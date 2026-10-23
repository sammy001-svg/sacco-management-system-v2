<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('SaccoPro needs PHP 8.1 or newer (this server runs ' . PHP_VERSION . '). In cPanel open "Select PHP Version" or "MultiPHP Manager" and choose PHP 8.1+.');
}

define('SACCO', true);
define('APP_PATH', __DIR__);
define('APP_ROOT', dirname(__DIR__));
define('APP_DB_VERSION', 5); // bump with every schema change; see database/Migrator.php

require APP_PATH . '/core/Env.php';
Env::load(APP_ROOT . '/.env');
$GLOBALS['config'] = require APP_PATH . '/config.php';

date_default_timezone_set($GLOBALS['config']['timezone']);
error_reporting(E_ALL);
ini_set('display_errors', $GLOBALS['config']['debug'] ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');

require APP_PATH . '/core/helpers.php';
require APP_PATH . '/core/DB.php';
require APP_PATH . '/core/Auth.php';
require APP_PATH . '/core/Validator.php';
require APP_PATH . '/core/Http.php';
require APP_PATH . '/services/Accounting.php';
require APP_PATH . '/services/Ledger.php';
require APP_PATH . '/services/MemberService.php';
require APP_PATH . '/services/SavingsService.php';
require APP_PATH . '/services/ShareService.php';
require APP_PATH . '/services/LoanService.php';
require APP_PATH . '/services/DistributionService.php';
require APP_PATH . '/services/TillService.php';
require APP_PATH . '/services/VaultService.php';
require APP_PATH . '/services/SmsService.php';
require APP_PATH . '/services/MpesaService.php';
require APP_PATH . '/services/ApprovalService.php';
require APP_PATH . '/services/ImportService.php';
require APP_PATH . '/services/BackupService.php';
require APP_PATH . '/core/Totp.php';

/** HTTPS detection that also works behind cPanel proxies and Cloudflare. */
function request_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? null) == 443
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || str_contains($_SERVER['HTTP_CF_VISITOR'] ?? '', 'https');
}

if (PHP_SAPI !== 'cli' && $GLOBALS['config']['force_https'] && !request_is_https()) {
    header('Location: https://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}

// Machine-to-machine endpoints (the M-Pesa callback) define SACCO_NO_SESSION.
if (PHP_SAPI !== 'cli' && !defined('SACCO_NO_SESSION') && session_status() !== PHP_SESSION_ACTIVE) {
    $secure = request_is_https();
    session_name($GLOBALS['config']['session']['name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if ($secure) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
