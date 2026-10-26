<?php
// src/Services/ReservationOperationsService.php

namespace App\Services;

use App\Helpers\Config;
use App\Helpers\Validator;
use App\Repositories\AccommodationRepository;
use App\Repositories\DatabaseLock;
use App\Repositories\HoldRepository;
use App\Repositories\PaymentProofRepository;
use App\Repositories\ReservationRepository;
use InvalidArgumentException;

/** Modules 7 and 9: reservation changes, walk-ins and guest lifecycle. */
class ReservationOperationsService
{
    private ReservationRepository $reservations;
    private HoldRepository $holds;
    private AccommodationRepository $accommodations;
    private AvailabilityService $availability;
    private CostCalculator $calculator;

    public function __construct()
    {
        $this->reservations = new ReservationRepository();
        $this->holds = new HoldRepository();
        $this->accommodations = new AccommodationRepository();
        $this->availability = new AvailabilityService();
        $this->calculator = new CostCalculator();
    }

    public function cancel(string $reference, ?string $email, string $actor, bool $staff = false): array
    {
        return DatabaseLock::withLock(function () use ($reference, $email, $actor, $staff): array {
            $items = $this->reservations->all();
            $index = $this->findReference($items, $reference);
            if ($index === null || (!$staff && strcasecmp((string) $items[$index]['guest_email'], (string) $email) !== 0)) {
                throw new InvalidArgumentException('Reservation reference or email did not match.');
            }
            if (!in_array($items[$index]['status'] ?? '', ReservationRepository::BLOCKING_STATUSES, true)) {
                throw new InvalidArgumentException('This reservation is not active and cannot be cancelled.');
            }
            if (($items[$index]['guest_status'] ?? '') !== 'reserved') {
                throw new InvalidArgumentException('A stay that has already started cannot be cancelled in this demo.');
            }
            $items[$index]['status'] = ReservationRepository::STATUS_CANCELLED;
            $items[$index]['cancellation_policy_result'] = BookingRules::cancellationResult((string) $items[$index]['check_in']);
            $items[$index]['cancelled_at'] = date('c');
            $items[$index]['cancelled_by'] = $actor;
            $items[$index]['updated_at'] = date('c');
            $this->reservations->writeAll($items);
            return $items[$index];
        });
    }

    public function reschedule(string $reference, ?string $email, string $checkIn, string $checkOut, string $actor, bool $staff = false): array
    {
        return DatabaseLock::withLock(function () use ($reference, $email, $checkIn, $checkOut, $actor, $staff): array {
            $items = $this->reservations->all();
            $index = $this->findReference($items, $reference);
            if ($index === null || (!$staff && strcasecmp((string) $items[$index]['guest_email'], (string) $email) !== 0)) {
                throw new InvalidArgumentException('Reservation reference or email did not match.');
            }
            $reservation = $items[$index];
            if (!in_array($reservation['status'] ?? '', ReservationRepository::BLOCKING_STATUSES, true)
                || ($reservation['guest_status'] ?? '') !== 'reserved') {
                throw new InvalidArgumentException('Only active stays that have not started can be rescheduled.');
            }
            foreach ((new PaymentProofRepository())->read() as $proof) {
                if ((int) ($proof['reservation_id'] ?? 0) === (int) $reservation['id'] && ($proof['status'] ?? '') === 'pending') {
                    throw new InvalidArgumentException('Wait for staff to review the submitted receipt before changing the stay dates.');
                }
            }
            $dateErrors = BookingRules::rescheduleErrors($checkIn, $checkOut);
            if ($dateErrors !== []) throw new InvalidArgumentException((string) reset($dateErrors));
            $others = $items;
            array_splice($others, $index, 1);
            $conflict = $this->availability->findConflict(
                $others, $this->holds->all(), (int) $reservation['accommodation_id'], $checkIn, $checkOut
            );
            if ($conflict !== null) throw new InvalidArgumentException('Those dates are unavailable; choose another range.');
            $accommodation = $this->accommodations->find((int) $reservation['accommodation_id']);
            if ($accommodation === null) throw new InvalidArgumentException('The reserved package is no longer available.');
            $nights = $this->calculator->nightsBetween($checkIn, $checkOut);
            if ($nights === null) throw new InvalidArgumentException('Those dates are invalid.');
            $quote = $this->calculator->quote($accommodation, (array) ($reservation['addons'] ?? []), $nights);
            if ($quote['total'] < ($reservation['total'] ?? 0)) {
                $quote['total'] = $reservation['total'];
                $quote['balance_due'] = round(max(0, (float) $quote['total'] - (float) ($reservation['amount_paid'] ?? 0)), 2);
            }
            $reservation['check_in'] = $checkIn;
            $reservation['check_out'] = $checkOut;
            $reservation['nights'] = $nights;
            foreach (['subtotal', 'addons_total', 'total', 'deposit_rate', 'deposit_due'] as $key) $reservation[$key] = $quote[$key];
            $reservation['addons_detail'] = $quote['addons'];
            $reservation['balance_due'] = round(max(0, (float) $quote['total'] - (float) ($reservation['amount_paid'] ?? 0)), 2);
            $reservation['status'] = (float) ($reservation['amount_paid'] ?? 0) + 0.001 >= (float) $quote['deposit_due']
                ? ReservationRepository::STATUS_CONFIRMED : ReservationRepository::STATUS_PENDING_PAYMENT;
            $rules = Config::get('booking_rules', []);
            $reservation['check_in_time'] = (string) ($rules['check_in_time'] ?? '14:00');
            $reservation['check_out_time'] = (string) ($rules['check_out_time'] ?? '11:00');
            $reservation['rescheduled_at'] = date('c');
            $reservation['rescheduled_by'] = $actor;
            $reservation['updated_at'] = date('c');
            $items[$index] = $reservation;
            $this->reservations->writeAll($items);
            return ['reservation' => $reservation, 'quote' => $quote];
        });
    }

    public function createWalkIn(array $input, string $actor): array
    {
        $accommodationId = Validator::int($input['accommodation_id'] ?? null, 1);
        $guestName = Validator::text($input['guest_name'] ?? null, 2, 120);
        $emailInput = $input['guest_email'] ?? '';
        $email = is_string($emailInput) ? trim($emailInput) : '';
        $phone = Validator::phone($input['guest_phone'] ?? null);
        $checkIn = Validator::date($input['check_in'] ?? date('Y-m-d'));
        $checkOut = Validator::date($input['check_out'] ?? null);
        if ($accommodationId === null || $guestName === null || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
            || $phone === null || $checkIn === null || $checkOut === null || $checkOut <= $checkIn) {
            throw new InvalidArgumentException('Enter a package, guest name, valid optional email, mobile number, and valid stay dates.');
        }
        $dateErrors = BookingRules::dateErrors($checkIn ?? '', $checkOut ?? '');
        if ($dateErrors !== []) throw new InvalidArgumentException((string) reset($dateErrors));
        $addons = Validator::list($input['addons'] ?? []);
        if ($unknown = $this->calculator->unknownAddonCodes($addons)) throw new InvalidArgumentException('Unknown add-ons: ' . implode(', ', $unknown));

        return DatabaseLock::withLock(function () use ($accommodationId, $guestName, $email, $phone, $checkIn, $checkOut, $addons, $actor, $input): array {
            $accommodation = $this->accommodations->findActive($accommodationId);
            if ($accommodation === null) throw new InvalidArgumentException('Choose an existing package.');
            $guests = Validator::int($input['guests'] ?? null, 1);
            if ($guests === null || $guests > $accommodation['capacity']) {
                throw new InvalidArgumentException('Maximum capacity for this accommodation is ' . $accommodation['capacity'] . '.');
            }
            $reservations = $this->reservations->all();
            $conflict = $this->availability->findConflict($reservations, $this->holds->all(), $accommodationId, $checkIn, $checkOut);
            if ($conflict !== null) throw new InvalidArgumentException('Those dates are already held or reserved.');
            $nights = $this->calculator->nightsBetween($checkIn, $checkOut);
            if ($nights === null) throw new InvalidArgumentException('Stay dates are invalid.');
            $quote = $this->calculator->quote($accommodation, $addons, $nights);
            $id = $this->reservations->nextId($reservations);
            $reservation = [
                'id' => $id, 'reference' => 'RSV-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT),
                'accommodation_id' => $accommodationId, 'accommodation_name' => (string) $accommodation['name'],
                'hold_id' => null, 'guest_name' => $guestName, 'guest_email' => $email, 'guest_phone' => $phone,
                'check_in' => $checkIn, 'check_out' => $checkOut, 'guests' => $guests, 'check_in_time' => (string) Config::get('booking_rules.check_in_time', '14:00'), 'check_out_time' => (string) Config::get('booking_rules.check_out_time', '11:00'), 'nights' => $nights, 'addons' => $addons,
                'addons_detail' => $quote['addons'], 'subtotal' => $quote['subtotal'], 'addons_total' => $quote['addons_total'],
                'total' => $quote['total'], 'deposit_rate' => $quote['deposit_rate'], 'deposit_due' => $quote['deposit_due'],
                'balance_due' => $quote['total'], 'amount_paid' => 0.0, 'status' => ReservationRepository::STATUS_CONFIRMED,
                'guest_status' => $checkIn <= date('Y-m-d') ? 'arrived' : 'reserved',
                'payment_method' => 'cash', 'payment_method_label' => 'Cash at the Resort', 'payment_requires_proof' => false,
                'settles_on_arrival' => true, 'walk_in' => true, 'created_by' => $actor,
                'notes' => 'Walk-in reservation recorded by staff.', 'created_at' => date('c'), 'updated_at' => date('c'),
            ];
            $reservations[] = $reservation;
            $this->reservations->writeAll($reservations);
            return ['reservation' => $reservation, 'quote' => $quote];
        });
    }

    public function updateGuestStatus(int $id, string $status, string $actor): array
    {
        if (!in_array($status, ['arrived', 'left'], true)) throw new InvalidArgumentException('Guest status must be arrived or left.');
        return DatabaseLock::withLock(function () use ($id, $status, $actor): array {
            $items = $this->reservations->all();
            foreach ($items as $index => $item) {
                if ((int) ($item['id'] ?? 0) !== $id) continue;
                if (!in_array($item['status'] ?? '', ReservationRepository::BLOCKING_STATUSES, true)) throw new InvalidArgumentException('Only an active reservation can change guest status.');
                if ($status === 'left' && ($item['guest_status'] ?? '') !== 'arrived') throw new InvalidArgumentException('Mark the guest arrived before marking them departed.');
                if ($status === 'arrived' && ($item['guest_status'] ?? '') === 'left') throw new InvalidArgumentException('A departed guest cannot be marked arrived again.');
                $items[$index]['guest_status'] = $status;
                $items[$index]['guest_status_updated_at'] = date('c');
                $items[$index]['guest_status_updated_by'] = $actor;
                $items[$index]['updated_at'] = date('c');
                $this->reservations->writeAll($items);
                return $items[$index];
            }
            throw new InvalidArgumentException('Reservation not found.');
        });
    }

    private function findReference(array $items, string $reference): ?int
    {
        foreach ($items as $index => $item) if (strcasecmp((string) ($item['reference'] ?? ''), trim($reference)) === 0) return $index;
        return null;
    }
}
