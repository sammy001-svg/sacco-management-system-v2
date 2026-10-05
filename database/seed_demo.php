<?php
/**
 * CLI: generate demo data.  php database/seed_demo.php --members=500
 * Use a large number (e.g. 50000) to load-test a big SACCO.
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/DemoSeeder.php';

$opts = getopt('', ['members::']);
$count = max(1, (int) ($opts['members'] ?? 200));

if (!is_installed()) {
    fwrite(STDERR, "Run the installer first: php install.php --email=... --password=...\n");
    exit(1);
}

$result = (new DemoSeeder(fn($m) => print($m . PHP_EOL)))->run($count);
echo "Done: {$result['members']} members in {$result['seconds']}s\n";
