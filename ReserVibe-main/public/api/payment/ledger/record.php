<?php
declare(strict_types=1);

use App\Helpers\Response;
use App\Helpers\Text;
use App\Repositories\ReservationRepository;
use App\Services\CsrfProtection;
use App\Services\PaymentService;
require_once __DIR__ . '/../../bootstrap.php';
Response::requireMethod('POST');
Response::requireRole(['staff', 'admin']);
$input = Response::input();
if (!CsrfProtection::validate(CsrfProtection::fromRequest($input))) Response::error('Invalid or missing CSRF token.', 419);
$reservationId = filter_var($input['reservation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$amount = $input['amount'] ?? null;
$method = $input['method'] ?? null;
$note = $input['note'] ?? '';
if (!$reservationId || !is_numeric($amount) || !is_string($method) || !is_string($note) || Text::length($note) > 300) {
    Response::error('Provide a reservation, a payment amount, a method, and a note under 300 characters.', 422);
}
try {
    $result = (new PaymentService())->recordPayment((int) $reservationId, (float) $amount, $method, (string) $_SESSION['user_id'], trim($note));
    Response::success($result, 201);
} catch (InvalidArgumentException $error) {
    Response::error($error->getMessage(), 422);
}
