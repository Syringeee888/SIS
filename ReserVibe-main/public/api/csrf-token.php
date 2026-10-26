<?php
// public/api/csrf-token.php
//
// GET — mints (or returns) the session CSRF token. Every mutating endpoint
// requires it back, either as the X-CSRF-Token header or a csrf_token field.

declare(strict_types=1);

use App\Helpers\Response;
use App\Services\CsrfProtection;

require_once __DIR__ . '/bootstrap.php';

Response::requireMethod('GET');

Response::success([
    'csrf_token' => CsrfProtection::token(),
    'role' => $_SERVER['HTTP_X_USER_ROLE'] ?? 'guest',
]);
