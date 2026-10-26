<?php
// src/Helpers/Response.php

namespace App\Helpers;

/**
 * Standard HTTP responses for the API, plus the plain-PHP view renderer used
 * by the guest pages served through public/index.php.
 *
 * Contract for every /api endpoint:
 *   success -> {"success": true,  "data": ...}
 *   failure -> {"success": false, "message": "...", "errors": {...}}
 */
class Response
{
    /** Wrap a payload in the standard success envelope and stop. */
    public static function success(mixed $data = null, int $status = 200): never
    {
        self::emit(['success' => true, 'data' => $data], $status);
    }

    /** Standard failure envelope and stop. */
    public static function error(string $message, int $status = 400, array $errors = []): never
    {
        $payload = ['success' => false, 'message' => $message];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        self::emit($payload, $status);
    }

    /**
     * Emit a raw array with no envelope — used by the FullCalendar feed,
     * which expects a bare JSON array of events.
     */
    public static function raw(array $payload, int $status = 200): never
    {
        self::emit($payload, $status);
    }

    /** Generic JSON emitter. Kept for backwards compatibility. */
    public static function json(mixed $data, int $status = 200): never
    {
        self::emit($data, $status);
    }

    /** Guard: only proceed for the expected HTTP verb. */
    public static function requireMethod(string $method): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== strtoupper($method)) {
            header('Allow: ' . strtoupper($method));
            self::error('Method not allowed. Use ' . strtoupper($method) . '.', 405);
        }
    }

    /** Require an authenticated session with one of the allowed roles. */
    public static function requireRole(array $roles): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $role = (string) ($_SESSION['user_role'] ?? 'guest');

        if (!in_array($role, $roles, true)) {
            self::error('Forbidden. This endpoint requires role: ' . implode(' or ', $roles) . '.', 403);
        }

        return $role;
    }
    /**
     * Merged request input: form/multipart fields plus a JSON body, if sent.
     * JSON keys win, because that is the format the frontend uses.
     */
    public static function input(): array
    {
        $data = is_array($_POST) ? $_POST : [];

        $raw = file_get_contents('php://input');

        if (is_string($raw) && trim($raw) !== '') {
            $json = json_decode($raw, true);

            if (is_array($json)) {
                $data = array_merge($data, $json);
            }
        }

        return $data;
    }

    /** Render a plain PHP view (no templating engine, by design). */
    public static function render(string $view, array $data = []): void
    {
        extract($data);
        $viewPath = __DIR__ . "/../../views/{$view}.php";

        if (file_exists($viewPath)) {
            include $viewPath;
        } else {
            http_response_code(500);
            die("View not found: {$view}");
        }
    }

    private static function emit(mixed $payload, int $status): never
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($status);
        }

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );

        exit;
    }
}
