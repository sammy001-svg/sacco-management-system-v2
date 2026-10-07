<?php
defined('SACCO') or exit;

/**
 * Compressed SQL backups of the whole database into storage/backups.
 *
 * Pure PHP (no mysqldump needed), streamed table by table with unbuffered
 * reads, so memory stays flat on big ledgers. Restore with:
 *   gunzip < file.sql.gz | mysql -u USER -p DATABASE
 */
final class BackupService
{
    public static function dir(): string
    {
        $dir = APP_ROOT . '/storage/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function run(?string $label = null): array
    {
        set_time_limit(0);
        $db = (string) config('db.name');
        $name = $db . '-' . date('Ymd-His') . ($label ? '-' . preg_replace('/[^a-z0-9]+/i', '-', $label) : '') . '.sql.gz';
        $path = self::dir() . '/' . $name;
        $tmp = $path . '.part';
        $gz = gzopen($tmp, 'wb6');
        if (!$gz) {
            throw new AppError('Cannot write to storage/backups. Check folder permissions.', 500);
        }
        $start = microtime(true);
        $pdo = DB::pdo();
        $w = fn(string $s) => gzwrite($gz, $s);

        $w("-- SaccoPro backup of `$db`\n-- Created " . date('c') . "\n-- Restore: gunzip < $name | mysql -u USER -p $db\n\n");
        $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tables = array_column(DB::all("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"), 'Tables_in_' . $db);
        $rowsTotal = 0;
        foreach ($tables as $table) {
            $create = DB::row("SHOW CREATE TABLE `$table`")['Create Table'];
            $w("-- ----------------------------------------\n-- $table\n-- ----------------------------------------\n");
            $w("DROP TABLE IF EXISTS `$table`;\n$create;\n\n");

            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $batch = [];
            $cols = null;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cols ??= '`' . implode('`,`', array_keys($row)) . '`';
                $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
                if (count($batch) === 500) {
                    $w("INSERT INTO `$table` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
                    $rowsTotal += 500;
                    $batch = [];
                }
            }
            $stmt->closeCursor();
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            if ($batch) {
                $w("INSERT INTO `$table` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
                $rowsTotal += count($batch);
            }
            $w("\n");
        }
        $w("SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n");
        gzclose($gz);
        rename($tmp, $path);

        $pruned = self::prune((int) setting('backup_keep', 14));
        return [
            'file' => $name, 'size' => filesize($path), 'tables' => count($tables), 'rows' => $rowsTotal,
            'seconds' => round(microtime(true) - $start, 1), 'pruned' => $pruned,
        ];
    }

    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.sql.gz') ?: [] as $f) {
            $out[] = ['file' => basename($f), 'size' => filesize($f), 'created_at' => date('Y-m-d H:i:s', filemtime($f))];
        }
        usort($out, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $out;
    }

    /** Resolve a backup file name safely (no path traversal). */
    public static function path(string $file): string
    {
        $file = basename($file);
        $path = self::dir() . '/' . $file;
        if (!preg_match('/^[A-Za-z0-9_\-]+\.sql\.gz$/', $file) || !is_file($path)) {
            throw new AppError('Backup not found.', 404);
        }
        return $path;
    }

    public static function prune(int $keep): int
    {
        if ($keep < 1) {
            return 0;
        }
        $n = 0;
        foreach (array_slice(self::list(), $keep) as $old) {
            @unlink(self::dir() . '/' . $old['file']) && $n++;
        }
        return $n;
    }
}
