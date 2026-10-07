<?php
/**
 * Nightly database backup into storage/backups (keeps the newest N, see
 * Settings → Operations → backups to keep). Schedule after daily.php:
 *
 *   Linux cron:  30 1 * * *  php /path/to/sacco/cron/backup.php >> /path/to/sacco/storage/logs/cron.log 2>&1
 *   Windows:     schtasks /create /sc daily /st 01:30 /tn "SaccoPro backup" /tr "C:\xampp\php\php.exe D:\path\to\sacco\cron\backup.php"
 *
 * Copy storage/backups off the server regularly (another disk, cloud storage).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';

$r = BackupService::run();
audit('backup', 'system', null, "Scheduled backup {$r['file']}");
printf("[%s] %s · %d tables · %s rows · %.2f MB · %.1fs · pruned %d\n", date('Y-m-d H:i:s'), $r['file'], $r['tables'], number_format($r['rows']), $r['size'] / 1048576, $r['seconds'], $r['pruned']);
