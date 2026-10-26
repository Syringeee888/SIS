<?php
// public/api/hold/create.php
//
// Module 4 — POST to claim a slot for a limited time. This is the endpoint
// that makes double-booking impossible.
//
// Body: { accommodation_id, check_in, check_out, guest_name?, guest_contact? }
//
//   201 → the slot is yours until expires_at
//   409 → somebody else holds or has booked it, with the blocking reference

declare(strict_types=1);

use App\Helpers\Response;
use App\Helpers\Validator;
use App\Repositories\AccommodationRepository;
use App\Services\CsrfProtection;
use App\Services\HoldManager;

require_once __DIR__ . '/../bootstrap.php';

Response::requireMethod('POST');

$input = Response::input();

if (!CsrfProtection::validate(CsrfProtection::fromRequest($input))) {
    Response::error('Invalid or missing CSRF token. Fetch one from /api/csrf-token.php.', 419);
}

$accommodationId = Validator::int($input['accommodation_id'] ?? null, 1);
$checkIn = Validator::date($input['check_in'] ?? null);
$checkOut = Validator::date($input['check_out'] ?? null);
$guestName = Validator::text($input['guest_name'] ?? '', 0, 120);
$guestContact = Validator::text($input['guest_contact'] ?? '', 0, 120);
$guestCount = Validator::int($input['guests'] ?? null, 1);

$errors = [];

if ($accommodationId === null) {
    $errors['accommodation_id'] = 'A valid accommodation_id is required.';
}

if ($checkIn === null) {
    $errors['check_in'] = 'check_in must be a valid date in Y-m-d format.';
}

if ($checkOut === null) {
    $errors['check_out'] = 'check_out must be a valid date in Y-m-d format.';
}

if ($checkIn !== null && $checkIn < date('Y-m-d')) {
    $errors['check_in'] = 'check_in cannot be in the past.';
}

if ($errors === [] && $checkOut <= $checkIn) {
    $errors['check_out'] = 'check_out must be later than check_in.';
}

if ($guestName === null) {
    $errors['guest_name'] = 'guest_name must be 120 characters or fewer.';
}

if ($guestContact === null) {
    $errors['guest_contact'] = 'guest_contact must be 120 characters or fewer.';
}
if ($guestCount === null) {
    $errors['guests'] = 'A valid guest count is required.';
}

if ($errors !== []) {
    Response::error('Validation failed.', 422, $errors);
}

$accommodation = (new AccommodationRepository())->findActive($accommodationId);

if ($accommodation === null) {
    Response::error('Accommodation not found.', 404);
}
if ($guestCount > (int) ($accommodation['capacity'] ?? 0)) {
    Response::error('Guest count exceeds this package capacity.', 422, ['guests' => 'Maximum capacity is ' . (int) $accommodation['capacity'] . '.']);
}

$manager = new HoldManager();

$result = $manager->place($accommodationId, $checkIn, $checkOut, [
    'guest_name' => $guestName,
    'guest_contact' => $guestContact,
    'guest_count' => $guestCount,
]);

if (!$result['granted']) {
    Response::error(
        'This slot is not available for the selected dates.',
        409,
        ['conflict' => $result['conflict']]
    );
}

$hold = $manager->present($result['hold']);

Response::success([
    'hold' => $hold,
    'accommodation' => $accommodation,
    'hold_minutes' => $manager->holdMinutes(),
    'message' => 'Slot held for ' . $manager->holdMinutes()
        . ' minutes. Upload your payment proof before it expires.',
], 201);

