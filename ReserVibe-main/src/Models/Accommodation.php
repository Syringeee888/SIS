<?php
// src/Models/Accommodation.php

namespace App\Models;

use App\Repositories\AccommodationRepository;

/**
 * Thin facade over AccommodationRepository.
 *
 * Controllers and views keep calling Accommodation::getAll(), but the rates
 * themselves live in the MySQL accommodations table and are edited in exactly one
 * place. Nothing here duplicates data.
 */
class Accommodation
{
    /**
     * Available rooms, cottages and gazebos.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getAll(?string $type = null): array
    {
        return (new AccommodationRepository())->active($type);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(mixed $id): ?array
    {
        if (!is_numeric($id)) {
            return null;
        }

        return (new AccommodationRepository())->find((int) $id);
    }
}
