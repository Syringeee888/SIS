<?php
// src/Repositories/DatabaseLock.php

namespace App\Repositories;

use App\Config\Database;
use RuntimeException;

/** Serializes booking and payment mutations across requests through MySQL. */
final class DatabaseLock
{
    private const NAME = 'reservibe_booking_operations';

    public static function withLock(callable $callback, int $timeoutSeconds = 10): mixed
    {
        $pdo = Database::getInstance();
        $acquire = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $acquire->execute([self::NAME, $timeoutSeconds]);
        if ((int) $acquire->fetchColumn() !== 1) {
            throw new RuntimeException('The booking system is busy. Please retry in a moment.');
        }

        try {
            return $callback();
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([self::NAME]);
        }
    }
}
