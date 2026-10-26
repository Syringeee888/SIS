<?php
// public/api/catalog/list.php
//
// Module 1 — GET the accommodation catalogue, the add-on list and the booking
// settings the frontend needs to render prices.

declare(strict_types=1);

use App\Helpers\Config;
use App\Helpers\Response;
use App\Repositories\AccommodationRepository;

require_once __DIR__ . '/../bootstrap.php';

Response::requireMethod('GET');

$repository = new AccommodationRepository();
$type = null;

if (isset($_GET['type']) && $_GET['type'] !== '') {
    $type = $repository->types() === [] ? null : \App\Helpers\Validator::in($_GET['type'], $repository->types());

    if ($type === null) {
        Response::error(
            'Unknown accommodation type. Valid types: ' . implode(', ', $repository->types()) . '.',
            422,
            ['type' => 'Invalid type filter.']
        );
    }
}

$accommodations = array_map(
    static function (array $room): array {
        $room['rate_formatted'] = number_format((float) ($room['rate'] ?? 0), 2);

        return $room;
    },
    $repository->active($type)
);

Response::success([
    'accommodations' => $accommodations,
    'types' => $repository->types(),
    'addons' => Config::get('addons', []),
    'payment_methods' => Config::get('payment_methods', []),
    'settings' => [
        'currency' => Config::get('currency', 'PHP'),
        'currency_symbol' => Config::get('currency_symbol', 'PHP'),
        'deposit_rate' => Config::get('deposit_rate', 0.5),
        'hold_minutes' => Config::get('hold_minutes', 90),
    ],
]);
