<?php
// public/api/availability/check.php
//
// Module 2 — GET ?accommodation_id=&check_in=&check_out=
// Answers one question: is this slot free, and if not, what is holding it?

declare(strict_types=1);

use App\Helpers\Response;
use App\Helpers\Validator;
use App\Repositories\AccommodationRepository;
use App\Repositories\HoldRepository;
use App\Repositories\ReservationRepository;
use App\Services\AvailabilityService;

require_once __DIR__ . '/../bootstrap.php';

Response::requireMethod('GET');

$accommodationId = Validator::int($_GET['accommodation_id'] ?? null, 1);
$checkIn = Validator::date($_GET['check_in'] ?? null);
$checkOut = Validator::date($_GET['check_out'] ?? null);

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

if ($errors === [] && $checkOut <= $checkIn) {
    $errors['check_out'] = 'check_out must be later than check_in.';
}

if ($errors !== []) {
    Response::error('Validation failed.', 422, $errors);
}

$accommodation = (new AccommodationRepository())->find($accommodationId);

if ($accommodation === null) {
    Response::error('Accommodation not found.', 404);
}

$reservations = (new ReservationRepository())->all();
$holds = (new HoldRepository())->all();
$availability = new AvailabilityService();

$conflict = $availability->findConflict($reservations, $holds, $accommodationId, $checkIn, $checkOut);

Response::success([
    'accommodation' => $accommodation,
    'check_in' => $checkIn,
    'check_out' => $checkOut,
    'available' => $conflict === null,
    'conflict' => $conflict,
]);
