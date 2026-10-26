<?php
declare(strict_types=1);

use App\Helpers\Response;
use App\Services\PaymentService;
require_once __DIR__ . '/../../bootstrap.php';
Response::requireMethod('GET');
Response::requireRole(['staff', 'admin']);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) Response::error('A valid proof id is required.', 422);
$file = (new PaymentService())->proofFile((int) $id);
if ($file === null) Response::error('Receipt not found.', 404);
header('Content-Type: ' . $file['mime']);
header('Content-Disposition: inline; filename="receipt-' . (int) $id . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($file['path']);
exit;
