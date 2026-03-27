<?php

declare(strict_types=1);

/**
 * Application Configuration
 */

return [
    'name'     => env('APP_NAME', 'SyncCU'),
    'env'      => env('APP_ENV', 'production'),
    'debug'    => env('APP_DEBUG', false),
    'url'      => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'key'      => env('APP_KEY', ''),

    'jwt' => [
        'secret'         => env('JWT_SECRET', ''),
        'expiry'         => (int) env('JWT_EXPIRY', 3600),
        'refresh_expiry' => (int) env('JWT_REFRESH_EXPIRY', 604800),
    ],

    'encryption' => [
        'key' => env('ENCRYPTION_KEY', ''),
    ],

    'rate_limit' => [
        'per_minute' => (int) env('RATE_LIMIT_PER_MINUTE', 60),
    ],

    'mail' => [
        'host'         => env('MAIL_HOST', ''),
        'port'         => (int) env('MAIL_PORT', 587),
        'username'     => env('MAIL_USERNAME', ''),
        'password'     => env('MAIL_PASSWORD', ''),
        'from_address' => env('MAIL_FROM_ADDRESS', 'noreply@synccu.com'),
        'from_name'    => env('MAIL_FROM_NAME', 'SyncCU'),
    ],

    'database' => [
        'driver'   => env('DB_DRIVER', 'mysql'),
        'host'     => env('DB_HOST', '127.0.0.1'),
        'port'     => (int) env('DB_PORT', 3306),
        'database' => env('DB_DATABASE', 'synccu'),
        'username' => env('DB_USERNAME', 'synccu_user'),
        'password' => env('DB_PASSWORD', ''),
        'charset'  => env('DB_CHARSET', 'utf8mb4'),
        'options'  => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ],
    ],
];
