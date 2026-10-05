<?php
require __DIR__ . '/app/bootstrap.php';

if (!is_installed()) {
    redirect('install.php');
}
if (Auth::check()) {
    redirect('index.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        csrf_verify();
        $email = (string) input('email', '');
        $password = (string) ($_POST['password'] ?? '');
        if ($email === '' || $password === '') {
            throw new ValidationError(array_filter([
                'email'    => $email === '' ? 'Email is required.' : null,
                'password' => $password === '' ? 'Password is required.' : null,
            ]));
        }
        $user = Auth::attempt($email, $password);
        Auth::login($user);
        audit('login', 'user', $user['id'], 'Signed in');
        json_out(['ok' => true, 'redirect' => 'index.php']);
    } catch (ValidationError $e) {
        json_out(['ok' => false, 'message' => $e->getMessage(), 'errors' => $e->errors], 422);
    } catch (AppError $e) {
        json_out(['ok' => false, 'message' => $e->getMessage()], $e->status);
    }
}

$saccoName = setting('sacco_name', 'SACCO');
$tagline = setting('sacco_tagline', 'Saving together, growing together');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <title>Sign in · <?= e($saccoName) ?></title>
  <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
  <script>try{var t=localStorage.getItem('sacco-theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=<?= filemtime(__DIR__ . '/assets/css/app.css') ?>">
</head>
<body class="auth-body">
  <div class="auth">
    <section class="auth-hero">
      <div class="auth-hero-inner">
        <div class="brand brand-lg">
          <div class="brand-logo"><i class="bi bi-bank2"></i></div>
          <div class="brand-text"><strong><?= e(config('app_name')) ?></strong><span><?= e($saccoName) ?></span></div>
        </div>
        <h1>Banking-grade management<br>for <span class="text-accent">modern SACCOs.</span></h1>
        <p class="auth-lead"><?= e($tagline) ?>. Members, savings, shares and loans in one secure place.</p>
        <ul class="auth-features">
          <li><i class="bi bi-shield-lock"></i><div><strong>Bank-level security</strong><span>Role-based access, audit trail and locked ledger.</span></div></li>
          <li><i class="bi bi-lightning-charge"></i><div><strong>Built for scale</strong><span>Fast with hundreds of thousands of members.</span></div></li>
          <li><i class="bi bi-graph-up-arrow"></i><div><strong>Live insights</strong><span>Portfolio at risk, cash flow and growth at a glance.</span></div></li>
        </ul>
      </div>
    </section>

    <section class="auth-panel">
      <button class="icon-btn auth-theme" type="button" data-theme-toggle aria-label="Toggle dark mode"><i class="bi bi-moon-stars"></i></button>
      <form class="auth-card" id="loginForm" novalidate>
        <div class="auth-card-head">
          <span class="eyebrow">Welcome back</span>
          <h2>Sign in to your account</h2>
          <p class="muted">Use the credentials issued by your SACCO administrator.</p>
        </div>
        <div class="alert alert-danger" id="loginError" hidden></div>
        <div class="field">
          <label for="email">Email address</label>
          <div class="input-icon"><i class="bi bi-envelope"></i>
            <input class="input" type="email" id="email" name="email" placeholder="you@sacco.co.ke" autocomplete="username" required autofocus>
          </div>
          <div class="field-error" data-error-for="email"></div>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <div class="input-icon"><i class="bi bi-lock"></i>
            <input class="input" type="password" id="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
            <button type="button" class="input-action" data-toggle-password aria-label="Show password"><i class="bi bi-eye"></i></button>
          </div>
          <div class="field-error" data-error-for="password"></div>
        </div>
        <button class="btn btn-primary btn-lg btn-block" type="submit"><span>Sign in</span><i class="bi bi-arrow-right"></i></button>
        <p class="auth-foot muted"><i class="bi bi-patch-check-fill text-green"></i> Secured with encrypted sessions &amp; CSRF protection</p>
      </form>
    </section>
  </div>

  <script>
  (function () {
    const form = document.getElementById('loginForm');
    const err = document.getElementById('loginError');
    const token = document.querySelector('meta[name="csrf-token"]').content;

    document.querySelectorAll('[data-theme-toggle]').forEach(b => b.addEventListener('click', () => {
      const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
      document.documentElement.dataset.theme = next;
      try { localStorage.setItem('sacco-theme', next); } catch (e) {}
    }));
    document.querySelector('[data-toggle-password]').addEventListener('click', e => {
      const input = document.getElementById('password');
      input.type = input.type === 'password' ? 'text' : 'password';
      e.currentTarget.innerHTML = input.type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
    });

    form.addEventListener('submit', async e => {
      e.preventDefault();
      err.hidden = true;
      form.querySelectorAll('.field-error').forEach(el => el.textContent = '');
      form.querySelectorAll('.input').forEach(el => el.classList.remove('is-invalid'));
      const btn = form.querySelector('button[type=submit]');
      btn.disabled = true; btn.classList.add('loading');
      try {
        const res = await fetch('login.php', { method: 'POST', body: new FormData(form), headers: { 'X-CSRF-Token': token, 'Accept': 'application/json' } });
        const data = await res.json();
        if (data.ok) { window.location.href = data.redirect; return; }
        if (data.errors) Object.entries(data.errors).forEach(([k, v]) => {
          const el = form.querySelector(`[data-error-for="${k}"]`); if (el) el.textContent = v;
          const inp = form.querySelector(`[name="${k}"]`); if (inp) inp.classList.add('is-invalid');
        });
        err.textContent = data.message || 'Unable to sign in.'; err.hidden = false;
      } catch (ex) {
        err.textContent = 'Network error. Please try again.'; err.hidden = false;
      } finally {
        btn.disabled = false; btn.classList.remove('loading');
      }
    });
  })();
  </script>
</body>
</html>
