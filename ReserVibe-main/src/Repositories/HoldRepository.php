<?php
// src/Repositories/HoldRepository.php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/** MySQL repository for temporary booking holds. */
class HoldRepository
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CONFIRMED = 'confirmed';

    public function all(): array
    {
        $rows = Database::getInstance()->query(
            'SELECT id, accommodation_id, check_in_date, check_out_date, guest_count, status, expires_at, created_at, app_metadata FROM temporary_holds ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $row): array {
            $extra = self::decode($row['app_metadata'] ?? null);
            return array_merge($extra, [
                'id' => (int) $row['id'],
                'accommodation_id' => (int) $row['accommodation_id'],
                'check_in' => (string) $row['check_in_date'],
                'check_out' => (string) $row['check_out_date'],
                'guest_count' => (int) $row['guest_count'],
                'status' => $row['status'] === 'converted' ? self::STATUS_CONFIRMED : (string) $row['status'],
                'expires_at' => self::isoDateTime((string) $row['expires_at']),
                'created_at' => self::isoDateTime((string) $row['created_at']),
            ]);
        }, $rows);
    }

    public function writeAll(array $holds): void
    {
        $pdo = Database::getInstance();
        $statement = $pdo->prepare(
            'INSERT INTO temporary_holds (id, hold_token, accommodation_id, check_in_date, check_out_date, guest_count, status, expires_at, created_at, app_metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE accommodation_id = VALUES(accommodation_id), check_in_date = VALUES(check_in_date),
                check_out_date = VALUES(check_out_date), guest_count = VALUES(guest_count), status = VALUES(status),
                expires_at = VALUES(expires_at), app_metadata = VALUES(app_metadata)'
        );
        foreach ($holds as $hold) {
            $id = (int) ($hold['id'] ?? 0);
            if ($id < 1) continue;
            $extra = $hold;
            foreach (['id', 'accommodation_id', 'check_in', 'check_out', 'guest_count', 'status', 'expires_at', 'created_at'] as $key) unset($extra[$key]);
            $status = ($hold['status'] ?? '') === self::STATUS_CONFIRMED ? 'converted' : (string) ($hold['status'] ?? self::STATUS_ACTIVE);
            $statement->execute([
                $id, bin2hex(random_bytes(32)), (int) $hold['accommodation_id'], (string) $hold['check_in'],
                (string) $hold['check_out'], max(1, (int) ($hold['guest_count'] ?? 1)), $status,
                self::sqlDateTime((string) ($hold['expires_at'] ?? '')), self::sqlDateTime((string) ($hold['created_at'] ?? 'now')),
                json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }
    }

    public function find(int $id): ?array
    {
        foreach ($this->all() as $hold) if ((int) $hold['id'] === $id) return $hold;
        return null;
    }

    public function nextId(array $holds): int
    {
        $max = 0;
        foreach ($holds as $hold) $max = max($max, (int) ($hold['id'] ?? 0));
        return $max + 1;
    }

    private static function decode(mixed $json): array
    {
        $value = json_decode((string) $json, true);
        return is_array($value) ? $value : [];
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
