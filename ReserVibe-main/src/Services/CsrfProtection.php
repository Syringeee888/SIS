<?php
// src/Services/CsrfProtection.php

namespace App\Services;

/**
 * Session-backed CSRF tokens, required on every mutating endpoint.
 *
 * The token is compared with hash_equals() so the check does not leak
 * information through timing.
 */
class CsrfProtection
{
    private const SESSION_KEY = 'csrf_token';

    /**
     * Returns the current session token, minting one on first use. The
     * frontend fetches this from GET /api/csrf-token.php and echoes it back in
     * the X-CSRF-Token header (or a csrf_token body field).
     */
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /** Pulls the token out of the header or the request body. */
    public static function fromRequest(array $input = []): ?string
    {
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (is_string($header) && $header !== '') {
            return $header;
        }

        $body = $input['csrf_token'] ?? null;

        return is_string($body) && $body !== '' ? $body : null;
    }

    public static function validate(?string $token): bool
    {
        if ($token === null || session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $expected = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($expected) && hash_equals($expected, $token);
    }

    /** Verifies the session is authenticated. */
    public static function isAuthenticated(): bool
    {
        return !empty($_SESSION['user_id']);
    }
}
