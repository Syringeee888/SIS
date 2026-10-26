<?php
// src/Services/BookingRules.php

namespace App\Services;

use App\Helpers\Config;
use DateTimeImmutable;

/** Shared date and cancellation policy for guest and staff reservation workflows. */
class BookingRules
{
    public static function dateErrors(string $checkIn, string $checkOut): array
    {
        $rules = Config::get('booking_rules', []);
        $errors = [];
        $today = new DateTimeImmutable('today');
        $arrival = DateTimeImmutable::createFromFormat('!Y-m-d', $checkIn);
        $departure = DateTimeImmutable::createFromFormat('!Y-m-d', $checkOut);
        if (!$arrival || !$departure) return ['dates' => 'Enter valid check-in and check-out dates.'];
        if ($arrival < $today) $errors['check_in'] = 'Check-in cannot be in the past.';
        if ($arrival > $today->modify('+' . (int) ($rules['max_advance_days'] ?? 365) . ' days')) {
            $errors['check_in'] = 'Check-in is beyond the booking window.';
        }
        $nights = (int) $arrival->diff($departure)->format('%r%a');
        if ($nights < 1) $errors['check_out'] = 'Check-out must be after check-in.';
        elseif ($nights > (int) ($rules['max_stay_nights'] ?? 30)) {
            $errors['check_out'] = 'The maximum stay is ' . (int) ($rules['max_stay_nights'] ?? 30) . ' nights.';
        }
        return $errors;
    }

    public static function rescheduleErrors(string $checkIn, string $checkOut): array
    {
        $errors = self::dateErrors($checkIn, $checkOut);
        if ($errors !== []) return $errors;
        $rules = Config::get('booking_rules', []);
        $arrival = new DateTimeImmutable($checkIn);
        $daysAhead = (int) (new DateTimeImmutable('today'))->diff($arrival)->format('%r%a');
        $minimum = (int) ($rules['free_reschedule_days'] ?? 7);
        if ($daysAhead < $minimum) $errors['check_in'] = 'Date changes must be requested at least ' . $minimum . ' days before arrival.';
        return $errors;
    }

    public static function cancellationResult(string $checkIn): string
    {
        $days = (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($checkIn))->format('%r%a');
        $window = (int) Config::get('booking_rules.deposit_non_refundable_days', 3);
        return $days <= $window ? 'deposit_non_refundable' : 'manual_review_required';
    }
}
