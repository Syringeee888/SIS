<?php
// public/api/bootstrap.php
//
// Required by every endpoint in /api. Sets up autoloading, config, the session
// used for CSRF tokens, and JSON-only error handling so a PHP notice can never
// corrupt an API response.

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    $base = dirname(__DIR__, 2) . '/src/';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

require_once dirname(__DIR__, 2) . '/src/Config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Errors are logged, never echoed — the caller expects valid JSON.
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

set_exception_handler(static function (Throwable $e): void {
    error_log('[reservibe] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if (!headers_sent()) {
        http_response_code(500);
    }

    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    exit;
});
