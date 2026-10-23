<?php
/**
 * Package SaccoPro for upload to cPanel.
 *
 *   php deploy/build.php
 *
 * Creates dist/saccopro-YYYYMMDD-HHMM.zip containing everything the server
 * needs and nothing it must not have: no .env (create it on the server or
 * let install.php write it), no .git, no logs, uploads, cache or backups.
 * Upload the zip in cPanel → File Manager and choose "Extract".
 */
if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "The PHP zip extension is required to build the package.\n");
    exit(1);
}

$root = dirname(__DIR__);
$excludeDirs = ['.git', 'dist', 'deploy', '.vscode', '.idea', 'node_modules'];
$excludeFiles = ['.env', '.DS_Store', 'Thumbs.db'];
// Runtime folders ship empty, keeping only their protection files.
$runtime = ['storage/logs', 'storage/cache', 'storage/uploads', 'storage/backups'];

@mkdir("$root/dist", 0775, true);
$name = 'saccopro-' . date('Ymd-Hi') . '.zip';
$zipPath = "$root/dist/$name";
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create $zipPath\n");
    exit(1);
}

$count = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $file) {
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $top = explode('/', $rel)[0];
    if (in_array($top, $excludeDirs, true) || in_array(basename($rel), $excludeFiles, true)) {
        continue;
    }
    foreach ($runtime as $dir) {
        if (str_starts_with($rel, "$dir/") && !in_array(basename($rel), ['.gitkeep', '.htaccess'], true)) {
            continue 2;
        }
    }
    if ($file->isDir()) {
        $zip->addEmptyDir($rel);
    } else {
        $zip->addFile($file->getPathname(), $rel);
        $count++;
    }
}
// Make sure every runtime folder exists in the package.
foreach ($runtime as $dir) {
    $zip->addEmptyDir($dir);
    $zip->addFromString("$dir/.gitkeep", '');
}
$zip->close();

printf("Built dist/%s — %d files, %.2f MB\n", $name, $count, filesize($zipPath) / 1048576);
echo "Next: upload it to cPanel → File Manager, extract, then open https://your-domain/install.php\n";
