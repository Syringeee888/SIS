<?php
// src/Config/Database.php

declare(strict_types=1);

namespace App\Config;

use App\Helpers\Config;
use PDO;
use PDOException;
use RuntimeException;

/** Creates and reuses the application's MySQL PDO connection. */
final class Database
{
    private static ?PDO $connection = null;

    private function __construct()
    {
    }

    public static function getInstance(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = (string) Config::get('db.host', 'localhost');
        $database = (string) Config::get('db.name', 'reservibe_db');
        $username = (string) Config::get('db.user', 'root');
        $password = (string) Config::get('db.pass', '');

        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $database);

        try {
            self::$connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $exception) {
            // Keep credentials and server details out of user-facing errors.
            throw new RuntimeException(
                'Could not connect to MySQL. Check DB_HOST, DB_NAME, DB_USER, and DB_PASS in .env, and confirm the MySQL server is running.',
                0,
                $exception
            );
        }

        return self::$connection;
    }
}
