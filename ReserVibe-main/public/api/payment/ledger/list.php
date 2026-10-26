<?php
declare(strict_types=1);

use App\Helpers\Response;
use App\Services\PaymentService;
require_once __DIR__ . '/../../bootstrap.php';
Response::requireMethod('GET');
Response::requireRole(['staff', 'admin']);
$id = null;
if (isset($_GET['reservation_id'])) {
    $id = filter_var($_GET['reservation_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) Response::error('Invalid reservation_id.', 422);
}
$entries = (new PaymentService())->ledger($id === null ? null : (int) $id);
Response::success(['payments' => $entries, 'count' => count($entries)]);
