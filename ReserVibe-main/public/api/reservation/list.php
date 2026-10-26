<?php
// public/api/reservation/list.php
//
// Modules 6 + 9 — GET ?status= for the staff desk. Staff/admin only.

declare(strict_types=1);

use App\Helpers\Response;
use App\Repositories\ReservationRepository;
use App\Services\ReservationService;

require_once __DIR__ . '/../bootstrap.php';

Response::requireMethod('GET');
Response::requireRole(['staff', 'admin']);

$statusParam = $_GET['status'] ?? null;
$status = null;

if ($statusParam !== null && $statusParam !== '') {
    $status = (string) $statusParam;
    $repository = new ReservationRepository();
    $valid = [
        ReservationRepository::STATUS_PENDING_PAYMENT,
        ReservationRepository::STATUS_PENDING_VERIFICATION,
        ReservationRepository::STATUS_CONFIRMED,
        ReservationRepository::STATUS_PAYMENT_REJECTED,
        ReservationRepository::STATUS_CANCELLED,
    ];

    if (!in_array($status, $valid, true)) {
        Response::error('Unknown status filter. Valid values: ' . implode(', ', $valid) . '.', 422);
    }
}

$service = new ReservationService();
$reservations = $service->listByStatus($status);

Response::success([
    'reservations' => $reservations,
    'count' => count($reservations),
    'filter' => ['status' => $status],
]);
