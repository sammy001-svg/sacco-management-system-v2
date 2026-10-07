<?php
/**
 * One-time installer (web or CLI). Creates the database, tables, reference
 * data and the first administrator. Refuses to run once a user exists.
 *
 *   CLI: php install.php --name="System Admin" --email=admin@sacco.local --password=Secret@123 [--sacco="My SACCO"] [--demo=200]
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/database/DemoSeeder.php';
require __DIR__ . '/database/Migrator.php';

function install_run(array $in, ?callable $log = null): void
{
    $log ??= fn($m) => null;
    $c = config('db');

    $server = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$c['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $log("Database `{$c['name']}` ready.");

    // Schema, chart of accounts and version stamp.
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
    install_run($in, fn($m) => print($m . PHP_EOL));
    exit("Installation complete.\n");
}

/* ------------------------------- Web ------------------------------- */
$installed = is_installed();
$errors = [];
$fatal = null;
$in = ['name' => '', 'email' => '', 'password' => '', 'sacco' => 'Demo SACCO Ltd', 'demo' => '1'];

if (!$installed && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        csrf_verify();
        $in = array_merge($in, array_map(fn($v) => is_string($v) ? trim($v) : $v, $_POST));
        $in['password'] = (string) ($_POST['password'] ?? '');
        $errors = install_validate($in);
        if (!$errors) {
            set_time_limit(0);
            $in['demo'] = !empty($_POST['demo']) ? 120 : 0;
            install_run($in);
            $installed = true;
            $justInstalled = true;
        }
    } catch (Throwable $e) {
        $fatal = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Install · SaccoPro</title>
  <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
  <script>try{var t=localStorage.getItem('sacco-theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>">
</head>
<body class="auth-body">
  <div class="auth">
    <section class="auth-hero">
      <div class="auth-hero-inner">
        <div class="brand brand-lg"><div class="brand-logo"><i class="bi bi-bank2"></i></div><div class="brand-text"><strong>SaccoPro</strong><span>Setup wizard</span></div></div>
        <h1>Let's set up your<br><span class="text-accent">SACCO workspace.</span></h1>
        <p class="auth-lead">This creates the <code><?= e(config('db.name')) ?></code> database on <code><?= e(config('db.host')) ?></code> and your administrator account. Edit <code>app/config.php</code> to change the connection.</p>
      </div>
    </section>
    <section class="auth-panel">
      <?php if ($installed): ?>
        <div class="auth-card">
          <div class="auth-card-head">
            <span class="eyebrow"><?= !empty($justInstalled) ? 'All done' : 'Already installed' ?></span>
            <h2><?= !empty($justInstalled) ? 'Your SACCO is ready 🎉' : 'SaccoPro is installed' ?></h2>
            <p class="muted">Sign in with your administrator account to continue.<?= !empty($justInstalled) && !empty($in['demo']) ? ' Demo staff accounts use the password <strong>Password@123</strong>.' : '' ?></p>
          </div>
          <a class="btn btn-primary btn-lg btn-block" href="login.php"><span>Go to sign in</span><i class="bi bi-arrow-right"></i></a>
        </div>
      <?php else: ?>
        <form class="auth-card" method="post" novalidate>
          <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
          <div class="auth-card-head">
            <span class="eyebrow">Installation</span>
            <h2>Create the administrator</h2>
          </div>
          <?php if ($fatal): ?><div class="alert alert-danger"><strong>Installation failed:</strong> <?= e($fatal) ?></div><?php endif; ?>
          <div class="field"><label>SACCO name</label><input class="input" name="sacco" value="<?= e($in['sacco']) ?>" required></div>
          <div class="field"><label>Your full name</label><input class="input <?= isset($errors['name']) ? 'is-invalid' : '' ?>" name="name" value="<?= e($in['name']) ?>" required><div class="field-error"><?= e($errors['name'] ?? '') ?></div></div>
          <div class="field"><label>Email</label><input class="input <?= isset($errors['email']) ? 'is-invalid' : '' ?>" type="email" name="email" value="<?= e($in['email']) ?>" required><div class="field-error"><?= e($errors['email'] ?? '') ?></div></div>
          <div class="field"><label>Password</label><input class="input <?= isset($errors['password']) ? 'is-invalid' : '' ?>" type="password" name="password" required><div class="field-error"><?= e($errors['password'] ?? '') ?></div></div>
          <label class="check"><input type="checkbox" name="demo" value="1" <?= !empty($in['demo']) ? 'checked' : '' ?>><span>Load demo data (120 members with 18 months of history)</span></label>
          <button class="btn btn-primary btn-lg btn-block" type="submit" onclick="this.classList.add('loading')"><span>Install SaccoPro</span><i class="bi bi-rocket-takeoff"></i></button>
        </form>
      <?php endif; ?>
    </section>
  </div>
</body>
</html>
