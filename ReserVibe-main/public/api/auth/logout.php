<?php
declare(strict_types=1);

use App\Helpers\Response;
use App\Services\AuthService;
use App\Services\CsrfProtection;

require_once __DIR__ . '/../bootstrap.php';
Response::requireMethod('POST');
$input = Response::input();
if (!CsrfProtection::validate(CsrfProtection::fromRequest($input))) {
    Response::error('Invalid or missing CSRF token.', 419);
}
AuthService::logout();
Response::success(['message' => 'Signed out.']);
