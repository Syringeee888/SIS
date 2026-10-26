<?php
// Module 10 — front-desk summary, computed from MySQL repositories.

declare(strict_types=1);

use App\Helpers\Response;
use App\Repositories\ReservationRepository;
use App\Services\PaymentService;
require_once __DIR__ . '/../bootstrap.php';
Response::requireMethod('GET');
Response::requireRole(['staff', 'admin']);
$reservations = (new ReservationRepository())->all();
$proofs = (new PaymentService())->proofs('pending');
$today = date('Y-m-d');
$active = array_values(array_filter($reservations, static fn (array $r): bool => in_array($r['status'] ?? '', ReservationRepository::BLOCKING_STATUSES, true)));
$sum = static fn (string $key): float => array_reduce($reservations, static fn (float $total, array $r): float => $total + (float) ($r[$key] ?? 0), 0.0);
$counts = [];
foreach (['pending_payment', 'pending_verification', 'confirmed', 'cancelled'] as $status) {
    $counts[$status] = count(array_filter($reservations, static fn (array $r): bool => ($r['status'] ?? '') === $status));
}
Response::success([
    'today' => $today,
    'total_reservations' => count($reservations),
    'active_reservations' => count($active),
    'upcoming_arrivals' => count(array_filter($active, static fn (array $r): bool => ($r['check_in'] ?? '') >= $today && ($r['guest_status'] ?? '') === 'reserved')),
    'walk_ins' => count(array_filter($reservations, static fn (array $r): bool => !empty($r['walk_in']))),
    'pending_proofs' => count($proofs),
    'statuses' => $counts,
    'total_recorded' => round($sum('amount_paid'), 2),
    'total_outstanding' => round(array_reduce($active, static fn (float $total, array $r): float => $total + (float) ($r['balance_due'] ?? 0), 0.0), 2),
]);
