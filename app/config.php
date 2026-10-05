<?php
/**
 * Application configuration.
 * Environment variables (if set) override the defaults below, which makes
 * the same codebase deployable to staging / production without edits.
 */
defined('SACCO') or exit;

return [
    'app_name' => 'SaccoPro',
    'debug'    => (getenv('SACCO_DEBUG') ?: '1') === '1',
    'timezone' => getenv('SACCO_TZ') ?: 'Africa/Nairobi',

    'db' => [
        'host'    => getenv('SACCO_DB_HOST') ?: '127.0.0.1',
        'port'    => (int) (getenv('SACCO_DB_PORT') ?: 3306),
        'name'    => getenv('SACCO_DB_NAME') ?: 'sacco_pro',
        'user'    => getenv('SACCO_DB_USER') ?: 'root',
        'pass'    => getenv('SACCO_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],

    'session' => [
        'name'         => 'saccopro_sid',
        'idle_timeout' => 60 * 60 * 2,   // seconds of inactivity before logout
    ],

    'security' => [
        'max_failed_logins' => 5,
        'lockout_minutes'   => 15,
    ],
];
