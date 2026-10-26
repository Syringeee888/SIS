<?php
// src/Repositories/PaymentProofRepository.php

namespace App\Repositories;

use App\Config\Database;
use App\Helpers\Config;
use PDO;

/** MySQL repository for payment proof uploads and staff review state. */
class PaymentProofRepository
{
    public function read(): array
    {
        $rows = Database::getInstance()->query(
            'SELECT p.*, r.booking_ref FROM payment_proofs p JOIN reservations r ON r.id = p.reservation_id ORDER BY p.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function (array $row): array {
            $extra = self::decode($row['app_metadata'] ?? null);
            return array_merge($extra, [
                'id' => (int) $row['id'], 'reservation_id' => (int) $row['reservation_id'],
                'reservation_reference' => (string) $row['booking_ref'], 'amount' => (float) $row['amount_claimed'],
                'filename' => basename((string) $row['file_path']), 'mime' => (string) $row['mime_type'],
                'original_name' => (string) ($row['original_filename'] ?? ''),
                'status' => (string) ($row['app_status'] ?: self::statusFromSql((string) $row['verification_status'])),
                'review_note' => (string) ($row['review_notes'] ?? ''),
                'created_at' => self::isoDateTime((string) $row['uploaded_at']),
                'reviewed_at' => $row['verified_at'] ? self::isoDateTime((string) $row['verified_at']) : null,
            ]);
        }, $rows);
    }

    public function replace(array $records): void
    {
        $pdo = Database::getInstance();
        $save = $pdo->prepare(
            'INSERT INTO payment_proofs (id, reservation_id, file_path, original_filename, mime_type, payment_channel, reference_code,
                amount_claimed, verification_status, review_notes, verified_by, uploaded_at, verified_at, app_status, app_metadata)
             VALUES (NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE reservation_id = VALUES(reservation_id), file_path = VALUES(file_path), original_filename = VALUES(original_filename),
                mime_type = VALUES(mime_type), payment_channel = VALUES(payment_channel), reference_code = VALUES(reference_code), amount_claimed = VALUES(amount_claimed),
                verification_status = VALUES(verification_status), review_notes = VALUES(review_notes), verified_by = VALUES(verified_by), verified_at = VALUES(verified_at),
                app_status = VALUES(app_status), app_metadata = VALUES(app_metadata)'
        );
        $reservation = new ReservationRepository();
        foreach ($records as $record) {
            $id = (int) ($record['id'] ?? 0);
            if ($id < 1) continue;
            $method = (string) (($reservation->find((int) ($record['reservation_id'] ?? 0))['payment_method'] ?? 'gcash'));
            // 'qr_code' (and anything unknown) is stored under the existing 'gcash' channel value.
            if (!in_array($method, ['gcash', 'maya', 'bank_transfer'], true)) $method = 'gcash';
            $status = (string) ($record['status'] ?? 'pending');
            $sqlStatus = self::statusToSql($status);
            $extra = $record;
            foreach (['id','reservation_id','reservation_reference','amount','filename','mime','original_name','status','review_note','created_at','reviewed_at','reviewed_by'] as $key) unset($extra[$key]);
            $save->execute([
                $id, (int) $record['reservation_id'], basename((string) ($record['filename'] ?? 'receipt')),
                (string) ($record['original_name'] ?? 'receipt'), (string) ($record['mime'] ?? 'image/jpeg'), $method,
                (string) ($record['reference_code'] ?? $record['reservation_reference'] ?? 'receipt'), (float) ($record['amount'] ?? 0),
                $sqlStatus, (string) ($record['review_note'] ?? ''), self::userId($record['reviewed_by'] ?? null),
                self::sqlDateTime((string) ($record['created_at'] ?? 'now')),
                empty($record['reviewed_at']) ? null : self::sqlDateTime((string) $record['reviewed_at']),
                $status, json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }
    }

    private static function userId(mixed $user): ?int
    {
        if ($user === null || $user === '') return null;
        if (is_numeric($user)) return (int) $user;
        $statement = Database::getInstance()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $statement->execute([(string) $user]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private static function decode(mixed $json): array { $v = json_decode((string) $json, true); return is_array($v) ? $v : []; }
    private static function statusToSql(string $s): string { return match ($s) { 'approved' => 'verified', 'rejected' => 'rejected', default => 'pending' }; }
    private static function statusFromSql(string $s): string { return match ($s) { 'verified' => 'approved', 'rejected' => 'rejected', default => 'pending' }; }
    private static function sqlDateTime(string $v): string { $t = strtotime($v); return date('Y-m-d H:i:s', $t === false ? time() : $t); }
    private static function isoDateTime(string $v): string { $t = strtotime($v); return date(DATE_ATOM, $t === false ? time() : $t); }
}