<?php
defined('SACCO') or exit;

return [

    'list' => function () {
        Auth::authorize('backups.manage');
        $files = BackupService::list();
        return ['data' => $files, 'keep' => (int) setting('backup_keep', 14), 'total_size' => array_sum(array_column($files, 'size'))];
    },

    'run' => function () {
        post_only();
        Auth::authorize('backups.manage');
        $r = BackupService::run('manual');
        audit('backup', 'system', null, "Backup {$r['file']} ({$r['rows']} rows, " . round($r['size'] / 1048576, 2) . ' MB)');
        return ['message' => "Backup created: {$r['file']} — " . number_format($r['rows']) . " rows in {$r['seconds']}s." . ($r['pruned'] ? " {$r['pruned']} old backup(s) removed." : ''), 'data' => $r];
    },

    'download' => function () {
        Auth::authorize('backups.manage');
        $path = BackupService::path((string) input('file'));
        audit('backup_download', 'system', null, 'Downloaded ' . basename($path));
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/gzip');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        readfile($path);
        exit;
    },

    'delete' => function () {
        post_only();
        Auth::authorize('backups.manage');
        $path = BackupService::path((string) input('file'));
        unlink($path);
        audit('backup_delete', 'system', null, 'Deleted ' . basename($path));
        return ['message' => 'Backup deleted.'];
    },
];
