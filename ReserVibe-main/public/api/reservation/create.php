<?php
// public/api/reservation/create.php
//
// Modules 3 + 4 — POST to turn a live hold into a reservation.
// Requires: an active hold (hold_id) plus guest details.
//
//   201 → reservation created in status pending_payment
//   409 → the hold lapsed or the slot was taken

declare(strict_types=1);

use App\Helpers\Response;
use App\Services\CsrfProtection;
use App\Services\ReservationService;

require_once __DIR__ . '/../bootstrap.php';

Response::requireMethod('POST');

$input = Response::input();

if (!CsrfProtection::validate(CsrfProtection::fromRequest($input))) {
    Response::error('Invalid or missing CSRF token. Fetch one from /api/csrf-token.php.', 419);
}

$result = (new ReservationService())->create($input);

if (!$result['ok']) {
    $errors = $result['errors'] ?? [];

    if ($errors !== []) {
        Response::error($result['message'] ?? 'Validation failed.', 422, $errors);
    }

    $extra = isset($result['conflict']) ? ['conflict' => $result['conflict']] : [];

    Response::error($result['message'] ?? 'Reservation could not be created.', 409, $extra);
}

$reservation = $result['reservation'];
$_SESSION['last_confirmation'] = $reservation;

Response::success([
    'reservation' => $reservation,
    'quote' => $result['quote'],
    'hold' => $result['hold'],
    'message' => 'Reservation ' . $reservation['reference'] . ' created. Upload your '
        . number_format((float) $reservation['deposit_due'], 2) . ' deposit to move it to verification.',
], 201);

