<?php
declare(strict_types=1);

use App\Helpers\Response;
use App\Helpers\Text;
use App\Services\CsrfProtection;
use App\Services\PaymentService;
require_once __DIR__ . '/../../bootstrap.php';
Response::requireMethod('POST');
Response::requireRole(['staff', 'admin']);
$input = Response::input();
if (!CsrfProtection::validate(CsrfProtection::fromRequest($input))) Response::error('Invalid or missing CSRF token.', 419);
$id = filter_var($input['proof_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$decision = $input['decision'] ?? null;
$note = $input['note'] ?? '';
if (!$id || !is_string($decision) || !is_string($note) || Text::length($note) > 300) Response::error('Provide a proof id, decision, and a note under 300 characters.', 422);
try {
    $result = (new PaymentService())->review((int) $id, $decision, trim($note), (string) $_SESSION['user_id']);
    Response::success($result);
} catch (InvalidArgumentException $error) {
    Response::error($error->getMessage(), 409);
}
