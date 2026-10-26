<?php
// src/Repositories/LoginAttemptRepository.php

namespace App\Repositories;

use App\Config\Database;

/** Stores failed-login throttling state in MySQL. */
class LoginAttemptRepository
{
    public function find(string $key): array
    {
        $statement = Database::getInstance()->prepare('SELECT failures, window_started_at, locked_until FROM login_attempts WHERE key_hash = ?');
        $statement->execute([$key]);
        $row = $statement->fetch();
        return is_array($row) ? $row : [];
    }

    public function recordFailure(string $key, int $maxFailures, int $windowSeconds, int $lockSeconds): void
    {
        $pdo = Database::getInstance();
        $pdo->beginTransaction();
        try {
            $now = time();
            $seed = $pdo->prepare('INSERT IGNORE INTO login_attempts (key_hash, failures, window_started_at, locked_until) VALUES (?, 0, ?, 0)');
            $seed->execute([$key, $now]);
            $select = $pdo->prepare('SELECT failures, window_started_at FROM login_attempts WHERE key_hash = ? FOR UPDATE');
            $select->execute([$key]);
            $row = $select->fetch();
            $failures = (int) ($row['failures'] ?? 0);
            $windowStarted = (int) ($row['window_started_at'] ?? $now);
            if ($windowStarted < $now - $windowSeconds) {
                $failures = 0;
                $windowStarted = $now;
            }
            $failures++;
            $lockedUntil = 0;
            if ($failures >= $maxFailures) {
                $failures = 0;
                $windowStarted = $now;
                $lockedUntil = $now + $lockSeconds;
            }
            $update = $pdo->prepare('UPDATE login_attempts SET failures = ?, window_started_at = ?, locked_until = ? WHERE key_hash = ?');
            $update->execute([$failures, $windowStarted, $lockedUntil, $key]);
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }

    public function clear(string $key): void
    {
        $statement = Database::getInstance()->prepare('DELETE FROM login_attempts WHERE key_hash = ?');
        $statement->execute([$key]);
    }
}
