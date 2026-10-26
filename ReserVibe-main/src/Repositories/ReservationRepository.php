<?php
// src/Repositories/ReservationRepository.php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/** MySQL repository for reservations and their guest records. */
class ReservationRepository
{
    public const BLOCKING_STATUSES = ['pending_payment', 'pending_verification', 'confirmed'];
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_PENDING_VERIFICATION = 'pending_verification';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PAYMENT_REJECTED = 'payment_rejected';
    public const STATUS_CANCELLED = 'cancelled';
    public const GUEST_RESERVED = 'reserved';

    public function all(): array
    {
        $rows = Database::getInstance()->query(
            'SELECT r.*, g.first_name, g.last_name, g.email AS guest_email, g.phone AS guest_phone, a.name AS accommodation_name
             FROM reservations r JOIN guests g ON g.id = r.guest_id
             LEFT JOIN accommodations a ON a.id = r.accommodation_id ORDER BY r.id'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $extra = self::decode($row['app_metadata'] ?? null);
            $status = (string) ($row['app_status'] ?: self::statusFromSql((string) $row['reservation_status']));
            $guestStatus = match ($row['guest_status']) {
                'arrived' => 'arrived',
                'checked_out' => 'left',
                'no_show' => 'no_show',
                default => self::GUEST_RESERVED,
            };
            return array_merge($extra, [
                'id' => (int) $row['id'],
                'reference' => (string) $row['booking_ref'],
                'accommodation_id' => (int) $row['accommodation_id'],
                'accommodation_name' => (string) ($row['accommodation_name'] ?? ''),
                'hold_id' => $row['hold_id'] === null ? null : (int) $row['hold_id'],
                'booking_type' => (string) $row['booking_type'],
                'guest_name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
                'guest_email' => (string) ($row['guest_email'] ?? ''),
                'guest_phone' => (string) ($row['guest_phone'] ?? ''),
                'guests' => (int) $row['guest_count'],
                'check_in' => (string) $row['check_in_date'],
                'check_out' => (string) $row['check_out_date'],
                'total' => (float) $row['total_cost'],
                'deposit_due' => (float) $row['deposit_required'],
                'amount_paid' => (float) $row['amount_paid'],
                'balance_due' => (float) $row['balance'],
                'status' => $status,
                'guest_status' => $guestStatus,
                'notes' => (string) ($row['notes'] ?? ''),
                'created_at' => self::isoDateTime((string) $row['created_at']),
                'updated_at' => $extra['updated_at'] ?? self::isoDateTime((string) $row['created_at']),
            ]);
        }, $rows);
    }

    /** Upsert the current collection into normalized reservations and guests tables. */
    public function writeAll(array $reservations): void
    {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $findGuest = $pdo->prepare('SELECT guest_id FROM reservations WHERE id = ?');
            $insertGuest = $pdo->prepare('INSERT INTO guests (first_name, last_name, email, phone) VALUES (?, ?, ?, ?)');
            $updateGuest = $pdo->prepare('UPDATE guests SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?');
            $save = $pdo->prepare(
                'INSERT INTO reservations (id, booking_ref, guest_id, accommodation_id, hold_id, booking_type, check_in_date, check_out_date, guest_count,
                    total_cost, deposit_required, amount_paid, balance, payment_status, reservation_status, guest_status, notes, created_at, app_status, app_metadata)
                 VALUES (NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE booking_ref = VALUES(booking_ref), guest_id = VALUES(guest_id), accommodation_id = VALUES(accommodation_id),
                    hold_id = VALUES(hold_id), booking_type = VALUES(booking_type), check_in_date = VALUES(check_in_date), check_out_date = VALUES(check_out_date),
                    guest_count = VALUES(guest_count), total_cost = VALUES(total_cost), deposit_required = VALUES(deposit_required), amount_paid = VALUES(amount_paid),
                    balance = VALUES(balance), payment_status = VALUES(payment_status), reservation_status = VALUES(reservation_status), guest_status = VALUES(guest_status),
                    notes = VALUES(notes), app_status = VALUES(app_status), app_metadata = VALUES(app_metadata)'
            );

            foreach ($reservations as $reservation) {
                $id = (int) ($reservation['id'] ?? 0);
                $name = trim((string) ($reservation['guest_name'] ?? 'Guest'));
                $parts = preg_split('/\s+/', $name, 2) ?: ['Guest'];
                $firstName = (string) ($parts[0] ?? 'Guest');
                $lastName = (string) ($parts[1] ?? '');
                $findGuest->execute([$id]);
                $guestId = $findGuest->fetchColumn();
                if ($guestId) {
                    $updateGuest->execute([$firstName, $lastName, (string) ($reservation['guest_email'] ?? ''), (string) ($reservation['guest_phone'] ?? ''), (int) $guestId]);
                } else {
                    $insertGuest->execute([$firstName, $lastName, (string) ($reservation['guest_email'] ?? ''), (string) ($reservation['guest_phone'] ?? '')]);
                    $guestId = (int) $pdo->lastInsertId();
                }

                $status = (string) ($reservation['status'] ?? self::STATUS_PENDING_PAYMENT);
                $sqlStatus = self::statusToSql($status);
                $paid = (float) ($reservation['amount_paid'] ?? 0);
                $total = (float) ($reservation['total'] ?? 0);
                $paymentStatus = $paid <= 0 ? 'unpaid' : ($paid + 0.001 >= $total ? 'paid' : 'partially_paid');
                $guestStatus = match ($reservation['guest_status'] ?? self::GUEST_RESERVED) {
                    'arrived' => 'arrived', 'left', 'checked_out' => 'checked_out', 'no_show' => 'no_show', default => 'pending_arrival',
                };
                $extra = $reservation;
                foreach (['id','reference','accommodation_id','hold_id','booking_type','guest_name','guest_email','guest_phone','guests','check_in','check_out','total','deposit_due','amount_paid','balance_due','status','guest_status','notes','created_at'] as $key) unset($extra[$key]);
                $created = self::sqlDateTime((string) ($reservation['created_at'] ?? 'now'));
                $save->execute([
                    $id, (string) ($reservation['reference'] ?? ('RSV-' . $id)), (int) $guestId,
                    (int) ($reservation['accommodation_id'] ?? 0), $reservation['hold_id'] ?? null,
                    ($reservation['booking_type'] ?? 'online') === 'walk_in' ? 'walk_in' : 'online',
                    (string) ($reservation['check_in'] ?? date('Y-m-d')), (string) ($reservation['check_out'] ?? date('Y-m-d', strtotime('+1 day'))),
                    max(1, (int) ($reservation['guests'] ?? 1)), $total, (float) ($reservation['deposit_due'] ?? 0), $paid,
                    max(0, (float) ($reservation['balance_due'] ?? ($total - $paid))), $paymentStatus, $sqlStatus, $guestStatus,
                    (string) ($reservation['notes'] ?? ''), $created, $status,
                    json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public function find(int $id): ?array
    {
        foreach ($this->all() as $reservation) if ((int) $reservation['id'] === $id) return $reservation;
        return null;
    }

    public function byStatus(?string $status = null): array
    {
        $all = $this->all();
        return $status === null ? $all : array_values(array_filter($all, static fn (array $item): bool => ($item['status'] ?? null) === $status));
    }

    public function nextId(array $reservations): int
    {
        $max = 0;
        foreach ($reservations as $reservation) $max = max($max, (int) ($reservation['id'] ?? 0));
        return $max + 1;
    }

    private static function decode(mixed $json): array
    {
        $value = json_decode((string) $json, true);
        return is_array($value) ? $value : [];
    }

    private static function statusToSql(string $status): string
    {
        return match ($status) {
            self::STATUS_CONFIRMED => 'confirmed', self::STATUS_CANCELLED => 'cancelled', 'rescheduled' => 'rescheduled', default => 'pending',
        };
    }

    private static function statusFromSql(string $status): string
    {
        return match ($status) {
            'confirmed' => self::STATUS_CONFIRMED, 'cancelled' => self::STATUS_CANCELLED, 'rescheduled' => 'rescheduled', default => self::STATUS_PENDING_PAYMENT,
        };
    }

    private static function sqlDateTime(string $value): string
    {
        $timestamp = strtotime($value);
        return date('Y-m-d H:i:s', $timestamp === false ? time() : $timestamp);
    }

    private static function isoDateTime(string $value): string
    {
        $timestamp = strtotime($value);
        return date(DATE_ATOM, $timestamp === false ? time() : $timestamp);
    }
}
