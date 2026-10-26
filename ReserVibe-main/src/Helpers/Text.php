<?php
// src/Helpers/Text.php

namespace App\Helpers;

/** Unicode-aware text helpers with safe fallbacks when mbstring is unavailable. */
final class Text
{
    public static function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $count = preg_match_all('/./us', $value, $matches);
        return $count === false ? strlen($value) : $count;
    }

    public static function substring(string $value, int $start, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, $start, $length, 'UTF-8');
        }

        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) {
            return substr($value, $start, $length);
        }

        return implode('', array_slice($characters, $start, $length));
    }
}
