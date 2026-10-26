<?php
// public/api/hold/release.php
//
// Module 4 — POST { hold_id } to hand a held slot back before it expires.

declare(strict_types=1);

use App\Helpers\Response;
use App\Helpers\Validator;
use App\Services\CsrfProtection;
use App\Services\HoldManager;

require_once __DIR__ . '/../bootstrap.php';

Response::requireMethod('POST');

$input = Response::input();

if (!CsrfProtection::validate(CsrfProtection::fromRequest($input))) {
    Response::error('Invalid or missing CSRF token. Fetch one from /api/csrf-token.php.', 419);
}

$holdId = Validator::int($input['hold_id'] ?? null, 1);

if ($holdId === null) {
    Response::error('Validation failed.', 422, ['hold_id' => 'A valid hold_id is required.']);
}

$result = (new HoldManager())->release($holdId);

if (($result['hold'] ?? null) === null) {
    Response::error($result['reason'] ?? 'Hold not found.', 404);
}

if (!$result['released']) {
    Response::error($result['reason'] ?? 'Hold could not be released.', 409, ['hold' => $result['hold']]);
}

Response::success([
    'hold' => $result['hold'],
    'message' => 'Hold released. The slot is available again.',
]);
