<?php
// POST username/password and start a demo staff session.

declare(strict_types=1);

use App\Helpers\Response;
use App\Services\AuthService;
use App\Services\CsrfProtection;

require_once __DIR__ . '/../bootstrap.php';
Response::requireMethod('POST');
$input = Response::input();
if (!CsrfProtection::validate(CsrfProtection::fromRequest($input))) Response::error('Invalid or missing CSRF token.', 419);
$username = $input['username'] ?? null;
$password = $input['password'] ?? null;
if (!is_string($username) || !is_string($password) || strlen($username) > 80 || strlen($password) > 200) {
    Response::error('Enter a valid username and password.', 422);
}
$retryAfter = AuthService::retryAfter($username);
if ($retryAfter > 0) {
    header('Retry-After: ' . $retryAfter);
    Response::error('Too many failed attempts. Try again in a few minutes.', 429);
}
$role = AuthService::login($username, $password);
if ($role === null) {
    $retryAfter = AuthService::retryAfter($username);
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
        Response::error('Too many failed attempts. Try again in a few minutes.', 429);
    }
    Response::error('The username or password is incorrect.', 401);
}
Response::success(['role' => $role, 'redirect' => '?action=dashboard']);
