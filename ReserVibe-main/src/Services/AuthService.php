<?php
// src/Services/AuthService.php

namespace App\Services;

use App\Config\Database;
use App\Helpers\Config;
use App\Repositories\LoginAttemptRepository;

/** Session authentication and a MySQL-backed login throttle for the local demo. */
class AuthService
{
    private const MAX_FAILURES = 5;
    private const WINDOW_SECONDS = 600;
    private const LOCK_SECONDS = 300;

    public static function retryAfter(string $username): int
    {
        $key = self::attemptKey($username);
        $row = (new LoginAttemptRepository())->find($key);
        return max(0, (int) ($row['locked_until'] ?? 0) - time());
    }

    public static function login(string $username, string $password): ?string
    {
        if (self::retryAfter($username) > 0) return null;

        $statement = Database::getInstance()->prepare(
            'SELECT id, username, password_hash, role FROM users WHERE username = ? AND is_active = 1 LIMIT 1'
        );
        $statement->execute([$username]);
        $user = $statement->fetch();
        if (is_array($user) && password_verify($password, (string) $user['password_hash'])) {
            self::completeLogin((int) $user['id'], (string) $user['username'], (string) $user['role']);
            self::clearFailures($username);
            return (string) $user['role'];
        }

        // Keep the configured local demo accounts usable for classroom setup.
        foreach (Config::get('demo_accounts', []) as $role => $account) {
            $expectedUser = (string) ($account['username'] ?? '');
            $passwordHash = (string) ($account['password_hash'] ?? '');
            if ($expectedUser !== '' && $passwordHash !== ''
                && hash_equals($expectedUser, $username) && password_verify($password, $passwordHash)) {
                self::completeLogin($expectedUser, $expectedUser, (string) $role);
                self::clearFailures($username);
                return (string) $role;
            }
        }
        self::recordFailure($username);
        return null;
    }

    private static function completeLogin(int|string $id, string $username, string $role): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
        $_SESSION['username'] = $username;
        $_SESSION['user_role'] = $role;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    private static function attemptKey(string $username): string
    {
        return hash('sha256', strtolower(trim($username)) . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'local'));
    }

    private static function recordFailure(string $username): void
    {
        $key = self::attemptKey($username);
        (new LoginAttemptRepository())->recordFailure($key, self::MAX_FAILURES, self::WINDOW_SECONDS, self::LOCK_SECONDS);
    }

    private static function clearFailures(string $username): void
    {
        $key = self::attemptKey($username);
        (new LoginAttemptRepository())->clear($key);
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000, 'path' => $params['path'], 'domain' => $params['domain'],
                'secure' => $params['secure'], 'httponly' => $params['httponly'], 'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }

    public static function role(): string
    {
        return session_status() === PHP_SESSION_ACTIVE ? (string) ($_SESSION['user_role'] ?? 'guest') : 'guest';
    }
}
