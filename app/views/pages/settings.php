<?php
defined('SACCO') or exit;
$s = DB::pairs('SELECT `key`, `value` FROM settings');
$v = fn(string $k, string $d = '—') => e(($s[$k] ?? '') !== '' ? $s[$k] : $d);
$groups = [
    'organisation' => ['Organisation', 'building', 'Name and contact details shown on receipts and statements.', [
        'sacco_name' => ['SACCO name', 'text', true], 'sacco_tagline' => ['Tagline', 'text', false], 'sacco_email' => ['Email', 'email', false],
        'sacco_phone' => ['Phone', 'text', false], 'sacco_address' => ['Postal address', 'text', false],
    ]],
    'financial' => ['Financial', 'currency-exchange', 'Currency, share pricing and membership fees.', [
        'currency' => ['Currency code', 'text', true], 'share_price' => ['Share price', 'number', true],
        'min_share_capital' => ['Minimum share capital', 'number', true], 'registration_fee' => ['Registration fee', 'number', true],
        'wht_interest_rate' => ['Withholding tax on savings interest (%)', 'number', true], 'wht_dividend_rate' => ['Withholding tax on dividends (%)', 'number', true],
    ]],
    'operations' => ['Operations', 'gear-wide-connected', 'Numbering and loan penalty rules.', [
        'member_no_prefix' => ['Member number prefix', 'text', true], 'penalty_grace_days' => ['Penalty grace period (days)', 'number', true],
    ]],
    'controls' => ['Controls', 'shield-lock', 'Teller tills, second-person approvals and backups.', [
        'require_till' => ['Cash needs an open teller till', 'bool', false],
        'require_id_check' => ['Tellers confirm ID and signature on withdrawals', 'bool', false],
        'till_cash_limit' => ['Teller drawer cash limit (0 = none)', 'number', true],
        'approval_withdrawal_limit' => ['Approve withdrawals from (0 = never)', 'number', true],
        'approval_journal_limit' => ['Approve manual journals from (0 = never)', 'number', true],
        'approve_reversals' => ['Reversals need approval', 'bool', false],
        'approve_write_offs' => ['Loan write-offs need approval', 'bool', false],
        'backup_keep' => ['Backups to keep', 'number', true],
    ]],
];
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Settings</span></div>
    <h1>System settings</h1>
    <p>Configure how your SACCO operates.</p>
  </div>
</div>

<div class="grid-3 mb-3">
  <?php foreach ($groups as $key => [$title, $icon, $desc, $fields]): ?>
    <section class="card">
      <div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-<?= $icon ?>"></i></span><?= e($title) ?></h3><p><?= e($desc) ?></p></div>
        <button class="btn btn-sm" data-open="settings-<?= $key ?>"><i class="bi bi-pencil"></i><span>Edit</span></button></div>
      <div class="card-body"><div class="kv" style="grid-template-columns:1fr">
        <?php foreach ($fields as $k => [$label, $type]): ?><div><span><?= e($label) ?></span><strong><?= $type === 'bool' ? (($s[$k] ?? '0') === '1' ? 'Yes' : 'No') : $v($k) ?></strong></div><?php endforeach; ?>
      </div></div>
    </section>

    <div class="modal" id="settings-<?= $key ?>" role="dialog" aria-modal="true" aria-hidden="true">
      <form class="modal-dialog" data-api="settings/save" novalidate>
        <div class="modal-head"><div class="modal-icon"><i class="bi bi-<?= $icon ?>"></i></div><div class="modal-title"><h3><?= e($title) ?> settings</h3><p><?= e($desc) ?></p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
        <div class="modal-body">
          <input type="hidden" name="group" value="<?= $key ?>" data-keep>
          <?php foreach ($fields as $k => [$label, $type, $req]): ?>
            <?php if ($type === 'bool'): ?>
              <label class="switch"><input type="checkbox" name="<?= $k ?>" value="1" <?= ($s[$k] ?? '0') === '1' ? 'checked' : '' ?>><span class="track"></span><?= e($label) ?></label>
              <?php continue; endif; ?>
            <div class="field"><label><?= e($label) ?><?= $req ? ' <span class="req">*</span>' : '' ?></label>
              <input class="input" type="<?= $type ?>" name="<?= $k ?>" value="<?= e($s[$k] ?? '') ?>" data-default="<?= e($s[$k] ?? '') ?>" <?= $req ? 'required' : '' ?> <?= $type === 'number' ? 'min="0" step="any"' : '' ?>></div>
          <?php endforeach; ?>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><span>Save settings</span></button></div>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once APP_ROOT . '/database/DemoSeeder.php'; if (DemoSeeder::canRun()): ?>
<section class="card mb-3">
  <div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-magic"></i></span>Demo data</h3><p>Fill this empty system with sample members, savings, shares, loans, expenses and accounting history to explore or train staff.</p></div>
    <button class="btn btn-primary" data-open="demoModal"><i class="bi bi-database-add"></i><span>Load demo data</span></button></div>
  <div class="card-body"><div class="info-strip warning mb-0"><i class="bi bi-exclamation-triangle"></i><span>Only available while the system has no members or transactions. Demo records are fictional — for a live SACCO, start with an empty system instead.</span></div></div>
</section>
<div class="modal" id="demoModal" role="dialog" aria-modal="true" aria-hidden="true" data-static>
  <form class="modal-dialog" data-api="settings/demo_data" novalidate>
    <div class="modal-head"><div class="modal-icon"><i class="bi bi-magic"></i></div><div class="modal-title"><h3>Load demo data</h3><p>Takes about 10–60 seconds. Keep this page open.</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <div class="field"><label>Number of members</label><select class="select" name="members"><option value="50">50 — quick</option><option value="120" selected>120 — recommended</option><option value="300">300 — larger demo (slower)</option></select></div>
      <p class="text-sm muted mb-2">Adds 3 demo branches and 18 months of history: deposits, withdrawals, shares, loans (some in arrears), expenses, bank banking and the full general ledger. Your login and SACCO settings are kept.</p>
      <label class="switch"><input type="checkbox" name="staff" value="1"><span class="track"></span>Also create demo staff logins (manager, teller, loans, accounts — password <code>Password@123</code>)</label>
      <div class="info-strip warning"><i class="bi bi-shield-exclamation"></i><span>If you create the staff logins on a public server, change or deactivate them afterwards in Users &amp; Roles.</span></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-database-add"></i><span>Load demo data</span></button></div>
  </form>
</div>
<?php endif; ?>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-clock-history"></i></span>Daily jobs</h3><p>Marks overdue installments and charges late-payment penalties.</p></div></div>
    <div class="card-body">
      <p class="muted text-sm mb-2">Schedule <code>php cron/daily.php</code> to run every night (Windows Task Scheduler or cron). You can also run it now:</p>
      <button class="btn btn-primary" id="runJobs"><i class="bi bi-play-circle"></i><span>Run daily jobs now</span></button>
    </div>
  </section>
  <section class="card">
    <div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-hdd-stack"></i></span>System</h3><p>Environment information</p></div></div>
    <div class="card-body"><div class="kv">
      <div><span>Application</span><strong><?= e(config('app_name')) ?> 1.0</strong></div>
      <div><span>PHP</span><strong><?= e(PHP_VERSION) ?></strong></div>
      <div><span>Database</span><strong><?= e(DB::value('SELECT VERSION()')) ?></strong></div>
      <div><span>Timezone</span><strong><?= e(date_default_timezone_get()) ?></strong></div>
      <div><span>Members</span><strong><?= number_format((int) DB::value('SELECT COUNT(*) FROM members')) ?></strong></div>
      <div><span>Ledger entries</span><strong><?= number_format((int) DB::value('SELECT COUNT(*) FROM transactions')) ?></strong></div>
    </div></div>
  </section>
</div>

<?php ob_start(); ?>
<script>
(function () {
  const A = App;
  document.addEventListener('app:saved', e => { const r = e.detail.route || ''; if (r === 'settings/save') setTimeout(() => location.reload(), 700); if (r === 'settings/demo_data') setTimeout(() => (location.href = 'index.php'), 1200); });
  A.$('#runJobs').addEventListener('click', async e => {
    const btn = e.currentTarget; btn.classList.add('loading'); btn.disabled = true;
    try { A.toast((await A.post('settings/run_jobs')).message, 'success', 'Daily jobs complete'); }
    catch (err) { A.toast(err.message, 'error'); }
    finally { btn.classList.remove('loading'); btn.disabled = false; }
  });
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
