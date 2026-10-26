<?php
declare(strict_types=1);

use App\Helpers\Response;
use App\Services\PaymentService;
require_once __DIR__ . '/../../bootstrap.php';
Response::requireMethod('GET');
Response::requireRole(['staff', 'admin']);
$status = isset($_GET['status']) && $_GET['status'] !== '' ? (string) $_GET['status'] : null;
if ($status !== null && !in_array($status, ['pending', 'approved', 'rejected'], true)) Response::error('Unknown proof status.', 422);
$proofs = (new PaymentService())->proofs($status);
Response::success(['proofs' => $proofs, 'count' => count($proofs)]);
