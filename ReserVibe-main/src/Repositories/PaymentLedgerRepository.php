<?php
// src/Repositories/PaymentLedgerRepository.php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/** MySQL repository for append-only payment ledger rows. */
class PaymentLedgerRepository
{
    public function read(): array
    {
        $rows = Database::getInstance()->query(
            'SELECT l.*, r.booking_ref, u.username AS actor_name FROM payment_ledger l
             JOIN reservations r ON r.id = l.reservation_id LEFT JOIN users u ON u.id = l.recorded_by ORDER BY l.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static function (array $row): array {
            $extra = json_decode((string) ($row['app_metadata'] ?? ''), true);
            if (!is_array($extra)) $extra = [];
            $methodCode = (string) ($extra['method_code'] ?? $row['payment_method']);
            unset($extra['method_code']);
            return array_merge($extra, [
                'id' => (int) $row['id'], 'reservation_id' => (int) $row['reservation_id'],
                'reservation_reference' => (string) $row['booking_ref'], 'amount' => (float) $row['amount'],
                'method' => $methodCode, 'proof_id' => $row['payment_proof_id'] === null ? null : (int) $row['payment_proof_id'],
                'recorded_by' => (string) ($row['actor_name'] ?? ''), 'created_at' => self::isoDateTime((string) $row['recorded_at']),
            ]);
        }, $rows);
    }

    public function replace(array $records): void
    {
        $pdo = Database::getInstance();
        $save = $pdo->prepare(
            'INSERT INTO payment_ledger (id, reservation_id, payment_proof_id, amount, payment_method, payment_type, reference_note, recorded_by, recorded_at, app_metadata)
             VALUES (NULLIF(?, 0), ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE reservation_id = VALUES(reservation_id), payment_proof_id = VALUES(payment_proof_id), amount = VALUES(amount),
                payment_method = VALUES(payment_method), payment_type = VALUES(payment_type), reference_note = VALUES(reference_note),
                recorded_by = VALUES(recorded_by), app_metadata = VALUES(app_metadata)'
        );
        foreach ($records as $record) {
            $id = (int) ($record['id'] ?? 0);
            if ($id < 1) continue;
            $method = (string) ($record['method'] ?? 'cash');
            $extra = $record;
            foreach (['id','reservation_id','reservation_reference','amount','method','proof_id','recorded_by','created_at'] as $key) unset($extra[$key]);
            // The DB enum has no 'qr_code' value, so store it as 'gcash' in the column and keep the real code in app_metadata.
            $extra['method_code'] = $method;
            if (!in_array($method, ['gcash','maya','cash','bank_transfer','paypal','card'], true)) $method = 'gcash';
            $save->execute([
                $id, (int) $record['reservation_id'], $record['proof_id'] ?? null, (float) $record['amount'], $method,
                ($record['source'] ?? '') === 'proof' ? 'deposit' : 'balance_settlement',
                (string) ($record['note'] ?? $record['source'] ?? ''), self::userId($record['recorded_by'] ?? null),
                self::sqlDateTime((string) ($record['created_at'] ?? 'now')),
                json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
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

    private static function sqlDateTime(string $v): string { $t = strtotime($v); return date('Y-m-d H:i:s', $t === false ? time() : $t); }
    private static function isoDateTime(string $v): string { $t = strtotime($v); return date(DATE_ATOM, $t === false ? time() : $t); }
}