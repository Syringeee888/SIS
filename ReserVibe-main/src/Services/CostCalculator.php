<?php
// src/Services/CostCalculator.php

namespace App\Services;

use App\Helpers\Config;

/**
 * Module 3 — cost calculation.
 *
 * Storage-agnostic and deterministic: it takes an accommodation record and a
 * list of add-on codes and returns the money breakdown. The client never sends
 * prices, only choices, so a tampered request cannot change the total.
 */
class CostCalculator
{
    /**
     * @param  array<string, mixed> $accommodation Record from AccommodationRepository.
     * @param  array<int, string>   $addonCodes    Add-on codes, e.g. ['videoke'].
     * @return array<string, mixed> Full breakdown, rounded to centavos.
     */
    public function quote(array $accommodation, array $addonCodes = [], int $nights = 1): array
    {
        $nights = max(1, $nights);
        $rate = (float) ($accommodation['rate'] ?? 0);
        $subtotal = round($rate * $nights, 2);

        $addons = [];
        $addonsTotal = 0.0;

        foreach (Config::get('addons', []) as $definition) {
            if (in_array($definition['code'], $addonCodes, true)) {
                $addons[] = $definition;
                $addonsTotal += (float) $definition['rate'];
            }
        }

        $addonsTotal = round($addonsTotal, 2);
        $total = round($subtotal + $addonsTotal, 2);

        $depositRate = (float) Config::get('deposit_rate', 0.5);
        $depositDue = round($total * $depositRate, 2);

        return [
            'accommodation_id' => (int) ($accommodation['id'] ?? 0),
            'accommodation_name' => (string) ($accommodation['name'] ?? ''),
            'nights' => $nights,
            'rate' => $rate,
            'subtotal' => $subtotal,
            'addons' => $addons,
            'addons_total' => $addonsTotal,
            'total' => $total,
            'deposit_rate' => $depositRate,
            'deposit_due' => $depositDue,
            'balance_due' => round($total - $depositDue, 2),
            'currency' => Config::get('currency', 'PHP'),
            'currency_symbol' => Config::get('currency_symbol', 'PHP'),
        ];
    }

    /** Add-on codes the catalogue does not recognise, for input validation. */
    public function unknownAddonCodes(array $codes): array
    {
        $known = array_map(
            static fn (array $definition): string => (string) $definition['code'],
            Config::get('addons', [])
        );

        return array_values(array_diff($codes, $known));
    }

    /** Whole nights between two Y-m-d dates; null when the range is invalid. */
    public function nightsBetween(string $checkIn, string $checkOut): ?int
    {
        $in = strtotime($checkIn);
        $out = strtotime($checkOut);

        if ($in === false || $out === false || $out <= $in) {
            return null;
        }

        return (int) round(($out - $in) / 86400);
    }
}
