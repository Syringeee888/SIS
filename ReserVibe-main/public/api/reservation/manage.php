<?php
// Guest actions are matched by reservation reference + booking email.

declare(strict_types=1);

use App\Helpers\Response;
use App\Services\CsrfProtection;
use App\Services\ReservationOperationsService;
require_once __DIR__ . '/../bootstrap.php';
Response::requireMethod('POST');
$input = Response::input();
if (!CsrfProtection::validate(CsrfProtection::fromRequest($input))) Response::error('Invalid or missing CSRF token.', 419);
$action = $input['action'] ?? null;
$reference = $input['reservation_reference'] ?? null;
$email = $input['guest_email'] ?? null;
if (!is_string($action) || !in_array($action, ['cancel', 'reschedule'], true)
    || !is_string($reference) || !preg_match('/^RSV-\d{4,}$/i', $reference)) {
    Response::error('Choose a valid reservation and action.', 422);
}
$staff = in_array($_SESSION['user_role'] ?? '', ['staff', 'admin'], true);
if (!$staff && (!is_string($email) || !filter_var(trim($email), FILTER_VALIDATE_EMAIL))) Response::error('Enter the booking email address.', 422);
$service = new ReservationOperationsService();
try {
    if ($action === 'cancel') {
        $reservation = $service->cancel($reference, is_string($email) ? trim($email) : null, $staff ? (string) $_SESSION['user_id'] : 'guest', $staff);
                $message = ($reservation['cancellation_policy_result'] ?? '') === 'deposit_non_refundable'
            ? 'Reservation cancelled. The official policy marks the deposit non-refundable within 3 days of arrival. No refund has been issued.'
            : 'Reservation cancelled. Any eligible refund request is now subject to staff review. No refund has been processed automatically.';
        Response::success(['reservation' => $reservation, 'message' => $message]);
    }
    $checkIn = is_string($input['check_in'] ?? null) ? $input['check_in'] : '';
    $checkOut = is_string($input['check_out'] ?? null) ? $input['check_out'] : '';
    if (App\Helpers\Validator::date($checkIn) === null || App\Helpers\Validator::date($checkOut) === null) Response::error('Enter valid check-in and check-out dates.', 422);
    Response::success($service->reschedule($reference, is_string($email) ? trim($email) : null, $checkIn, $checkOut, $staff ? (string) $_SESSION['user_id'] : 'guest', $staff));
} catch (InvalidArgumentException $error) {
    Response::error($error->getMessage(), 409);
}
