<?php
// src/Helpers/Config.php

namespace App\Helpers;

/**
 * Reads values from src/Config/config.php using dot notation.
 *
 * Config::get('deposit_rate');
 * Config::get('uploads.max_bytes', 0);
 */
class Config
{
    private static ?array $config = null;

    public static function all(): array
    {
        if (self::$config === null) {
            self::$config = require __DIR__ . '/../Config/config.php';
        }

        return self::$config;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::all();

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
