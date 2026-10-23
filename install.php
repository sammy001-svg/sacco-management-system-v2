<?php
/**
 * One-time installer (web or CLI).
 *
 *  Web (cPanel): open https://your-domain/install.php
 *    1. Server check   2. Database connection (writes .env)   3. Administrator
 *  CLI:  php install.php --name="System Admin" --email=admin@sacco.local --password=Secret@123 [--sacco="My SACCO"] [--demo=200]
 *
 * Refuses to run once a user exists, or when INSTALLER_ENABLED=false in .env.
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/database/DemoSeeder.php';
require __DIR__ . '/database/Migrator.php';

const ENV_FILE = __DIR__ . '/.env';

if (!config('install.enabled')) {
    http_response_code(403);
    exit(PHP_SAPI === 'cli' ? "Installer disabled (INSTALLER_ENABLED=false).\n" : 'The installer is disabled.');
}

/** Can we reach the configured database? Returns null or an error message. */
function install_db_error(): ?string
{
    try {
        DB::pdo()->query('SELECT 1');
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

function install_run(array $in, ?callable $log = null): void
{
    $log ??= fn($m) => null;
    $c = config('db');
    system_process(true); // installing: teller-till and approval controls do not apply
    SmsService::mute();

    // On cPanel the database already exists (created in MySQL® Databases) and the
    // user usually may not create databases, so only try when it is missing.
    try {
        DB::pdo();
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) !== 1049 && !str_contains($e->getMessage(), 'Unknown database')) {
            throw $e;
        }
        $server = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$c['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    $log("Database `{$c['name']}` ready.");

    Migrator::run($log);
    $log('Tables and reference data created.');

    if (!empty($in['sacco'])) {
        DB::query("UPDATE settings SET `value` = ? WHERE `key` = 'sacco_name'", [$in['sacco']]);
    }
    $id = DB::insert('users', [
        'name'          => $in['name'],
        'email'         => strtolower($in['email']),
        'password_hash' => password_hash($in['password'], PASSWORD_DEFAULT),
        'role'          => 'admin',
        'branch_id'     => null,
    ]);
    Auth::actAs($id);
    audit('install', 'system', null, 'System installed');
    $log("Administrator {$in['email']} created.");

    if (($n = (int) ($in['demo'] ?? 0)) > 0) {
        settings_flush();
        (new DemoSeeder($log))->run($n);
    }
}

/**
 * Undo a failed installation so it can simply be run again: drops only the
 * SaccoPro tables (names read from schema.sql), never anything else in the database.
 */
function install_rollback(): void
{
    try {
        preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?(\w+)`?/i', (string) file_get_contents(__DIR__ . '/database/schema.sql'), $m);
        $pdo = DB::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($m[1] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `$table`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $e) {
        error_log('[install] rollback failed: ' . $e->getMessage());
    }
}

/** Install, or roll everything back and rethrow so the installer can be re-run. */
function install_safely(array $in, ?callable $log = null): void
{
    try {
        install_run($in, $log);
    } catch (Throwable $e) {
        install_rollback();
        throw $e;
    }
}

function install_validate(array $in): array
{
    $errors = [];
    if (trim($in['name'] ?? '') === '') {
        $errors['name'] = 'Your name is required.';
    }
    if (!filter_var($in['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    $pw = (string) ($in['password'] ?? '');
    if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        $errors['password'] = 'Use at least 8 characters with letters and numbers.';
    }
    return $errors;
}

/** Server requirements: [label, ok, hint]. */
function install_checks(): array
{
    $writable = fn(string $p) => is_dir($p) ? is_writable($p) : @mkdir($p, 0775, true);
    return [
        ['PHP 8.1 or newer (running ' . PHP_VERSION . ')', PHP_VERSION_ID >= 80100, 'cPanel → Select PHP Version'],
        ['PDO MySQL extension', extension_loaded('pdo_mysql'), 'Enable pdo_mysql in Select PHP Version → Extensions'],
        ['mbstring extension', extension_loaded('mbstring'), 'Enable mbstring'],
        ['fileinfo extension (document uploads)', extension_loaded('fileinfo'), 'Enable fileinfo'],
        ['zlib extension (backups)', extension_loaded('zlib'), 'Enable zlib'],
        ['openssl extension', extension_loaded('openssl'), 'Enable openssl'],
        ['storage/ is writable', $writable(__DIR__ . '/storage') && $writable(__DIR__ . '/storage/logs') && $writable(__DIR__ . '/storage/cache')
            && $writable(__DIR__ . '/storage/uploads') && $writable(__DIR__ . '/storage/backups'), 'Set storage/ and its folders to 755 in File Manager'],
        ['.env can be written', is_file(ENV_FILE) ? is_writable(ENV_FILE) : is_writable(__DIR__), 'Otherwise you will paste .env by hand'],
    ];
}

function env_quote(string $v): string
{
    return preg_match('/[\s#"\'\\\\=]/', $v) || $v === '' ? '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"' : $v;
}

/** Fill .env.example (or a minimal template) with the given values. */
function env_build(array $values): string
{
    $tpl = is_file(__DIR__ . '/.env.example') ? (string) file_get_contents(__DIR__ . '/.env.example') : '';
    foreach ($values as $k => $v) {
        $line = $k . '=' . env_quote((string) $v);
        $tpl = preg_match("/^$k=.*$/m", $tpl) ? preg_replace("/^$k=.*$/m", str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $tpl) : rtrim($tpl) . "\n$line\n";
    }
    return $tpl;
}

/* ------------------------------- CLI ------------------------------- */
if (PHP_SAPI === 'cli') {
    if (is_installed()) {
        exit("Already installed.\n");
    }
    $o = getopt('', ['name::', 'email:', 'password:', 'sacco::', 'demo::']);
    $in = ['name' => $o['name'] ?? 'System Administrator', 'email' => $o['email'] ?? '', 'password' => $o['password'] ?? '', 'sacco' => $o['sacco'] ?? '', 'demo' => $o['demo'] ?? 0];
    if ($errors = install_validate($in)) {
        fwrite(STDERR, implode("\n", $errors) . "\n");
        exit(1);
    }
    install_safely($in, fn($m) => print($m . PHP_EOL));
    exit("Installation complete.\n");
}

/* ------------------------------- Web ------------------------------- */
$installed = is_installed();
$errors = [];
$fatal = null;
$envOut = null;
$checks = install_checks();
$checksOk = !array_filter(array_slice($checks, 0, 7), fn($c) => !$c[1]);
$dbError = $installed ? null : install_db_error();
$https = request_is_https();
$guessUrl = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$db = [
    'DB_HOST' => config('db.host'), 'DB_PORT' => (string) config('db.port'), 'DB_DATABASE' => config('db.name'),
    'DB_USERNAME' => config('db.user'), 'DB_PASSWORD' => '', 'APP_URL' => config('url') ?: $guessUrl, 'APP_TIMEZONE' => config('timezone'),
];
$in = ['name' => '', 'email' => '', 'password' => '', 'sacco' => 'My SACCO Ltd', 'demo' => ''];
$step = $installed ? 'done' : ($dbError ? 'database' : 'admin');

if (!$installed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        csrf_verify();
        if (($_POST['step'] ?? '') === 'database') {
            $db = array_merge($db, array_map('trim', array_intersect_key($_POST, $db)));
            $db['DB_PASSWORD'] = (string) ($_POST['DB_PASSWORD'] ?? '');
            try {
                $pdo = new PDO("mysql:host={$db['DB_HOST']};port={$db['DB_PORT']};dbname={$db['DB_DATABASE']};charset=utf8mb4",
                    $db['DB_USERNAME'], $db['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
                $pdo->query('SELECT 1');
            } catch (PDOException $e) {
                throw new AppError('Could not connect: ' . $e->getMessage() . ' — check the names include your cPanel prefix and that the user was added to the database with ALL PRIVILEGES.');
            }
            $content = env_build($db + [
                'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'FORCE_HTTPS' => str_starts_with($db['APP_URL'], 'https://') && $https ? 'true' : 'false',
                'INSTALLER_ENABLED' => 'true', 'MPESA_CALLBACK_KEY' => bin2hex(random_bytes(24)),
            ]);
            if (@file_put_contents(ENV_FILE, $content, LOCK_EX) !== false) {
                @chmod(ENV_FILE, 0600);
                header('Location: install.php');
                exit;
            }
            $envOut = $content; // not writable: show it to paste by hand
            $step = 'database';
        } else {
            $step = 'admin';
            $in = array_merge($in, array_map(fn($v) => is_string($v) ? trim($v) : $v, $_POST));
            $in['password'] = (string) ($_POST['password'] ?? '');
            $errors = install_validate($in);
            if (!$errors) {
                set_time_limit(0);
                $in['demo'] = !empty($_POST['demo']) ? 120 : 0;
                install_safely($in);
                $installed = true;
                $step = 'done';
                $justInstalled = true;
            }
        }
    } catch (Throwable $e) {
        $fatal = $e->getMessage();
    }
}
$steps = ['database' => 'Database', 'admin' => 'Administrator', 'done' => 'Finish'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>Install · SaccoPro</title>
  <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
  <script>try{var t=localStorage.getItem('sacco-theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>">
  <style>.auth-card{max-width:520px}.checks{list-style:none;padding:0;margin:0 0 18px;display:grid;gap:6px;font-size:13px}.checks li{display:flex;gap:8px;align-items:flex-start}.checks small{display:block;color:var(--muted)}</style>
</head>
<body class="auth-body">
  <div class="auth">
    <section class="auth-hero">
      <div class="auth-hero-inner">
        <div class="brand brand-lg"><div class="brand-logo"><i class="bi bi-bank2"></i></div><div class="brand-text"><strong>SaccoPro</strong><span>Setup wizard</span></div></div>
        <h1>Let's set up your<br><span class="text-accent">SACCO workspace.</span></h1>
        <p class="auth-lead">Before you start, create a MySQL database and user in <strong>cPanel → MySQL® Databases</strong> and add the user to the database with <em>All privileges</em>.</p>
        <ul class="auth-features">
          <?php $i = 1; foreach ($steps as $k => $label): $doneStep = array_search($k, array_keys($steps)) < array_search($step, array_keys($steps)); ?>
            <li><i class="bi bi-<?= $doneStep ? 'check2-circle' : ($k === $step ? 'arrow-right-circle' : 'circle') ?>"></i><div><strong><?= $i++ ?>. <?= e($label) ?></strong><span><?= $doneStep ? 'Done' : ($k === $step ? 'In progress' : 'Next') ?></span></div></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>
    <section class="auth-panel">
      <?php if ($step === 'done'): ?>
        <div class="auth-card">
          <div class="auth-card-head">
            <span class="eyebrow"><?= !empty($justInstalled) ? 'All done' : 'Already installed' ?></span>
            <h2><?= !empty($justInstalled) ? 'Your SACCO is ready 🎉' : 'SaccoPro is installed' ?></h2>
            <p class="muted">Sign in with your administrator account to continue.<?= !empty($justInstalled) && !empty($in['demo']) ? ' Demo staff accounts use the password <strong>Password@123</strong>.' : '' ?></p>
          </div>
          <div class="info-strip warning"><i class="bi bi-shield-lock"></i><span>For security, set <code>INSTALLER_ENABLED=false</code> in <code>.env</code> (or delete <code>install.php</code>).</span></div>
          <a class="btn btn-primary btn-lg btn-block" href="login.php"><span>Go to sign in</span><i class="bi bi-arrow-right"></i></a>
        </div>

      <?php elseif ($step === 'database'): ?>
        <form class="auth-card" method="post" novalidate>
          <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="step" value="database">
          <div class="auth-card-head"><span class="eyebrow">Step 1 of 2</span><h2>Server &amp; database</h2></div>
          <ul class="checks">
            <?php foreach ($checks as [$label, $ok, $hint]): ?>
              <li><i class="bi bi-<?= $ok ? 'check-circle-fill text-green' : 'x-circle-fill text-red' ?>"></i><span><?= e($label) ?><?= $ok ? '' : '<small>' . e($hint) . '</small>' ?></span></li>
            <?php endforeach; ?>
          </ul>
          <?php if (!$checksOk): ?><div class="alert alert-danger">Fix the items marked in red, then reload this page.</div><?php endif; ?>
          <?php if ($fatal): ?><div class="alert alert-danger"><?= e($fatal) ?></div><?php endif; ?>
          <?php if ($envOut !== null): ?>
            <div class="alert alert-danger">Connection works, but <code>.env</code> could not be written. Create a file named <code>.env</code> next to <code>install.php</code> (File Manager → + File) with this content, then reload:</div>
            <textarea class="textarea mb-2" rows="10" readonly onclick="this.select()" style="font-family:monospace;font-size:12px"><?= e($envOut) ?></textarea>
          <?php endif; ?>
          <div class="form-grid">
            <div class="field"><label>Database host</label><input class="input" name="DB_HOST" value="<?= e($db['DB_HOST']) ?>" required></div>
            <div class="field"><label>Port</label><input class="input" name="DB_PORT" value="<?= e($db['DB_PORT']) ?>" required></div>
            <div class="field span-2"><label>Database name</label><input class="input" name="DB_DATABASE" value="<?= e($db['DB_DATABASE']) ?>" placeholder="cpuser_sacco" required></div>
            <div class="field"><label>Database user</label><input class="input" name="DB_USERNAME" value="<?= e($db['DB_USERNAME']) ?>" placeholder="cpuser_admin" required></div>
            <div class="field"><label>Password</label><input class="input" type="password" name="DB_PASSWORD" autocomplete="new-password"></div>
            <div class="field span-2"><label>Site address (APP_URL)</label><input class="input" name="APP_URL" value="<?= e($db['APP_URL']) ?>" required></div>
            <div class="field span-2"><label>Timezone</label><input class="input" name="APP_TIMEZONE" value="<?= e($db['APP_TIMEZONE']) ?>" required></div>
          </div>
          <button class="btn btn-primary btn-lg btn-block" type="submit" <?= $checksOk ? '' : 'disabled' ?> onclick="this.classList.add('loading')"><span>Test &amp; save connection</span><i class="bi bi-plug"></i></button>
        </form>

      <?php else: ?>
        <form class="auth-card" method="post" novalidate>
          <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="step" value="admin">
          <div class="auth-card-head"><span class="eyebrow">Step 2 of 2 · connected to <?= e(config('db.name')) ?></span><h2>Create the administrator</h2></div>
          <?php if ($fatal): ?><div class="alert alert-danger"><strong>Installation failed:</strong> <?= e($fatal) ?><br><small>Nothing was kept — fix the problem and press Install again.</small></div><?php endif; ?>
          <div class="field"><label>SACCO name</label><input class="input" name="sacco" value="<?= e($in['sacco']) ?>" required></div>
          <div class="field"><label>Your full name</label><input class="input <?= isset($errors['name']) ? 'is-invalid' : '' ?>" name="name" value="<?= e($in['name']) ?>" required><div class="field-error"><?= e($errors['name'] ?? '') ?></div></div>
          <div class="field"><label>Email</label><input class="input <?= isset($errors['email']) ? 'is-invalid' : '' ?>" type="email" name="email" value="<?= e($in['email']) ?>" required><div class="field-error"><?= e($errors['email'] ?? '') ?></div></div>
          <div class="field"><label>Password</label><input class="input <?= isset($errors['password']) ? 'is-invalid' : '' ?>" type="password" name="password" required autocomplete="new-password"><div class="field-error"><?= e($errors['password'] ?? '') ?></div></div>
          <label class="check"><input type="checkbox" name="demo" value="1" <?= !empty($in['demo']) ? 'checked' : '' ?>><span>Load demo data (120 sample members) — leave unticked for a live SACCO</span></label>
          <button class="btn btn-primary btn-lg btn-block" type="submit" onclick="this.classList.add('loading')"><span>Install SaccoPro</span><i class="bi bi-rocket-takeoff"></i></button>
        </form>
      <?php endif; ?>
    </section>
  </div>
</body>
</html>
