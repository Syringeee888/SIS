<?php
// src/Helpers/Validator.php

namespace App\Helpers;

/**
 * Small input-validation helpers.
 *
 * Every method returns null when the value is invalid, so callers can use a
 * null-coalescing guard and answer the client with a clear 422 instead of
 * letting bad data reach a repository.
 */
class Validator
{
    /** Y-m-d, and a real calendar date (rejects 2026-02-30). */
    public static function date(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }

    public static function int(mixed $value, ?int $min = null, ?int $max = null): ?int
    {
        if (is_string($value) && $value !== '' && !preg_match('/^-?\d+$/', $value)) {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        if ($min !== null && $int < $min) {
            return null;
        }

        if ($max !== null && $int > $max) {
            return null;
        }

        return $int;
    }

    /** Trimmed, length-checked string. Control characters are stripped. */
    public static function text(mixed $value, int $min = 1, int $max = 255): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $clean = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
        $length = Text::length($clean);

        return ($length >= $min && $length <= $max) ? $clean : null;
    }

    public static function email(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $clean = trim($value);

        return filter_var($clean, FILTER_VALIDATE_EMAIL) ? $clean : null;
    }

    /** Philippine mobile format, loosely: 09xxxxxxxxx or +639xxxxxxxxx. */
    public static function phone(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $digits = preg_replace('/[^\d+]/', '', trim($value)) ?? '';

        return preg_match('/^(\+639|09)\d{9}$/', $digits) ? $digits : null;
    }

    public static function in(mixed $value, array $allowed): ?string
    {
        return (is_string($value) && in_array($value, $allowed, true)) ? $value : null;
    }

    /**
     * Normalises a repeated field (add-ons) into a unique list of strings.
     * Accepts an array or a comma-separated string, since both are common
     * forms for form posts and JSON bodies.
     */
    public static function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = array_map('trim', explode(',', $value));
        }

        if (!is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values(array_unique($out));
    }
}
