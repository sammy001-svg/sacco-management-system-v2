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
    ]],
    'operations' => ['Operations', 'gear-wide-connected', 'Numbering and loan penalty rules.', [
        'member_no_prefix' => ['Member number prefix', 'text', true], 'penalty_grace_days' => ['Penalty grace period (days)', 'number', true],
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
        <?php foreach ($fields as $k => [$label]): ?><div><span><?= e($label) ?></span><strong><?= $v($k) ?></strong></div><?php endforeach; ?>
      </div></div>
    </section>

    <div class="modal" id="settings-<?= $key ?>" role="dialog" aria-modal="true" aria-hidden="true">
      <form class="modal-dialog" data-api="settings/save" novalidate>
        <div class="modal-head"><div class="modal-icon"><i class="bi bi-<?= $icon ?>"></i></div><div class="modal-title"><h3><?= e($title) ?> settings</h3><p><?= e($desc) ?></p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
        <div class="modal-body">
          <input type="hidden" name="group" value="<?= $key ?>" data-keep>
          <?php foreach ($fields as $k => [$label, $type, $req]): ?>
            <div class="field"><label><?= e($label) ?><?= $req ? ' <span class="req">*</span>' : '' ?></label>
              <input class="input" type="<?= $type ?>" name="<?= $k ?>" value="<?= e($s[$k] ?? '') ?>" data-default="<?= e($s[$k] ?? '') ?>" <?= $req ? 'required' : '' ?> <?= $type === 'number' ? 'min="0" step="any"' : '' ?>></div>
          <?php endforeach; ?>
        </div>
        <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><span>Save settings</span></button></div>
      </form>
    </div>
  <?php endforeach; ?>
</div>

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
  document.addEventListener('app:saved', e => { if ((e.detail.route || '') === 'settings/save') setTimeout(() => location.reload(), 700); });
  A.$('#runJobs').addEventListener('click', async e => {
    const btn = e.currentTarget; btn.classList.add('loading'); btn.disabled = true;
    try { A.toast((await A.post('settings/run_jobs')).message, 'success', 'Daily jobs complete'); }
    catch (err) { A.toast(err.message, 'error'); }
    finally { btn.classList.remove('loading'); btn.disabled = false; }
  });
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
