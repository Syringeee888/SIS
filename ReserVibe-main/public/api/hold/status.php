<?php
// public/api/hold/status.php
//
// Module 4 — GET ?hold_id= returns the hold with its countdown. Expiry is
// evaluated lazily here (no cron job), so the answer is always current.

declare(strict_types=1);

use App\Helpers\Response;
use App\Helpers\Validator;
use App\Services\HoldManager;

require_once __DIR__ . '/../bootstrap.php';

Response::requireMethod('GET');

$holdId = Validator::int($_GET['hold_id'] ?? null, 1);

if ($holdId === null) {
    Response::error('Validation failed.', 422, ['hold_id' => 'A valid hold_id is required.']);
}

$manager = new HoldManager();
$hold = $manager->status($holdId);

if ($hold === null) {
    Response::error('Hold not found.', 404);
}

$minutes = intdiv($hold['remaining_seconds'], 60);
$seconds = $hold['remaining_seconds'] % 60;

Response::success([
    'hold' => $hold,
    'remaining_label' => sprintf('%d:%02d', $minutes, $seconds),
    'usable' => $manager->isUsable($hold),
]);
