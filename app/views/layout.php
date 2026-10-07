<?php
defined('SACCO') or exit;

$user = Auth::user();
$saccoName = (string) setting('sacco_name', 'SACCO');
$perms = array_values(array_filter(array_keys(permissions()), fn($p) => Auth::can($p)));
$roleLabel = Auth::roles()[$user['role']] ?? label($user['role']);
$pendingLoans = Auth::can('loans.approve')
    ? (int) DB::value("SELECT COUNT(*) FROM loans WHERE status = 'pending'" . ($user['branch_id'] ? ' AND branch_id = ' . (int) $user['branch_id'] : ''))
    : 0;

$pendingApprovals = Auth::can('approvals.decide') ? ApprovalService::pendingCount() : 0;
$till = Auth::can('tills.use') ? TillService::openFor((int) $user['id']) : null;

$nav = [
    'Overview'       => [['dashboard', 'Dashboard', 'grid-1x2', 'dashboard.view']],
    'Membership'     => [['members', 'Members', 'people', 'members.view']],
    'Finance'        => [
        ['savings', 'Savings', 'piggy-bank', 'savings.view'],
        ['shares', 'Share Capital', 'pie-chart', 'shares.view'],
        ['loans', 'Loans', 'cash-stack', 'loans.view', $pendingLoans],
        ['loan_products', 'Loan Products', 'box-seam', 'loans.view'],
        ['transactions', 'Transactions', 'arrow-left-right', 'transactions.view'],
        ['expenses', 'Expenses', 'wallet2', 'expenses.view'],
    ],
    'Operations'     => [
        ['tills', 'Teller Tills', 'safe2', 'tills.use'],
        ['approvals', 'Approvals', 'check2-square', 'dashboard.view', $pendingApprovals],
        ['imports', 'Imports', 'cloud-upload', 'imports.checkoff'],
    ],
    'Accounting'     => [
        ['statements', 'Financial Statements', 'file-earmark-bar-graph', 'accounting.view'],
        ['accounts', 'Chart of Accounts', 'journal-bookmark', 'accounting.view'],
        ['journals', 'Journal Entries', 'journal-text', 'accounting.view'],
        ['distributions', 'Interest & Dividends', 'percent', 'distributions.prepare'],
        ['year_end', 'Year-End Close', 'calendar-check', 'accounting.view'],
    ],
    'Insights'       => [['reports', 'Reports', 'bar-chart-line', 'reports.view']],
    'Administration' => [
        ['branches', 'Branches', 'buildings', 'branches.manage'],
        ['users', 'Users & Roles', 'person-gear', 'users.manage'],
        ['settings', 'Settings', 'sliders', 'settings.manage'],
        ['audit', 'Audit Trail', 'shield-check', 'audit.view'],
        ['backups', 'Backups', 'database-down', 'backups.manage'],
    ],
];

$quick = array_filter([
    Auth::can('members.manage')   ? ['memberModal', 'person-plus', 'New member'] : null,
    Auth::can('savings.transact') ? ['depositModal', 'arrow-down-left-circle', 'Receive deposit'] : null,
    Auth::can('savings.transact') ? ['withdrawModal', 'arrow-up-right-circle', 'Withdraw savings'] : null,
    Auth::can('shares.transact')  ? ['sharesModal', 'pie-chart', 'Buy shares'] : null,
    Auth::can('loans.apply')      ? ['loanApplyModal', 'file-earmark-plus', 'New loan application'] : null,
    Auth::can('loans.repay')      ? ['repayModal', 'cash-coin', 'Loan repayment'] : null,
]);

$appConfig = [
    'csrf'       => csrf_token(),
    'currency'   => currency(),
    'sacco'      => $saccoName,
    'sharePrice' => ShareService::price(),
    'regFee'     => (float) setting('registration_fee', 0),
    'perms'      => $perms,
    'user'       => ['id' => $user['id'], 'name' => $user['name'], 'role' => $user['role'], 'branch_id' => $user['branch_id']],
];
$asset = fn(string $p) => $p . '?v=' . @filemtime(APP_ROOT . '/' . $p);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta name="theme-color" content="#0a1f4d">
  <title><?= e($pageTitle) ?> · <?= e($saccoName) ?></title>
  <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
  <script>
    (function () {
      var t = null;
      try { t = localStorage.getItem('sacco-theme'); } catch (e) {}
      if (!t && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches) t = 'dark';
      document.documentElement.dataset.theme = t || 'light';
    })();
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= e($asset('assets/css/app.css')) ?>">
  <script>window.APP = <?= json_encode($appConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
</head>
<body>
<div class="app">
  <aside class="sidebar" aria-label="Main navigation">
    <div class="sidebar-head">
      <a class="brand" href="index.php">
        <div class="brand-logo"><i class="bi bi-bank2"></i></div>
        <div class="brand-text"><strong><?= e(config('app_name')) ?></strong><span><?= e($saccoName) ?></span></div>
      </a>
    </div>
    <nav class="nav">
      <?php foreach ($nav as $group => $links):
          $links = array_filter($links, fn($l) => Auth::can($l[3]));
          if (!$links) continue; ?>
        <div class="nav-label"><?= e($group) ?></div>
        <?php foreach ($links as $l): ?>
          <a class="nav-link <?= $activeNav === $l[0] ? 'active' : '' ?>" href="index.php?page=<?= e($l[0]) ?>" title="<?= e($l[1]) ?>">
            <i class="bi bi-<?= e($l[2]) ?>"></i><span><?= e($l[1]) ?></span>
            <?php if (!empty($l[4])): ?><em class="nav-badge"><?= (int) $l[4] ?></em><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <div class="sidebar-user">
        <span class="avatar sm" data-hue="3"><?= e(initials($user['name'])) ?></span>
        <div class="meta"><strong><?= e($user['name']) ?></strong><span><?= e($roleLabel) ?> · <?= e($user['branch_name'] ?? 'All branches') ?></span></div>
      </div>
    </div>
  </aside>
  <div class="sidebar-overlay"></div>

  <div class="main">
    <header class="topbar">
      <button class="icon-btn" type="button" data-sidebar-toggle aria-label="Toggle navigation"><i class="bi bi-layout-sidebar-inset"></i></button>

      <?php if (Auth::can('members.view')): ?>
      <div class="global-search dropdown">
        <i class="bi bi-search"></i>
        <input class="input" type="search" placeholder="Search members…" aria-label="Search members" autocomplete="off">
        <kbd>/</kbd>
        <div class="dropdown-menu search-results"></div>
      </div>
      <?php endif; ?>

      <div class="topbar-actions">
        <?php if (Auth::can('tills.use')): ?>
          <a class="till-chip <?= $till ? 'open' : '' ?> hide-sm" href="index.php?page=tills" title="Teller till"><i class="bi bi-safe2"></i><span><?= $till ? 'Till open' : 'Till closed' ?></span></a>
        <?php endif; ?>
        <?php if ($quick): ?>
        <div class="dropdown">
          <button class="btn btn-primary" type="button" data-dropdown-toggle><i class="bi bi-plus-lg"></i><span class="hide-sm">Quick action</span></button>
          <div class="dropdown-menu">
            <div class="dropdown-head">Create</div>
            <?php foreach ($quick as [$modal, $icon, $text]): ?>
              <button class="dropdown-item" type="button" data-open="<?= e($modal) ?>"><i class="bi bi-<?= e($icon) ?>"></i><?= e($text) ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <button class="icon-btn" type="button" data-theme-toggle aria-label="Toggle dark mode" title="Toggle dark mode">
          <i class="bi bi-moon-stars theme-icon-light"></i><i class="bi bi-sun theme-icon-dark"></i>
        </button>

        <div class="dropdown hide-xs" id="notifications">
          <button class="icon-btn" type="button" data-dropdown-toggle aria-label="Notifications"><i class="bi bi-bell"></i><span class="dot" hidden>0</span></button>
          <div class="dropdown-menu wide">
            <div class="dropdown-head">Notifications</div>
            <div class="notif-list"><div class="picker-empty">Loading…</div></div>
          </div>
        </div>

        <div class="dropdown">
          <button class="user-chip" type="button" data-dropdown-toggle>
            <span class="avatar sm" data-hue="3"><?= e(initials($user['name'])) ?></span>
            <span class="meta"><strong><?= e($user['name']) ?></strong><span><?= e($roleLabel) ?></span></span>
            <i class="bi bi-chevron-down text-xs muted hide-sm"></i>
          </button>
          <div class="dropdown-menu">
            <div class="dropdown-head"><?= e($user['email']) ?></div>
            <button class="dropdown-item" type="button" data-open="profileModal" data-prefill="<?= e(json_encode(['name' => $user['name'], 'phone' => $user['phone']])) ?>"><i class="bi bi-person-circle"></i>My profile &amp; password</button>
            <button class="dropdown-item" type="button" data-open-security><i class="bi bi-shield-lock"></i>Two-step login</button>
            <?php if (Auth::can('settings.manage')): ?><a class="dropdown-item" href="index.php?page=settings"><i class="bi bi-sliders"></i>System settings</a><?php endif; ?>
            <button class="dropdown-item" type="button" data-theme-toggle><i class="bi bi-circle-half"></i>Switch theme</button>
            <div class="dropdown-divider"></div>
            <form method="post" action="logout.php">
              <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
              <button class="dropdown-item danger" type="submit"><i class="bi bi-box-arrow-right"></i>Sign out</button>
            </form>
          </div>
        </div>
      </div>
    </header>

    <main class="content" id="content">
      <?php if ($user['role'] === 'admin' && (int) setting('db_version', 1) < APP_DB_VERSION): ?>
        <div class="info-strip warning"><i class="bi bi-database-exclamation"></i><span><strong>Database upgrade required.</strong> Run <code>php database/migrate.php</code> to finish the upgrade.</span></div>
      <?php endif; ?>
      <?= $content ?>
    </main>

    <footer class="footer">
      <span>© <?= date('Y') ?> <?= e($saccoName) ?> · Powered by <?= e(config('app_name')) ?></span>
      <span><i class="bi bi-shield-lock"></i> Signed in as <?= e($roleLabel) ?><?= $user['branch_name'] ? ' · ' . e($user['branch_name']) : ' · Head office' ?></span>
    </footer>
  </div>
</div>

<div class="toast-stack" aria-live="polite"></div>

<?php require APP_PATH . '/views/partials/modals.php'; ?>

<?php if ($needsCharts): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script src="<?= e($asset('assets/js/app.js')) ?>"></script>
<script src="<?= e($asset('assets/js/sacco.js')) ?>"></script>
<?= $pageScripts ?>
</body>
</html>
