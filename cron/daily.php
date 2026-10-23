<?php
/**
 * Nightly maintenance. Schedule once a day (e.g. 00:05):
 *
 *   Linux cron:   5 0 * * *  php /path/to/sacco/cron/daily.php >> /path/to/sacco/storage/logs/cron.log 2>&1
 *   Windows:      schtasks /create /sc daily /st 00:05 /tn "SaccoPro daily" /tr "C:\xampp\php\php.exe D:\path\to\sacco\cron\daily.php"
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

$start = microtime(true);
$r = LoanService::runDailyJobs();
cache_flush();
$due = SmsService::dueReminders();
$arrears = SmsService::arrearsAlerts();
audit('cron', 'system', null, "Daily jobs: {$r['overdue_marked']} overdue, {$r['penalties_applied']} penalties (" . money($r['penalty_total']) . "), SMS: $due due reminders, $arrears arrears alerts");

printf(
    "[%s] overdue marked: %d · penalties: %d (%s) · SMS reminders: %d due, %d arrears · %.2fs\n",
    date('Y-m-d H:i:s'),
    $r['overdue_marked'],
    $r['penalties_applied'],
    money($r['penalty_total']),
    $due,
    $arrears,
    microtime(true) - $start
);
