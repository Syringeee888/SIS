<?php
declare(strict_types=1);

use App\Helpers\Response;
use App\Services\GoogleCalendarService;
require_once __DIR__ . '/../../bootstrap.php';
Response::requireMethod('GET');
Response::requireRole(['admin']);
$service = new GoogleCalendarService();
Response::success(['configured' => $service->configured(), 'connected' => $service->connected()]);
