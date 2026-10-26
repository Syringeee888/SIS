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
try {
    Response::success((new ReservationOperationsService())->createWalkIn($input, (string) $_SESSION['user_id']), 201);
} catch (InvalidArgumentException $error) {
    Response::error($error->getMessage(), 422);
}
