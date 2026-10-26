<?php
// public/api/reservation/calculate.php
//
// Module 3 — POST a quote request, get the money breakdown back.
// Side-effect free, but still CSRF-checked: every POST in this API is.
//
// Body: { accommodation_id, check_in, check_out, addons: ["videoke"] }

declare(strict_types=1);

use App\Helpers\Response;
use App\Helpers\Validator;
use App\Repositories\AccommodationRepository;
use App\Repositories\HoldRepository;
use App\Repositories\ReservationRepository;
use App\Services\AvailabilityService;
use App\Services\CostCalculator;
use App\Services\CsrfProtection;

require_once __DIR__ . '/../bootstrap.php';

Response::requireMethod('POST');

$input = Response::input();

if (!CsrfProtection::validate(CsrfProtection::fromRequest($input))) {
    Response::error('Invalid or missing CSRF token. Fetch one from /api/csrf-token.php.', 419);
}

$accommodationId = Validator::int($input['accommodation_id'] ?? null, 1);
$checkIn = Validator::date($input['check_in'] ?? null);
$checkOut = Validator::date($input['check_out'] ?? null);
$addonCodes = Validator::list($input['addons'] ?? []);

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

$calculator = new CostCalculator();
$unknown = $calculator->unknownAddonCodes($addonCodes);

if ($unknown !== []) {
    $errors['addons'] = 'Unknown add-on code(s): ' . implode(', ', $unknown) . '.';
}

if ($errors !== []) {
    Response::error('Validation failed.', 422, $errors);
}

$accommodation = (new AccommodationRepository())->find($accommodationId);

if ($accommodation === null) {
    Response::error('Accommodation not found.', 404);
}

$nights = $calculator->nightsBetween($checkIn, $checkOut) ?? 1;

$conflict = (new AvailabilityService())->findConflict(
    (new ReservationRepository())->all(),
    (new HoldRepository())->all(),
    $accommodationId,
    $checkIn,
    $checkOut
);

Response::success([
    'quote' => $calculator->quote($accommodation, $addonCodes, $nights),
    'available' => $conflict === null,
    'conflict' => $conflict,
]);
