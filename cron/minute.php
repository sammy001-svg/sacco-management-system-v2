<?php
/**
 * Every-minute worker: sends queued SMS, collects delivery reports and
 * settles M-Pesa prompts whose callback never arrived.
 *
 *   cPanel cron:  * * * * *  php /home/USER/sacco/cron/minute.php >> /home/USER/sacco/storage/logs/cron.log 2>&1
 *   Windows:      schtasks /create /sc minute /mo 1 /tn "SaccoPro minute" /tr "C:\xampp\php\php.exe D:\path\to\sacco\cron\minute.php"
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

// One worker at a time, even if a run takes longer than a minute.
$lock = fopen(APP_ROOT . '/storage/cache/minute.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit;
}

$start = microtime(true);
$out = [];
try {
    $s = SmsService::dispatch();
    if ($s['sent'] || $s['failed'] || $s['retry']) {
        $out[] = "sms sent {$s['sent']}, failed {$s['failed']}, retry {$s['retry']}";
    }
    if ((int) date('i') % 5 === 0 && ($n = SmsService::refreshDelivery())) {
        $out[] = "delivery reports $n";
    }
} catch (Throwable $e) {
    $out[] = 'sms error: ' . $e->getMessage();
}
try {
    if ($n = MpesaService::reconcileStale()) {
        $out[] = "mpesa checked $n";
    }
} catch (Throwable $e) {
    $out[] = 'mpesa error: ' . $e->getMessage();
}

DB::query("INSERT INTO settings (`key`, `value`) VALUES ('cron_minute_at', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [now()]);
if ($out) {
    printf("[%s] %s · %.1fs\n", date('Y-m-d H:i:s'), implode(' · ', $out), microtime(true) - $start);
}
flock($lock, LOCK_UN);
