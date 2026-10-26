<?php
// src/Services/AvailabilityService.php

namespace App\Services;

use App\Repositories\HoldRepository;
use App\Repositories\ReservationRepository;

/**
 * Modules 2 and 4 — availability and conflict detection.
 *
 * Deliberately pure: every method takes the collections it needs as arrays and
 * returns a verdict. It never reads or writes storage itself, which is what
 * lets HoldManager run the check and the write inside one lock.
 */
class AvailabilityService
{
    /**
     * Standard half-open date overlap: two stays collide when each starts
     * before the other ends. Checking out on the day someone else checks in is
     * therefore allowed.
     */
    public function overlaps(string $aIn, string $aOut, string $bIn, string $bOut): bool
    {
        return $aIn < $bOut && $aOut > $bIn;
    }

    /**
     * Reservations that currently occupy the slot.
     *
     * @param  array<int, array<string, mixed>> $reservations
     * @return array<int, array<string, mixed>>
     */
    public function blockingReservations(
        array $reservations,
        int $accommodationId,
        string $checkIn,
        string $checkOut,
        ?int $ignoreReservationId = null
    ): array {
        return array_values(array_filter(
            $reservations,
            function (array $reservation) use ($accommodationId, $checkIn, $checkOut, $ignoreReservationId): bool {
                if ((int) ($reservation['accommodation_id'] ?? 0) !== $accommodationId) {
                    return false;
                }

                if (!in_array($reservation['status'] ?? '', ReservationRepository::BLOCKING_STATUSES, true)) {
                    return false;
                }

                if ($ignoreReservationId !== null && (int) ($reservation['id'] ?? 0) === $ignoreReservationId) {
                    return false;
                }

                return $this->overlaps(
                    $checkIn,
                    $checkOut,
                    (string) ($reservation['check_in'] ?? ''),
                    (string) ($reservation['check_out'] ?? '')
                );
            }
        ));
    }

    /**
     * Holds that currently occupy the slot. Expiry is decided by status plus
     * the clock at call time, so a stale hold can never block a new booking
     * even if nothing has swept it yet.
     *
     * @param  array<int, array<string, mixed>> $holds
     * @return array<int, array<string, mixed>>
     */
    public function activeHolds(
        array $holds,
        int $accommodationId,
        string $checkIn,
        string $checkOut,
        ?int $ignoreHoldId = null,
        ?int $now = null
    ): array {
        $now = $now ?? time();

        return array_values(array_filter(
            $holds,
            function (array $hold) use ($accommodationId, $checkIn, $checkOut, $ignoreHoldId, $now): bool {
                if (($hold['status'] ?? null) !== HoldRepository::STATUS_ACTIVE) {
                    return false;
                }

                if ((int) ($hold['accommodation_id'] ?? 0) !== $accommodationId) {
                    return false;
                }

                if ($ignoreHoldId !== null && (int) ($hold['id'] ?? 0) === $ignoreHoldId) {
                    return false;
                }

                if ($this->expiresAt($hold) <= $now) {
                    return false;
                }

                return $this->overlaps(
                    $checkIn,
                    $checkOut,
                    (string) ($hold['check_in'] ?? ''),
                    (string) ($hold['check_out'] ?? '')
                );
            }
        ));
    }

    /**
     * The first thing standing in the way of this booking, or null when the
     * slot is free.
     *
     * @return array<string, mixed>|null
     */
    public function findConflict(
        array $reservations,
        array $holds,
        int $accommodationId,
        string $checkIn,
        string $checkOut,
        ?int $ignoreHoldId = null,
        ?int $now = null
    ): ?array {
        $reserved = $this->blockingReservations($reservations, $accommodationId, $checkIn, $checkOut);

        if ($reserved !== []) {
            return [
                'type' => 'reservation',
                'reference' => 'RSV-' . str_pad((string) ($reserved[0]['id'] ?? 0), 4, '0', STR_PAD_LEFT),
                'check_in' => $reserved[0]['check_in'] ?? null,
                'check_out' => $reserved[0]['check_out'] ?? null,
            ];
        }

        $held = $this->activeHolds($holds, $accommodationId, $checkIn, $checkOut, $ignoreHoldId, $now);

        if ($held !== []) {
            return [
                'type' => 'hold',
                'reference' => 'HOLD-' . str_pad((string) ($held[0]['id'] ?? 0), 4, '0', STR_PAD_LEFT),
                'expires_at' => $held[0]['expires_at'] ?? null,
                'check_in' => $held[0]['check_in'] ?? null,
                'check_out' => $held[0]['check_out'] ?? null,
            ];
        }

        return null;
    }

    public function isAvailable(
        array $reservations,
        array $holds,
        int $accommodationId,
        string $checkIn,
        string $checkOut,
        ?int $ignoreHoldId = null,
        ?int $now = null
    ): bool {
        return $this->findConflict($reservations, $holds, $accommodationId, $checkIn, $checkOut, $ignoreHoldId, $now) === null;
    }

    /**
     * Module 2 — FullCalendar feed.
     *
     * FullCalendar treats an event's end date as exclusive, which is exactly
     * how the half-open overlap rule above already treats check-out, so the
     * dates can be passed through unchanged.
     *
     * @return array<int, array<string, mixed>>
     */
    public function calendarEvents(
        array $accommodations,
        array $reservations,
        array $holds,
        ?string $from = null,
        ?string $to = null,
        ?int $accommodationId = null,
        ?int $now = null
    ): array {
        $now = $now ?? time();
        $names = [];

        foreach ($accommodations as $accommodation) {
            $names[(int) ($accommodation['id'] ?? 0)] = (string) ($accommodation['name'] ?? 'Accommodation');
        }

        $events = [];

        foreach ($reservations as $reservation) {
            if (!in_array($reservation['status'] ?? '', ReservationRepository::BLOCKING_STATUSES, true)) {
                continue;
            }

            $id = (int) ($reservation['accommodation_id'] ?? 0);

            if ($accommodationId !== null && $id !== $accommodationId) {
                continue;
            }

            if (!$this->withinWindow((string) ($reservation['check_in'] ?? ''), (string) ($reservation['check_out'] ?? ''), $from, $to)) {
                continue;
            }

            $events[] = [
                'id' => 'rsv-' . ($reservation['id'] ?? 0),
                'title' => ($names[$id] ?? 'Accommodation') . ' — reserved',
                'start' => $reservation['check_in'] ?? null,
                'end' => $reservation['check_out'] ?? null,
                'color' => '#dc2626',
                'extendedProps' => [
                    'kind' => 'reservation',
                    'status' => $reservation['status'] ?? null,
                    'reference' => 'RSV-' . str_pad((string) ($reservation['id'] ?? 0), 4, '0', STR_PAD_LEFT),
                ],
            ];
        }

        foreach ($holds as $hold) {
            if (($hold['status'] ?? null) !== HoldRepository::STATUS_ACTIVE || $this->expiresAt($hold) <= $now) {
                continue;
            }

            $id = (int) ($hold['accommodation_id'] ?? 0);

            if ($accommodationId !== null && $id !== $accommodationId) {
                continue;
            }

            if (!$this->withinWindow((string) ($hold['check_in'] ?? ''), (string) ($hold['check_out'] ?? ''), $from, $to)) {
                continue;
            }

            $events[] = [
                'id' => 'hold-' . ($hold['id'] ?? 0),
                'title' => ($names[$id] ?? 'Accommodation') . ' — on hold',
                'start' => $hold['check_in'] ?? null,
                'end' => $hold['check_out'] ?? null,
                'color' => '#f59e0b',
                'extendedProps' => [
                    'kind' => 'hold',
                    'expires_at' => $hold['expires_at'] ?? null,
                    'reference' => 'HOLD-' . str_pad((string) ($hold['id'] ?? 0), 4, '0', STR_PAD_LEFT),
                ],
            ];
        }

        return $events;
    }

    /** Exposes the same overlap rule the calendar uses. */
    public function withinWindow(string $checkIn, string $checkOut, ?string $from, ?string $to): bool
    {
        if ($from !== null && $checkOut <= $from) {
            return false;
        }

        if ($to !== null && $checkIn >= $to) {
            return false;
        }

        return true;
    }

    /** Parses a hold's expires_at into a unix timestamp. */
    public function expiresAt(array $hold): int
    {
        $stamp = strtotime((string) ($hold['expires_at'] ?? ''));

        return $stamp === false ? 0 : $stamp;
    }
}
