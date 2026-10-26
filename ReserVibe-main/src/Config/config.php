<?php
// src/Config/config.php
//
// Single source of truth for tunable settings. Anything a defense might ask
// "where is that number set?" about belongs here, not hardcoded in a view.

/**
 * Reads a key from the project .env file.
 *
 * The name is deliberately not getEnv(): PHP function names are
 * case-insensitive, so getEnv() *is* the built-in getenv(). The
 * function_exists() guard below would then always be true, silently skip the
 * definition, and every lookup would fall through to PHP's getenv() — which
 * takes a bool as its second argument and returns false for a missing key.
 * That is why this helper is named reservibe_env().
 */
if (!function_exists('reservibe_env')) {
    function reservibe_env(string $key, $default = null)
    {
        static $env = null;

        if ($env === null) {
            $envPath = __DIR__ . '/../../.env';
            $env = [];

            // .env.example uses # comments, which parse_ini_file() does not
            // accept consistently. Read simple KEY=VALUE entries directly so
            // copying the example never emits warnings into rendered pages.
            if (is_file($envPath)) {
                foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $line = trim($line);

                    if ($line === '' || $line[0] === '#' || $line[0] === ';' || !str_contains($line, '=')) {
                        continue;
                    }

                    [$name, $value] = array_map('trim', explode('=', $line, 2));

                    if ($name !== '') {
                        $length = strlen($value);
                        if ($length >= 2 && (($value[0] === '"' && $value[$length - 1] === '"')
                            || ($value[0] === "'" && $value[$length - 1] === "'"))) {
                            $value = substr($value, 1, -1);
                        }

                        $env[$name] = $value;
                    }
                }
            }
        }

        if (isset($env[$key]) && $env[$key] !== '') {
            return $env[$key];
        }

        // Fall back to real environment variables, so hosting can override
        // .env without editing files.
        $fromSystem = getenv($key);

        if ($fromSystem !== false && $fromSystem !== '') {
            return $fromSystem;
        }

        return $default;
    }
}

$root = dirname(__DIR__, 2);

// The resort is in the Philippines; hold countdowns and timestamps must read in
// resort-local time rather than whatever the hosting default happens to be.
$timezone = (string) reservibe_env('APP_TIMEZONE', 'Asia/Manila');
date_default_timezone_set($timezone);

return [
    'timezone' => $timezone,

    'db' => [
        'host' => reservibe_env('DB_HOST', 'localhost'),
        'name' => reservibe_env('DB_NAME', 'reservibe_db'),
        'user' => reservibe_env('DB_USER', 'root'),
        'pass' => reservibe_env('DB_PASS', ''),
    ],
    'app_name' => 'ReserVibe Prototype',

    // Local demo accounts are configured with password hashes in the private .env.
    'demo_accounts' => [
        'admin' => [
            'username' => reservibe_env('DEMO_ADMIN_USER', 'admin'),
            'password_hash' => reservibe_env('DEMO_ADMIN_PASSWORD_HASH', ''),
        ],
        'staff' => [
            'username' => reservibe_env('DEMO_STAFF_USER', 'staff'),
            'password_hash' => reservibe_env('DEMO_STAFF_PASSWORD_HASH', ''),
        ],
    ],

    // Module 3 — cost calculation
    'currency' => 'PHP',
    'currency_symbol' => "\u{20B1}",           // Peso sign
    'deposit_rate' => 0.50,                    // required down payment

    // Module 4 — temporary holds
    'hold_minutes' => 90,                      // slot lock lifetime before payment proof

    // Add-on catalogue. The client sends add-on *codes*; the server decides
    // what they cost, so a tampered request cannot change the price.
    'addons' => [
        ['code' => 'videoke',  'label' => 'Videoke Machine',  'rate' => 800.0],
        ['code' => 'grilling', 'label' => 'Grilling Station', 'rate' => 300.0],
    ],

    // Module 5 — payment proof uploads
    'uploads' => [
        'dir' => $root . '/public/uploads/payment-proofs',
        'max_bytes' => 5 * 1024 * 1024,        // 5 MB
        'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    // Module 5 — how the guest settles the deposit.
    //
    // The single source of truth for "what can I pay with?". The booking view
    // renders these options from here, and the API validates whatever the
    // client sends against this same list, so the two can never disagree.
    //
    //   requires_proof     guest pays outside the system, then uploads a receipt
    //   redirect           in production this hands off to an online gateway
    //   settles_on_arrival nothing is collected online; the desk collects it
    'payment_methods' => [
        [
            'code' => 'gcash',
            'label' => 'GCash',
            'group' => 'E-Wallet',
            'requires_proof' => true,
            'qr_image' => '/assets/img/gcash-qr.png',
            'instructions' => 'Scan the GCash QR, pay the deposit, then upload your receipt. School demo only: this QR is a sample and no real payment is processed. Do not send money.',
        ],
    ],

    'paths' => [
        'root' => $root,
    ],
];
