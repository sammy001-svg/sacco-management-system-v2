<?php
/**
 * CLI: upgrade the database to the current version (safe to re-run).
 *   php database/migrate.php
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/Migrator.php';

$start = microtime(true);
Migrator::run(fn($m) => print($m . PHP_EOL));
printf("Done in %.1fs\n", microtime(true) - $start);
