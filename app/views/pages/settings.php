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
    'notifications' => ['Notifications', 'chat-dots', 'Automatic SMS to members and M-Pesa prompts. Placeholders: {first_name} {name} {amount} {account} {balance} {ref} {date} {due_date} {sacco}.', [
        'sms_enabled' => ['Send SMS', 'bool', false], 'mpesa_enabled' => ['Allow M-Pesa payment prompts', 'bool', false],
        'sms_on_transactions' => ['Text a receipt for deposits, withdrawals, repayments and shares', 'bool', false],
        'sms_on_disbursement' => ['Text when a loan is disbursed', 'bool', false],
        'sms_due_reminders' => ['Remind borrowers before an installment is due', 'bool', false],
        'sms_due_reminder_days' => ['Days before the due date', 'number', true],
        'sms_arrears_alerts' => ['Alert borrowers in arrears', 'bool', false],
        'sms_arrears_every_days' => ['Repeat arrears alerts every (days)', 'number', true],
        'sms_signature' => ['Signature ({sacco}) — blank uses the SACCO name', 'text', false],
        'sms_tpl_transaction' => ['Receipt message', 'textarea', true], 'sms_tpl_withdrawal' => ['Withdrawal message', 'textarea', true],
        'sms_tpl_disbursement' => ['Disbursement message', 'textarea', true], 'sms_tpl_due' => ['Due reminder message', 'textarea', true],
        'sms_tpl_arrears' => ['Arrears alert message', 'textarea', true],
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
        <?php foreach ($fields as $k => [$label, $type]): if ($type === 'textarea') { continue; } ?><div><span><?= e($label) ?></span><strong><?= $type === 'bool' ? (($s[$k] ?? '0') === '1' ? 'Yes' : 'No') : $v($k) ?></strong></div><?php endforeach; ?>
      </div></div>
    </section>

    <div class="modal<?= $key === 'notifications' ? ' modal-lg' : '' ?>" id="settings-<?= $key ?>" role="dialog" aria-modal="true" aria-hidden="true">
      <form class="modal-dialog" data-api="settings/save" novalidate>
        <div class="modal-head"><div class="modal-icon"><i class="bi bi-<?= $icon ?>"></i></div><div class="modal-title"><h3><?= e($title) ?> settings</h3><p><?= e($desc) ?></p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
        <div class="modal-body">
          <input type="hidden" name="group" value="<?= $key ?>" data-keep>
          <?php foreach ($fields as $k => [$label, $type, $req]): ?>
            <?php if ($type === 'bool'): ?>
              <label class="switch"><input type="checkbox" name="<?= $k ?>" value="1" <?= ($s[$k] ?? '0') === '1' ? 'checked' : '' ?>><span class="track"></span><?= e($label) ?></label>
              <?php continue; endif; ?>
            <?php if ($type === 'textarea'): ?>
              <div class="field"><label><?= e($label) ?> <span class="req">*</span></label><textarea class="textarea" name="<?= $k ?>" rows="2" maxlength="480" required><?= e($s[$k] ?? '') ?></textarea></div>
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

<section class="card mb-3" id="integrations">
  <div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-plug"></i></span>Integrations</h3><p>M-Pesa and SMS credentials live in the <code>.env</code> file on the server, never in the database or backups. This shows what is set up.</p></div></div>
  <div class="card-body"><div class="int-grid">
    <div>
      <div class="form-section">M-Pesa (Safaricom Daraja)</div>
      <div data-int-mpesa><p class="muted text-sm">Loading…</p></div>
      <div class="btn-row mt-2"><button class="btn btn-sm" data-int-test="test_mpesa"><i class="bi bi-plug"></i><span>Test connection</span></button></div>
    </div>
    <div>
      <div class="form-section">SMS (Shanfix SMS)</div>
      <div data-int-sms><p class="muted text-sm">Loading…</p></div>
      <div class="btn-row mt-2"><button class="btn btn-sm" data-int-test="test_sms"><i class="bi bi-plug"></i><span>Check balance</span></button><button class="btn btn-sm" data-open="smsTestModal"><i class="bi bi-send"></i><span>Send test SMS</span></button></div>
    </div>
  </div>
  <div class="info-strip mt-3 mb-0"><i class="bi bi-clock"></i><span>Schedule <code>php cron/minute.php</code> every minute (cPanel → Cron Jobs). It sends queued SMS and settles M-Pesa payments whose callback was missed. <span data-int-cron></span></span></div>
  </div>
</section>
<div class="modal modal-sm" id="smsTestModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="sms/test" novalidate>
    <div class="modal-head"><div class="modal-icon"><i class="bi bi-send"></i></div><div class="modal-title"><h3>Send a test SMS</h3><p>Sent immediately through Shanfix SMS.</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <div class="field"><label>Phone <span class="req">*</span></label><input class="input" type="tel" name="phone" required maxlength="20" placeholder="0712 345 678"></div>
      <div class="field"><label>Message</label><textarea class="textarea" name="message" rows="3" maxlength="300" placeholder="Leave blank for a standard test message"></textarea></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-send"></i><span>Send</span></button></div>
  </form>
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
  document.addEventListener('app:saved', e => { const r = e.detail.route || ''; if (r === 'settings/save') setTimeout(() => location.reload(), 700); if (r === 'settings/demo_data') setTimeout(() => (location.href = 'index.php'), 1200); });
  A.$('#runJobs').addEventListener('click', async e => {
    const btn = e.currentTarget; btn.classList.add('loading'); btn.disabled = true;
    try { A.toast((await A.post('settings/run_jobs')).message, 'success', 'Daily jobs complete'); }
    catch (err) { A.toast(err.message, 'error'); }
    finally { btn.classList.remove('loading'); btn.disabled = false; }
  });

  /* Integrations: what .env configures (no secrets are sent to the browser). */
  const yes = (ok, text) => `<strong class="${ok ? 'text-green' : 'text-red'}"><i class="bi bi-${ok ? 'check-circle' : 'x-circle'}"></i> ${A.esc(text)}</strong>`;
  const row = (k, v) => `<div class="int-row"><span>${k}</span>${v}</div>`;
  async function integrations() {
    let d;
    try { d = (await A.get('integrations/status')).data; } catch (e) { return; }
    const m = d.mpesa, s = d.sms;
    A.$('[data-int-mpesa]').innerHTML = row('Status', yes(m.configured, m.configured ? 'Connected settings present' : 'Not set up'))
      + row('Environment', `<strong>${m.env === 'live' ? 'Live (real money)' : 'Sandbox (test)'}</strong>`)
      + row('Type', `<strong>${m.type === 'till' ? `Till ${A.esc(m.till_number || '—')} (store ${A.esc(m.shortcode)})` : `Paybill ${A.esc(m.shortcode || '—')}`}</strong>`)
      + row('Consumer key &amp; secret', yes(m.keys, m.keys ? 'Set' : 'Missing'))
      + row('Passkey', yes(m.passkey, m.passkey ? 'Set' : 'Missing'))
      + row('Callback', yes(m.callback_key && m.https, m.https ? (m.callback_key ? 'HTTPS, key protected' : 'Key too short') : 'Needs public HTTPS'))
      + `<div class="text-xs muted mono" style="word-break:break-all;padding:2px 0 8px">${A.esc(m.callback_url)}</div>`
      + row('Waiting now', `<strong>${A.num(m.pending)}</strong>`)
      + row('Last payment', `<strong>${m.last_paid ? A.dateTime(m.last_paid) : '—'}</strong>`);
    A.$('[data-int-sms]').innerHTML = row('Status', yes(s.configured, s.configured ? 'API credentials present' : 'Not set up'))
      + row('Service', `<strong class="mono text-sm">${A.esc(s.base_url)}</strong>`)
      + row('Client ID', `<strong class="mono">${A.esc(s.client_id || '—')}</strong>`)
      + row('Sender ID', `<strong>${A.esc(s.sender_id || 'Account default')}</strong>`)
      + row('Queued', `<strong>${A.num(s.queued)}</strong>`)
      + row('Last sent', `<strong>${s.last_sent ? A.dateTime(s.last_sent) : '—'}</strong>`);
    const last = d.cron_last_run ? new Date(d.cron_last_run.replace(' ', 'T')) : null;
    A.$('[data-int-cron]').innerHTML = !last ? '<strong class="text-red">It has not run yet.</strong>'
      : (Date.now() - last) > 5 * 60000 ? `<strong class="text-amber">Last run ${A.dateTime(d.cron_last_run)} — check the cron job.</strong>` : `<strong class="text-green">Running (last ${A.dateTime(d.cron_last_run)}).</strong>`;
  }
  document.addEventListener('click', async e => {
    const b = e.target.closest('[data-int-test]');
    if (!b) return;
    b.classList.add('loading'); b.disabled = true;
    try { A.toast((await A.post('integrations/' + b.dataset.intTest)).message); } catch (err) { A.toast(err.message, 'error'); }
    finally { b.classList.remove('loading'); b.disabled = false; }
  });
  integrations();
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
