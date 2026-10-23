<?php
/**
 * Application configuration — every value comes from the .env file in the
 * project root (copy .env.example to .env), with safe defaults.
 * Real server environment variables override the file.
 */
defined('SACCO') or exit;

return [
    'app_name' => Env::get('APP_NAME', 'SaccoPro'),
    'env'      => Env::get('APP_ENV', 'production'),
    'debug'    => Env::bool('APP_DEBUG', false),
    'url'      => rtrim((string) Env::get('APP_URL', ''), '/'),
    'timezone' => Env::get('APP_TIMEZONE', 'Africa/Nairobi'),
    'force_https' => Env::bool('FORCE_HTTPS', false),

    'db' => [
        'host'    => Env::get('DB_HOST', 'localhost'),
        'port'    => (int) Env::get('DB_PORT', 3306),
        'name'    => Env::get('DB_DATABASE', 'sacco_pro'),
        'user'    => Env::get('DB_USERNAME', 'root'),
        'pass'    => (string) Env::get('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        // Pinned so behaviour is identical on MySQL 5.7/8 and MariaDB hosts.
        'sql_mode' => Env::get('DB_SQL_MODE', 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'),
    ],

    'session' => [
        'name'         => Env::get('SESSION_NAME', 'saccopro_sid'),
        'idle_timeout' => (int) Env::get('SESSION_IDLE_MINUTES', 120) * 60,
    ],

    'security' => [
        'max_failed_logins' => (int) Env::get('MAX_FAILED_LOGINS', 5),
        'lockout_minutes'   => (int) Env::get('LOCKOUT_MINUTES', 15),
    ],

    // Safaricom Daraja — Lipa na M-Pesa Online (STK push).
    'mpesa' => [
        'env'             => Env::get('MPESA_ENV', 'sandbox') === 'live' ? 'live' : 'sandbox',
        'base_url'        => rtrim((string) Env::get('MPESA_BASE_URL', ''), '/'),
        'consumer_key'    => (string) Env::get('MPESA_CONSUMER_KEY', ''),
        'consumer_secret' => (string) Env::get('MPESA_CONSUMER_SECRET', ''),
        'type'            => Env::get('MPESA_TYPE', 'paybill') === 'till' ? 'till' : 'paybill',
        'shortcode'       => (string) Env::get('MPESA_SHORTCODE', ''),
        'till_number'     => (string) Env::get('MPESA_TILL_NUMBER', ''),
        'passkey'         => (string) Env::get('MPESA_PASSKEY', ''),
        'callback_key'    => (string) Env::get('MPESA_CALLBACK_KEY', ''),
        'callback_url'    => (string) Env::get('MPESA_CALLBACK_URL', ''),
        'allowed_ips'     => (string) Env::get('MPESA_ALLOWED_IPS', ''),
    ],

    // Shanfix SMS (https://sms.shanfixtechnology.com → Client → API).
    'sms' => [
        'base_url'  => rtrim((string) Env::get('SMS_BASE_URL', 'https://sms.shanfixtechnology.com'), '/'),
        'client_id' => (string) Env::get('SMS_CLIENT_ID', ''),
        'api_key'   => (string) Env::get('SMS_API_KEY', ''),
        'sender_id' => (string) Env::get('SMS_SENDER_ID', ''),
    ],

    'install' => [
        // Set to false after installation to make install.php refuse to run at all.
        'enabled' => Env::bool('INSTALLER_ENABLED', true),
    ],
];
