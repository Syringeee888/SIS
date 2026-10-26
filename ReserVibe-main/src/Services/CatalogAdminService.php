<?php
// src/Services/CatalogAdminService.php

namespace App\Services;

use App\Helpers\Validator;
use App\Repositories\AccommodationRepository;
use App\Repositories\DatabaseLock;
use App\Repositories\HoldRepository;
use InvalidArgumentException;

/** Admin-only package maintenance backed by the accommodations table. */
class CatalogAdminService
{
    public function __construct(private ?AccommodationRepository $catalog = null)
    {
        $this->catalog ??= new AccommodationRepository();
    }

    public function save(array $input): array
    {
        $id = null;
        if (array_key_exists('id', $input) && $input['id'] !== '' && $input['id'] !== null) {
            $id = Validator::int($input['id'], 1);
            if ($id === null) throw new InvalidArgumentException('Choose a valid package to update.');
        }

        $name = Validator::text($input['name'] ?? null, 2, 120);
        $type = Validator::text($input['type'] ?? null, 2, 40);
        $capacity = Validator::int($input['capacity'] ?? null, 1, 500);
        $rateInput = $input['rate'] ?? null;
        $rate = is_numeric($rateInput) ? (float) $rateInput : NAN;
        $descriptionInput = $input['description'] ?? '';
        $description = Validator::text($descriptionInput, 0, 500);
        $activeInput = $input['active'] ?? ($id === null ? true : null);
        $active = is_bool($activeInput) ? $activeInput
            : (in_array($activeInput, ['true', '1', 1], true) ? true
                : (in_array($activeInput, ['false', '0', 0], true) ? false : null));

        if ($name === null || $type === null || $capacity === null || !is_finite($rate)
            || $rate < 1 || $rate > 10000000 || $description === null || $active === null) {
            throw new InvalidArgumentException('Enter a name, type, capacity, valid rate, description, and active state.');
        }

        return DatabaseLock::withLock(function () use ($id, $name, $type, $capacity, $rate, $description, $active): array {
            $items = $this->catalog->all();
            $index = null;
            if ($id !== null) {
                foreach ($items as $i => $item) {
                    if ((int) ($item['id'] ?? 0) === $id) { $index = $i; break; }
                }
                if ($index === null) throw new InvalidArgumentException('That package no longer exists.');
                if (abs((float) ($items[$index]['rate'] ?? 0) - $rate) > 0.001) {
                    foreach ((new HoldRepository())->all() as $hold) {
                        if ((int) ($hold['accommodation_id'] ?? 0) === $id
                            && ($hold['status'] ?? '') === HoldRepository::STATUS_ACTIVE
                            && (strtotime((string) ($hold['expires_at'] ?? '')) ?: 0) > time()) {
                            throw new InvalidArgumentException('Wait for active booking holds on this package to expire before changing its rate.');
                        }
                    }
                }
            }

            $slugBase = substr(strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-')), 0, 22);
            if ($slugBase === '') $slugBase = 'package';
            $slug = $slugBase;
            $suffix = 2;
            while ($this->slugExists($items, $slug, $index)) $slug = $slugBase . '-' . $suffix++;

            $record = [
                'id' => $index === null ? $this->nextId($items) : (int) $items[$index]['id'],
                'slug' => $slug,
                'name' => $name,
                'type' => strtolower($type),
                'capacity' => $capacity,
                'rate' => round($rate, 2),
                'description' => $description,
                'active' => $active,
            ];
            if ($index === null) $items[] = $record;
            else $items[$index] = $record;

            return $this->catalog->save($record);
        });
    }

    private function slugExists(array $items, string $slug, ?int $exceptIndex): bool
    {
        foreach ($items as $index => $item) {
            if ($index !== $exceptIndex && ($item['slug'] ?? '') === $slug) return true;
        }
        return false;
    }

    private function nextId(array $items): int
    {
        return 1 + array_reduce($items, static fn (int $max, array $item): int => max($max, (int) ($item['id'] ?? 0)), 0);
    }
}
