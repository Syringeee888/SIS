<?php
declare(strict_types=1);

use App\Helpers\Response;
use App\Services\CsrfProtection;
use App\Services\ReservationOperationsService;
require_once __DIR__ . '/../bootstrap.php';
Response::requireMethod('POST');
Response::requireRole(['staff', 'admin']);
$input = Response::input();
if (!CsrfProtection::validate(CsrfProtection::fromRequest($input))) Response::error('Invalid or missing CSRF token.', 419);
$id = filter_var($input['reservation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$status = $input['guest_status'] ?? null;
if (!$id || !is_string($status)) Response::error('Provide a reservation id and guest status.', 422);
try {
    Response::success(['reservation' => (new ReservationOperationsService())->updateGuestStatus((int) $id, $status, (string) $_SESSION['user_id'])]);
} catch (InvalidArgumentException $error) {
    Response::error($error->getMessage(), 409);
}
