<?php
// src/Repositories/AccommodationRepository.php

namespace App\Repositories;

use App\Config\Database;
use PDO;

/** MySQL repository for the accommodation catalogue. */
class AccommodationRepository
{
    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $rows = Database::getInstance()->query(
            'SELECT id, code, name, type, description, price, guest_limit, primary_image_path, status FROM accommodations ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'fromRow'], $rows);
    }

    /** @return array<int, array<string, mixed>> */
    public function active(?string $type = null): array
    {
        return array_values(array_filter($this->all(), static fn (array $item): bool =>
            $item['active'] && ($type === null || $item['type'] === $type)
        ));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $statement = Database::getInstance()->prepare(
            'SELECT id, code, name, type, description, price, guest_limit, primary_image_path, status FROM accommodations WHERE id = ?'
        );
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->fromRow($row) : null;
    }

    public function findActive(int $id): ?array
    {
        $item = $this->find($id);
        return $item !== null && $item['active'] ? $item : null;
    }

    /** Persist an accommodation while retaining the repository's app-facing shape. */
    public function save(array $item): array
    {
        $id = (int) ($item['id'] ?? 0);
        $slug = substr(trim((string) ($item['slug'] ?? '')), 0, 30);
        $type = strtolower(trim((string) ($item['type'] ?? 'package')));
        $statement = Database::getInstance()->prepare(
            'INSERT INTO accommodations (id, code, name, type, description, price, deposit_rate, guest_limit, primary_image_path, status)
             VALUES (NULLIF(?, 0), ?, ?, ?, ?, ?, 50.00, ?, ?, ?)
             ON DUPLICATE KEY UPDATE code = VALUES(code), name = VALUES(name), type = VALUES(type), description = VALUES(description),
                price = VALUES(price), guest_limit = VALUES(guest_limit), status = VALUES(status)'
        );
        $statement->execute([
            $id, $slug, (string) ($item['name'] ?? ''), $type,
            (string) ($item['description'] ?? ''), (float) ($item['rate'] ?? 0),
            (int) ($item['capacity'] ?? 1), 'assets/img/hero-pool.jpg',
            !empty($item['active']) ? 'active' : 'inactive',
        ]);

        if ($id < 1) $item['id'] = (int) Database::getInstance()->lastInsertId();
        return $item;
    }

    public function types(): array
    {
        $types = array_map(static fn (array $item): string => (string) $item['type'], $this->all());
        return array_values(array_unique(array_filter($types)));
    }

    private function fromRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['code'],
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'capacity' => (int) $row['guest_limit'],
            'rate' => (float) $row['price'],
            'description' => (string) ($row['description'] ?? ''),
            'image' => (string) ($row['primary_image_path'] ?? 'assets/img/hero-pool.jpg'),
            'active' => $row['status'] === 'active',
        ];
    }
}
