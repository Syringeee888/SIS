<?php
// Guest multipart endpoint: upload receipt evidence for an existing reservation.

declare(strict_types=1);

use App\Helpers\Response;
use App\Repositories\ReservationRepository;
use App\Services\CsrfProtection;
use App\Services\PaymentService;

require_once __DIR__ . '/../../bootstrap.php';
Response::requireMethod('POST');
$input = Response::input();
if (!CsrfProtection::validate(CsrfProtection::fromRequest($input))) Response::error('Invalid or missing CSRF token.', 419);

$reference = $input['reservation_reference'] ?? null;
$email = $input['guest_email'] ?? null;
$amount = $input['amount'] ?? null;
if (!is_string($reference) || !preg_match('/^RSV-\d{4,}$/', $reference)
    || !is_string($email) || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)
    || !is_numeric($amount) || !isset($_FILES['proof'])) {
    Response::error('Enter your reservation reference, booking email, payment amount, and receipt image.', 422);
}
$record = null;
foreach ((new ReservationRepository())->all() as $reservation) {
    if (($reservation['reference'] ?? null) === $reference) { $record = $reservation; break; }
}
if ($record === null) Response::error('Reservation reference or email did not match.', 404);

try {
    $proof = (new PaymentService())->submitProof((int) $record['id'], trim($email), (float) $amount, $_FILES['proof']);
    Response::success(['proof' => $proof, 'message' => 'Receipt received and queued for staff review. No automatic payment was processed.'], 201);
} catch (InvalidArgumentException $error) {
    Response::error($error->getMessage(), 422);
}
