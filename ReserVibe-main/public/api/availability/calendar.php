<?php
// public/api/availability/calendar.php
//
// Module 2 — FullCalendar feed: GET ?start=&end=&accommodation_id=
// Returns a bare JSON array of events, which is what FullCalendar expects.

declare(strict_types=1);

use App\Helpers\Response;
use App\Helpers\Validator;
use App\Repositories\AccommodationRepository;
use App\Repositories\HoldRepository;
use App\Repositories\ReservationRepository;
use App\Services\AvailabilityService;

require_once __DIR__ . '/../bootstrap.php';

Response::requireMethod('GET');

$from = Validator::date($_GET['start'] ?? null);
$to = Validator::date($_GET['end'] ?? null);
$accommodationId = null;

if (isset($_GET['accommodation_id']) && $_GET['accommodation_id'] !== '') {
    $accommodationId = Validator::int($_GET['accommodation_id'], 1);

    if ($accommodationId === null) {
        Response::error('accommodation_id must be a positive integer.', 422);
    }
}

$events = (new AvailabilityService())->calendarEvents(
    (new AccommodationRepository())->active(),
    (new ReservationRepository())->all(),
    (new HoldRepository())->all(),
    $from,
    $to,
    $accommodationId
);

Response::raw($events);
