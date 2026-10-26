<?php
declare(strict_types=1);

use App\Helpers\Response;
use App\Services\CsrfProtection;
use App\Services\GoogleCalendarService;
require_once __DIR__ . '/../../bootstrap.php';
Response::requireMethod('POST');
Response::requireRole(['admin']);
$input = Response::input();
if (!CsrfProtection::validate(CsrfProtection::fromRequest($input))) Response::error('Invalid or missing CSRF token.', 419);
try {
    Response::success((new GoogleCalendarService())->syncAll());
} catch (RuntimeException $error) {
    Response::error($error->getMessage(), 502);
}
