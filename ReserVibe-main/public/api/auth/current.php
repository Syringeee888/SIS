<?php
declare(strict_types=1);

use App\Helpers\Response;
use App\Services\AuthService;
use App\Services\CsrfProtection;

require_once __DIR__ . '/../bootstrap.php';
Response::requireMethod('GET');
Response::success(['role' => AuthService::role(), 'csrf_token' => CsrfProtection::token()]);
