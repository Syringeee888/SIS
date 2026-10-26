<?php
// src/Services/ReservationService.php

namespace App\Services;

use App\Helpers\Config;
use App\Helpers\Validator;
use App\Repositories\AccommodationRepository;
use App\Repositories\DatabaseLock;
use App\Repositories\HoldRepository;
use App\Repositories\ReservationRepository;

/**
 * Modules 3 and 4 — turning a held slot into a reservation.
 *
 * A reservation can only be created against a live hold, and the whole
 * operation (validate hold, price it, insert, confirm the hold) runs under the
 * same MySQL lock as HoldManager::place(). That is what stops a slot from
 * being sold twice.
 */
class ReservationService
{
    private ReservationRepository $reservations;
    private HoldRepository $holds;
    private AccommodationRepository $accommodations;
    private HoldManager $holdManager;
    private AvailabilityService $availability;
    private CostCalculator $calculator;

    public function __construct(
        ?ReservationRepository $reservations = null,
        ?HoldRepository $holds = null,
        ?AccommodationRepository $accommodations = null,
        ?HoldManager $holdManager = null,
        ?AvailabilityService $availability = null,
        ?CostCalculator $calculator = null
    ) {
        $this->reservations = $reservations ?? new ReservationRepository();
        $this->holds = $holds ?? new HoldRepository();
        $this->accommodations = $accommodations ?? new AccommodationRepository();
        $this->holdManager = $holdManager ?? new HoldManager();
        $this->availability = $availability ?? new AvailabilityService();
        $this->calculator = $calculator ?? new CostCalculator();
    }

    /**
     * @param  array<string, mixed> $input Raw request input.
     * @return array{ok: bool, errors?: array, message?: string, reservation?: array, quote?: array}
     */
    public function create(array $input): array
    {
        $errors = [];

        $holdId = Validator::int($input['hold_id'] ?? null, 1);

        if ($holdId === null) {
            $errors['hold_id'] = 'A valid hold_id is required. Place a hold before confirming a booking.';
        }

        $guestName = Validator::text($input['guest_name'] ?? null, 2, 120);

        if ($guestName === null) {
            $errors['guest_name'] = 'Guest name is required (2-120 characters).';
        }

        $guestEmail = Validator::email($input['guest_email'] ?? null);

        if ($guestEmail === null) {
            $errors['guest_email'] = 'A valid email address is required.';
        }

        $guestPhone = Validator::phone($input['guest_phone'] ?? null);

        if ($guestPhone === null) {
            $errors['guest_phone'] = 'A valid mobile number is required (09xxxxxxxxx or +639xxxxxxxxx).';
        }

        $guestCount = Validator::int($input['guests'] ?? null, 1);
        if ($guestCount === null) $errors['guests'] = 'Enter a valid number of guests.';
        $termsAccepted = ($input['agree_terms'] ?? false) === true;
        if (!$termsAccepted) $errors['agree_terms'] = 'Accept the school-demo booking terms to continue.';

        $addonCodes = Validator::list($input['addons'] ?? []);

        $unknown = $this->calculator->unknownAddonCodes($addonCodes);

        if ($unknown !== []) {
            $errors['addons'] = 'Unknown add-on code(s): ' . implode(', ', $unknown) . '.';
        }

        $paymentMethodCode = Validator::in($input['payment_method'] ?? null, $this->paymentMethodCodes());

        if ($paymentMethodCode === null) {
            $errors['payment_method'] = 'Choose a payment method: '
                . implode(', ', $this->paymentMethodCodes()) . '.';
        }

        $paymentMethod = $paymentMethodCode === null ? null : $this->paymentMethod($paymentMethodCode);

        $notes = Validator::text($input['notes'] ?? '', 0, 500);

        if ($notes === null) {
            $errors['notes'] = 'Notes must be 500 characters or fewer.';
        }

        if ($errors !== []) {
            return ['ok' => false, 'message' => 'Validation failed.', 'errors' => $errors];
        }

        return DatabaseLock::withLock(
            function () use (
                $holdId,
                $guestName,
                $guestEmail,
                $guestPhone,
                $guestCount,
                $termsAccepted,
                $addonCodes,
                $paymentMethodCode,
                $paymentMethod,
                $notes
            ): array {
                $now = time();

                $holds = $this->holdManager->sweepExpired($this->holds->all(), $now);
                $hold = null;
                $holdIndex = null;

                foreach ($holds as $index => $candidate) {
                    if ((int) ($candidate['id'] ?? 0) === $holdId) {
                        $hold = $candidate;
                        $holdIndex = $index;
                        break;
                    }
                }

                if ($hold === null) {
                    $this->holds->writeAll($holds);

                    return ['ok' => false, 'message' => 'Hold not found. Place a new hold to continue.'];
                }

                if (!$this->holdManager->isUsable($hold, $now)) {
                    $this->holds->writeAll($holds);

                    return [
                        'ok' => false,
                        'message' => 'This hold is no longer active (status: ' . ($hold['status'] ?? 'unknown')
                            . '). Place a new hold to continue.',
                    ];
                }

                $accommodationId = (int) ($hold['accommodation_id'] ?? 0);
                $accommodation = $this->accommodations->find($accommodationId);

                if ($accommodation === null) {
                    return ['ok' => false, 'message' => 'The accommodation on this hold no longer exists.'];
                }

                if ($guestCount > (int) ($accommodation['capacity'] ?? 0)) {
                    return ['ok' => false, 'message' => 'Guest count exceeds this package capacity.', 'errors' => ['guests' => 'Maximum capacity is ' . (int) $accommodation['capacity'] . '.']];
                }
                if (isset($hold['guest_count']) && (int) $hold['guest_count'] !== $guestCount) {
                    return ['ok' => false, 'message' => 'Guest count changed after the slot was held. Start again to recheck capacity.', 'errors' => ['guests' => 'Guest count must match the held request.']];
                }

                $checkIn = (string) ($hold['check_in'] ?? '');
                $checkOut = (string) ($hold['check_out'] ?? '');
                $nights = $this->calculator->nightsBetween($checkIn, $checkOut) ?? 1;

                $reservations = $this->reservations->all();

                // Belt and braces: the hold should already be the only claim on
                // this slot, but re-check with the hold itself excluded.
                $conflict = $this->availability->findConflict(
                    $reservations,
                    $holds,
                    $accommodationId,
                    $checkIn,
                    $checkOut,
                    $holdId,
                    $now
                );

                if ($conflict !== null) {
                    $this->holds->writeAll($holds);

                    return [
                        'ok' => false,
                        'message' => 'This slot was taken before the booking was confirmed.',
                        'conflict' => $conflict,
                    ];
                }

                $quote = $this->calculator->quote($accommodation, $addonCodes, $nights);
                $reservationId = $this->reservations->nextId($reservations);

                $reservation = [
                    'id' => $reservationId,
                    'reference' => 'RSV-' . str_pad((string) $reservationId, 4, '0', STR_PAD_LEFT),
                    'accommodation_id' => $accommodationId,
                    'accommodation_name' => (string) ($accommodation['name'] ?? ''),
                    'hold_id' => $holdId,
                    'guest_name' => $guestName,
                    'guest_email' => $guestEmail,
                    'guest_phone' => $guestPhone,
                    'guests' => $guestCount,
                    'terms_accepted_at' => date('c', $now),
                    'terms_version' => 'school-demo-v1',
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'nights' => $nights,
                    'addons' => $addonCodes,
                    'addons_detail' => $quote['addons'],
                    'subtotal' => $quote['subtotal'],
                    'addons_total' => $quote['addons_total'],
                    'total' => $quote['total'],
                    'deposit_rate' => $quote['deposit_rate'],
                    'deposit_due' => $quote['deposit_due'],
                    'balance_due' => $quote['balance_due'],
                    'amount_paid' => 0.0,
                    'status' => ReservationRepository::STATUS_PENDING_PAYMENT,
                    'guest_status' => ReservationRepository::GUEST_RESERVED,
                    'payment_method' => $paymentMethodCode,
                    'payment_method_label' => (string) ($paymentMethod['label'] ?? ''),
                    'payment_requires_proof' => (bool) ($paymentMethod['requires_proof'] ?? false),
                    'settles_on_arrival' => (bool) ($paymentMethod['settles_on_arrival'] ?? false),
                    'notes' => $notes ?? '',
                    'created_at' => date('c', $now),
                    'updated_at' => date('c', $now),
                ];

                $reservations[] = $reservation;
                $holds = $this->holdManager->markConfirmed($holds, $holdId, $reservationId, $now);

                $this->reservations->writeAll($reservations);
                $this->holds->writeAll($holds);

                return [
                    'ok' => true,
                    'reservation' => $reservation,
                    'quote' => $quote,
                    'hold' => $this->holdManager->present($holds[$holdIndex], $now),
                ];
            }
        );
    }

    /**
     * Module 6/9 — staff-facing list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByStatus(?string $status = null): array
    {
        $reservations = $this->reservations->byStatus($status);

        usort(
            $reservations,
            static fn (array $a, array $b): int => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? '')
        );

        return $reservations;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->reservations->find($id);
    }

    /** @return array<int, string> Codes the API will accept for payment_method. */
    public function paymentMethodCodes(): array
    {
        return array_map(
            static fn (array $method): string => (string) $method['code'],
            Config::get('payment_methods', [])
        );
    }

    /** @return array<string, mixed>|null */
    public function paymentMethod(string $code): ?array
    {
        foreach (Config::get('payment_methods', []) as $method) {
            if (($method['code'] ?? null) === $code) {
                return $method;
            }
        }

        return null;
    }
}

