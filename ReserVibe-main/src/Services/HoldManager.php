<?php
// src/Services/HoldManager.php

namespace App\Services;

use App\Helpers\Config;
use App\Repositories\DatabaseLock;
use App\Repositories\HoldRepository;
use App\Repositories\ReservationRepository;

/**
 * Module 4 — temporary holds and conflict prevention.
 *
 * This is the heart of the project. Placing a hold happens inside
 * DatabaseLock::withLock(), and the conflict check reads the stores *inside*
 * that same critical section, so two requests racing for the same slot cannot
 * both succeed — the second one sees the first one's hold already written.
 *
 * Methods that take an array of holds are pure so that other services can
 * compose several changes into one lock; for example ReservationService sweeps,
 * re-checks and confirms without ever releasing the lock in between.
 */
class HoldManager
{
    private HoldRepository $holds;
    private ReservationRepository $reservations;
    private AvailabilityService $availability;

    public function __construct(
        ?HoldRepository $holds = null,
        ?ReservationRepository $reservations = null,
        ?AvailabilityService $availability = null
    ) {
        $this->holds = $holds ?? new HoldRepository();
        $this->reservations = $reservations ?? new ReservationRepository();
        $this->availability = $availability ?? new AvailabilityService();
    }

    /**
     * Claim a slot for a limited time, or explain what is in the way.
     *
     * @return array{granted: bool, hold?: array, remaining_seconds?: int, conflict?: array}
     */
    public function place(int $accommodationId, string $checkIn, string $checkOut, array $meta = []): array
    {
        return $this->withBookingLock(function () use ($accommodationId, $checkIn, $checkOut, $meta): array {
            $now = time();

            $reservations = $this->reservations->all();
            $holds = $this->sweepExpired($this->holds->all(), $now);

            // Inside the lock: nothing can slip in between this check and the write.
            $conflict = $this->availability->findConflict(
                $reservations,
                $holds,
                $accommodationId,
                $checkIn,
                $checkOut,
                null,
                $now
            );

            if ($conflict !== null) {
                // Persist the sweep even though the hold is refused.
                $this->holds->writeAll($holds);

                return ['granted' => false, 'conflict' => $conflict];
            }

            $expiresAt = $this->expiryFrom($now);
            $hold = [
                'id' => $this->holds->nextId($holds),
                'accommodation_id' => $accommodationId,
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'guest_name' => $meta['guest_name'] ?? null,
                'guest_contact' => $meta['guest_contact'] ?? null,
                'guest_count' => isset($meta['guest_count']) ? (int) $meta['guest_count'] : null,
                'status' => HoldRepository::STATUS_ACTIVE,
                'reservation_id' => null,
                'created_at' => date('c', $now),
                'expires_at' => date('c', $expiresAt),
            ];

            $holds[] = $hold;
            $this->holds->writeAll($holds);

            return [
                'granted' => true,
                'hold' => $hold,
                'remaining_seconds' => $expiresAt - $now,
            ];
        });
    }

    /**
     * Current state of a hold. Expiry is checked lazily on read — this is the
     * only place a stale hold is written back as expired, and it means the
     * project needs no cron job on shared hosting.
     *
     * @return array<string, mixed>|null
     */
    public function status(int $holdId): ?array
    {
        return $this->withBookingLock(function () use ($holdId): ?array {
            $now = time();
            $holds = $this->holds->all();
            $swept = $this->sweepExpired($holds, $now);

            if ($swept !== $holds) {
                $this->holds->writeAll($swept);
            }

            foreach ($swept as $hold) {
                if ((int) ($hold['id'] ?? 0) !== $holdId) {
                    continue;
                }

                return $this->present($hold, $now);
            }

            return null;
        });
    }

    /**
     * Guest gave up: hand the slot back immediately.
     *
     * @return array{released: bool, reason?: string, hold?: array}
     */
    public function release(int $holdId): array
    {
        return $this->withBookingLock(function () use ($holdId): array {
            $now = time();
            $holds = $this->sweepExpired($this->holds->all(), $now);

            foreach ($holds as $index => $hold) {
                if ((int) ($hold['id'] ?? 0) !== $holdId) {
                    continue;
                }

                if (($hold['status'] ?? null) !== HoldRepository::STATUS_ACTIVE) {
                    $this->holds->writeAll($holds);

                    return [
                        'released' => false,
                        'reason' => 'Hold is no longer active (status: ' . ($hold['status'] ?? 'unknown') . ').',
                        'hold' => $this->present($holds[$index], $now),
                    ];
                }

                $holds[$index]['status'] = HoldRepository::STATUS_RELEASED;
                $holds[$index]['released_at'] = date('c', $now);
                $this->holds->writeAll($holds);

                return ['released' => true, 'hold' => $this->present($holds[$index], $now)];
            }

            return ['released' => false, 'reason' => 'Hold not found.'];
        });
    }

    /**
     * Mark every hold whose time is up as expired. Pure: takes and returns the
     * collection so callers can batch it into a single write.
     *
     * @param  array<int, array<string, mixed>> $holds
     * @return array<int, array<string, mixed>>
     */
    public function sweepExpired(array $holds, ?int $now = null): array
    {
        $now = $now ?? time();

        foreach ($holds as $index => $hold) {
            $isActive = ($hold['status'] ?? null) === HoldRepository::STATUS_ACTIVE;

            if ($isActive && $this->availability->expiresAt($hold) <= $now) {
                $holds[$index]['status'] = HoldRepository::STATUS_EXPIRED;
                $holds[$index]['expired_at'] = date('c', $now);
            }
        }

        return $holds;
    }

    /**
     * Attach the confirmed reservation to its hold. Pure, like sweepExpired().
     *
     * @param  array<int, array<string, mixed>> $holds
     * @return array<int, array<string, mixed>>
     */
    public function markConfirmed(array $holds, int $holdId, int $reservationId, ?int $now = null): array
    {
        $now = $now ?? time();

        foreach ($holds as $index => $hold) {
            if ((int) ($hold['id'] ?? 0) === $holdId) {
                $holds[$index]['status'] = HoldRepository::STATUS_CONFIRMED;
                $holds[$index]['reservation_id'] = $reservationId;
                $holds[$index]['confirmed_at'] = date('c', $now);
            }
        }

        return $holds;
    }

    /** Whether a hold is usable right now. */
    public function isUsable(array $hold, ?int $now = null): bool
    {
        $now = $now ?? time();

        return ($hold['status'] ?? null) === HoldRepository::STATUS_ACTIVE
            && $this->availability->expiresAt($hold) > $now;
    }

    /** Seconds left before a hold lapses; never negative. */
    public function remainingSeconds(array $hold, ?int $now = null): int
    {
        return max(0, $this->availability->expiresAt($hold) - ($now ?? time()));
    }

    /** Configured hold lifetime, in minutes. */
    public function holdMinutes(): int
    {
        return (int) Config::get('hold_minutes', 90);
    }

    public function expiryFrom(int $now): int
    {
        return $now + ($this->holdMinutes() * 60);
    }

    /** Shape a hold for API output, adding clock-derived fields. */
    public function present(array $hold, ?int $now = null): array
    {
        $now = $now ?? time();
        $hold['remaining_seconds'] = $this->remainingSeconds($hold, $now);
        $hold['is_expired'] = ($hold['status'] ?? null) === HoldRepository::STATUS_EXPIRED
            || $this->availability->expiresAt($hold) <= $now;
        $hold['reference'] = 'HOLD-' . str_pad((string) ($hold['id'] ?? 0), 4, '0', STR_PAD_LEFT);

        return $hold;
    }

    private function withBookingLock(callable $callback): mixed
    {
        return DatabaseLock::withLock($callback);
    }
}

