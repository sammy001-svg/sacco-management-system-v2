<?php
declare(strict_types=1);

define('SACCO', true);
define('APP_PATH', __DIR__);
define('APP_ROOT', dirname(__DIR__));

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
require APP_PATH . '/services/Ledger.php';
require APP_PATH . '/services/MemberService.php';
require APP_PATH . '/services/SavingsService.php';
require APP_PATH . '/services/ShareService.php';
require APP_PATH . '/services/LoanService.php';

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
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
}
