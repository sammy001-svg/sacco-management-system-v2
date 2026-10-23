<?php
defined('SACCO') or exit;
$supervise = Auth::can('tills.supervise');
$headOffice = $supervise && empty(Auth::user()['branch_id']);
$cur = e(currency());
$denomFields = function (): string {
    $h = '<div class="denoms mb-2">';
    foreach (TillService::DENOMINATIONS as $d) {
        $h .= '<label class="denom"><span>' . number_format($d) . ' ×</span><input class="input input-sm" type="number" min="0" step="1" name="denoms[' . $d . ']" data-denom="' . $d . '" placeholder="0"></label>';
    }
    return $h . '</div>';
};
$modalHead = fn(string $icon, string $title, string $sub, string $tone = '') => '<div class="modal-head"><div class="modal-icon ' . $tone . '"><i class="bi bi-' . $icon . '"></i></div><div class="modal-title"><h3>' . $title . '</h3><p>' . $sub . '</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>';
$amountField = fn(string $label, string $name = 'amount', string $min = '1') => '<div class="field"><label>' . $label . ' <span class="req">*</span></label><div class="input-group"><span class="addon">' . e(currency()) . '</span><input class="input input-amount" type="number" name="' . $name . '" min="' . $min . '" step="0.01" required placeholder="0.00"></div></div>';
$foot = fn(string $btn, string $icon, string $tone = 'primary') => '<div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-' . $tone . '"><i class="bi bi-' . $icon . '"></i><span>' . $btn . '</span></button></div>';
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Operations</span><i class="bi bi-chevron-right"></i><span>Teller tills &amp; vault</span></div>
    <h1>Teller tills &amp; vault</h1>
    <p>Floats come from the branch vault with a supervisor's release. Tellers top up, drop excess cash and surrender the count at day end.</p>
  </div>
  <div class="page-actions"><a class="btn btn-primary" href="index.php?page=counter"><i class="bi bi-person-workspace"></i><span>Teller counter</span></a></div>
</div>

<div class="grid-main mb-3">
  <section class="card">
    <div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-safe2"></i></span>My till</h3><p data-my-sub>Loading…</p></div><div class="btn-row" data-my-actions></div></div>
    <div class="card-body">
      <div class="grid-kpi" style="grid-template-columns:repeat(auto-fit,minmax(118px,1fr));gap:12px" data-my-kpis></div>
      <div data-my-pending></div>
    </div>
  </section>
  <section class="card">
    <div class="card-head"><h3><span class="h-icon"><i class="bi bi-diagram-3"></i></span>Cash flow of the day</h3></div>
    <div class="card-body"><ol class="text-sm" style="margin:0;padding-left:18px;line-height:1.9">
      <li><strong>Request a float.</strong> A supervisor releases it from the vault and your till opens.</li>
      <li>Every cash deposit, withdrawal, share purchase or repayment you post runs through your drawer.</li>
      <li>Running low? <strong>Request a top-up</strong>. Holding too much? <strong>Drop cash</strong> back to the vault.</li>
      <li>At day end, count the notes and <strong>close</strong>. The count goes back to the vault, any difference posts to Cash Over / Short and you print the signed report.</li>
    </ol></div>
  </section>
</div>

<?php if ($supervise): ?>
<section class="card mb-3" id="vaultCard">
  <div class="card-head">
    <div><h3><span class="h-icon"><i class="bi bi-bank2"></i></span>Branch vault <span class="muted fw-normal" data-vault-branch></span></h3><p>Strong-room cash, cash with tellers and requests waiting for you.</p></div>
    <div class="btn-row">
      <?php if ($headOffice): ?><select class="select select-sm" id="vaultBranch" aria-label="Branch"><?php foreach (DB::all("SELECT id, name FROM branches WHERE status = 'active' ORDER BY id") as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
      <button class="btn btn-ghost btn-sm" data-open="vaultBankModal" data-prefill='{"direction":"to"}'><i class="bi bi-box-arrow-up"></i><span>Bank cash</span></button>
      <button class="btn btn-ghost btn-sm" data-open="vaultBankModal" data-prefill='{"direction":"from"}'><i class="bi bi-box-arrow-in-down"></i><span>Cash from bank</span></button>
      <button class="btn btn-ghost btn-sm" data-open="vaultCountModal"><i class="bi bi-calculator"></i><span>Count vault</span></button>
    </div>
  </div>
  <div class="card-body">
    <div class="grid-kpi" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px" data-vault-kpis></div>
    <div class="form-section mt-3">Waiting for release</div>
    <div data-vault-pending></div>
    <div class="form-section mt-3">Tellers on duty</div>
    <div data-vault-tills></div>
  </div>
</section>

<section class="card mb-3">
  <div class="card-head"><h3><span class="h-icon"><i class="bi bi-arrow-left-right"></i></span>Vault movements</h3></div>
  <form class="toolbar" id="moveFilters" autocomplete="off">
    <input type="hidden" name="branch_id">
    <select class="select" name="type"><option value="">All movements</option><?php foreach (VaultService::TYPES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select>
    <input class="input" type="date" name="date_from" value="<?= date('Y-m-d', strtotime('-7 days')) ?>" aria-label="From">
    <input class="input" type="date" name="date_to" value="<?= today() ?>" aria-label="To">
  </form>
  <div id="moveTable"></div>
</section>
<?php endif; ?>

<section class="card">
  <div class="card-head"><h3><span class="h-icon"><i class="bi bi-clock-history"></i></span><?= $supervise ? 'Till sessions' : 'My till history' ?></h3></div>
  <form class="toolbar" id="tillFilters" autocomplete="off">
    <select class="select" name="status"><option value="">Open &amp; closed</option><option value="open">Open</option><option value="closed">Closed</option></select>
    <?php if ($supervise): ?><select class="select" name="user_id"><option value="">All tellers</option><?php foreach (DB::all("SELECT id, name FROM users WHERE status = 'active' ORDER BY name") as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select><?= branch_filter() ?><?php endif; ?>
    <input class="input" type="date" name="date_from" value="<?= date('Y-m-d', strtotime('-30 days')) ?>" aria-label="From">
    <input class="input" type="date" name="date_to" value="<?= today() ?>" aria-label="To">
  </form>
  <div id="tillTable"></div>
</section>

<?php require APP_PATH . '/views/partials/till_modals.php'; ?>

<div class="modal modal-lg" id="tillCloseModal" role="dialog" aria-modal="true" aria-hidden="true" data-static>
  <form class="modal-dialog" data-api="tills/close" novalidate>
    <div class="modal-head"><div class="modal-icon warning"><i class="bi bi-lock"></i></div><div class="modal-title"><h3 data-close-title>Close till</h3><p>Count each denomination in the drawer. The count is surrendered to the vault.</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <input type="hidden" name="id">
      <div class="form-section">Cash count</div>
      <?= $denomFields() ?>
      <div class="field"><label>Counted total <span class="muted text-xs">(or type it directly)</span></label><div class="input-group"><span class="addon"><?= $cur ?></span><input class="input input-amount" type="number" name="counted_cash" min="0" step="0.01" placeholder="0.00"></div></div>
      <div class="summary-box" data-close-summary></div>
      <div class="field"><label>Notes</label><input class="input" name="notes" maxlength="255" placeholder="Explain any difference"></div>
    </div>
    <?= $foot('Close &amp; surrender', 'lock', 'warning') ?>
  </form>
</div>

<?php if ($supervise): ?>
<div class="modal modal-sm" id="vaultBankModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="vault/bank" novalidate>
    <?= $modalHead('bank', 'Vault ⇄ bank', 'Bank excess vault cash, or bring cash from the bank into the vault. Posts a journal.') ?>
    <div class="modal-body">
      <input type="hidden" name="branch_id" data-keep>
      <div class="field"><label>Direction</label><div class="segmented">
        <label><input type="radio" name="direction" value="to" checked><span><i class="bi bi-box-arrow-up"></i> Vault → bank</span></label>
        <label><input type="radio" name="direction" value="from"><span><i class="bi bi-box-arrow-in-down"></i> Bank → vault</span></label>
      </div></div>
      <?= $amountField('Amount') ?>
      <div class="form-grid">
        <div class="field"><label>Slip / cheque no.</label><input class="input" name="reference" maxlength="80"></div>
        <div class="field"><label>Note</label><input class="input" name="note" maxlength="255"></div>
      </div>
    </div>
    <?= $foot('Record', 'check2-circle') ?>
  </form>
</div>

<div class="modal modal-lg" id="vaultCountModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="vault/count" novalidate>
    <?= $modalHead('calculator', 'Count the vault', 'Physical count of the strong room. Differences post to Cash Over / Short.', 'warning') ?>
    <div class="modal-body">
      <input type="hidden" name="branch_id" data-keep>
      <div class="form-section">Cash count</div>
      <?= $denomFields() ?>
      <div class="field"><label>Counted total <span class="muted text-xs">(or type it directly)</span></label><div class="input-group"><span class="addon"><?= $cur ?></span><input class="input input-amount" type="number" name="counted" min="0" step="0.01" placeholder="0.00"></div></div>
      <div class="summary-box" data-vcount-summary></div>
      <div class="field"><label>Note</label><input class="input" name="note" maxlength="255" placeholder="Who counted, any remarks"></div>
    </div>
    <?= $foot('Record count', 'check2-circle', 'warning') ?>
  </form>
</div>
<?php endif; ?>

<?php ob_start(); ?>
<script>
(function () {
  const A = App, supervise = <?= $supervise ? 'true' : 'false' ?>;
  let mine = null, closing = null, vault = null;
  const kpi = (l, v, tone, icon, extra = '') => `<div class="stat-card" data-tone="${tone}" style="padding:14px 16px"><div class="stat-top"><span class="stat-label">${l}</span><span class="stat-icon" style="width:36px;height:36px;font-size:16px"><i class="bi bi-${icon}"></i></span></div><div class="stat-value" style="font-size:19px">${v}</div>${extra}</div>`;
  const typeLabel = { float: 'Opening float', top_up: 'Top-up', drop: 'Cash drop', surrender: 'Day-end surrender' };

  /* ---------- Teller: my till ---------- */
  async function loadMine() {
    let d;
    try { d = (await A.get('tills/mine')).data; } catch (e) { return A.toast(e.message, 'error'); }
    mine = d.session;
    const pend = d.pending || [];
    const waitingFloat = pend.some(p => p.type === 'float');
    A.$('[data-my-pending]').innerHTML = pend.length ? `<div class="form-section mt-3">My requests</div><div class="list">${pend.map(p => `
      <div class="list-row"><span class="badge warning">Waiting</span><div class="grow"><strong>${typeLabel[p.type] || A.label(p.type)} · ${A.money(p.amount)}</strong><div class="text-xs muted">${A.dateTime(p.created_at)}${p.note ? ' · ' + A.esc(p.note) : ''}</div></div>
      <button class="btn btn-ghost btn-xs" data-cancel-req="${p.id}"><i class="bi bi-x"></i> Cancel</button></div>`).join('')}</div>` : '';

    if (!mine) {
      A.$('[data-my-sub]').innerHTML = waitingFloat
        ? '<span class="badge warning">Waiting for float</span> — your till opens when a supervisor releases the cash.'
        : d.required ? '<span class="text-amber fw-bold">Closed</span> — cash transactions are blocked until your till is open.' : 'Closed. Tills are optional (see Settings → Controls).';
      A.$('[data-my-actions]').innerHTML = waitingFloat ? '' : '<button class="btn btn-primary" data-open="floatModal"><i class="bi bi-unlock"></i><span>Request float</span></button>';
      A.$('[data-my-kpis]').innerHTML = `<div class="empty" style="grid-column:1/-1;padding:20px"><div class="empty-icon"><i class="bi bi-safe2"></i></div><h4>No open till</h4><p>${waitingFloat ? 'Collect your float from the vault custodian once it is released.' : 'Request your opening float to start the day.'}</p></div>`;
      return;
    }
    A.$('[data-my-sub]').innerHTML = `<span class="badge success">Open</span> since ${A.dateTime(mine.opened_at)}${mine.branch_name ? ' · ' + A.esc(mine.branch_name) : ''}`;
    A.$('[data-my-actions]').innerHTML = `
      <button class="btn btn-ghost btn-sm" data-open="topupModal"><i class="bi bi-plus-circle"></i><span>Top-up</span></button>
      <button class="btn btn-ghost btn-sm" data-open="dropModal"><i class="bi bi-box-arrow-in-down"></i><span>Cash drop</span></button>
      <a class="btn btn-ghost btn-sm" href="index.php?page=till_report&id=${mine.id}" target="_blank"><i class="bi bi-printer"></i><span>Interim report</span></a>
      <button class="btn btn-warning btn-sm" data-close-till="mine"><i class="bi bi-lock"></i><span>Close till</span></button>`;
    const limit = mine.over_limit ? `<div class="text-xs text-red fw-bold mt-1"><i class="bi bi-exclamation-triangle"></i> Over the ${A.money(mine.cash_limit)} limit — drop cash</div>` : '';
    A.$('[data-my-kpis]').innerHTML = kpi('Float + top-ups', A.money(+mine.opening_float + +mine.top_ups), 'navy', 'safe2')
      + kpi(`Cash in · ${mine.count_in}`, A.money(mine.cash_in), 'green', 'box-arrow-in-down')
      + kpi(`Cash out · ${mine.count_out}`, A.money(mine.cash_out), 'rose', 'box-arrow-up')
      + kpi('Dropped to vault', A.money(mine.drops), 'amber', 'safe')
      + kpi('Expected in drawer', A.money(mine.expected_now), mine.over_limit ? 'rose' : 'teal', 'calculator', limit);
  }

  /* ---------- Close till ---------- */
  function openClose(s) {
    closing = s;
    A.openForm('tillCloseModal', { id: s.id });
    A.$('[data-close-title]').textContent = `Close till — ${s.user_name}`;
    closeSummary();
  }
  function denomTotal(f, field) {
    let total = 0, any = false;
    A.$$('[data-denom]', f).forEach(i => { if (+i.value) { any = true; total += +i.dataset.denom * +i.value; } });
    if (any && field) f.elements.namedItem(field).value = total.toFixed(2);
    return any;
  }
  function varianceRows(expected, counted, label = 'Expected cash') {
    const v = Math.round((counted - expected) * 100) / 100;
    return `<div class="summary-row"><span>${label}</span><strong>${A.money(expected)}</strong></div>
      <div class="summary-row"><span>Counted</span><strong>${A.money(counted)}</strong></div>
      <div class="summary-row total"><span>${v === 0 ? 'Balanced' : v < 0 ? 'Shortage' : 'Overage'}</span><strong class="${v === 0 ? 'text-green' : v < 0 ? 'text-red' : 'text-amber'}">${A.money(Math.abs(v))}</strong></div>`;
  }
  function closeSummary() {
    const f = A.$('#tillCloseModal form');
    denomTotal(f, 'counted_cash');
    A.$('[data-close-summary]').innerHTML = varianceRows(closing.expected_now, +f.elements.namedItem('counted_cash').value || 0);
  }
  const clearDenomsOnTotal = (f, field, cb) => f.addEventListener('input', e => { if (e.target.name === field) A.$$('[data-denom]', f).forEach(i => (i.value = '')); cb(); });
  clearDenomsOnTotal(A.$('#tillCloseModal form'), 'counted_cash', closeSummary);
  A.$('#tillCloseModal form').addEventListener('form:success', e => {
    if (e.detail.report) window.open(e.detail.report, '_blank');
  });

  /* ---------- Supervisor: vault ---------- */
  const branchSel = A.$('#vaultBranch');
  const branchParam = () => (branchSel ? { branch_id: branchSel.value } : {});
  async function loadVault() {
    if (!supervise) return;
    try { vault = (await A.get('vault/summary', branchParam())).data; } catch (e) { return A.toast(e.message, 'error'); }
    A.$('[data-vault-branch]').textContent = vault.branch_name ? '· ' + vault.branch_name : '';
    A.$$('#vaultCard ~ .modal input[name=branch_id], #vaultBankModal input[name=branch_id], #vaultCountModal input[name=branch_id], #moveFilters input[name=branch_id]').forEach(i => (i.value = vault.branch_id));
    A.$('[data-vault-kpis]').innerHTML = kpi('In the vault', A.money(vault.balance), 'navy', 'bank2')
      + kpi(`With tellers · ${vault.tills.length}`, A.money(vault.in_tills), 'teal', 'people')
      + kpi('Branch cash total', A.money(+vault.balance + +vault.in_tills), 'green', 'cash-coin')
      + kpi('Waiting for release', vault.pending.length, vault.pending.length ? 'amber' : 'navy', 'hourglass-split');
    A.$('[data-vault-pending]').innerHTML = vault.pending.length ? `<div class="list">${vault.pending.map(p => `
      <div class="list-row">${A.avatar(p.user_name, 'sm')}<div class="grow"><strong>${A.esc(p.user_name)} · ${A.esc(p.type_label)}</strong><div class="text-xs muted">${A.dateTime(p.created_at)}${p.note ? ' · ' + A.esc(p.note) : ''}</div></div>
        <strong class="mono">${A.money(p.amount)}</strong>
        ${p.mine ? '<span class="text-xs muted">Your own request</span>' : `<button class="btn btn-primary btn-xs" data-release="${p.id}"><i class="bi bi-check2"></i> Release</button><button class="btn btn-ghost btn-xs" data-reject="${p.id}"><i class="bi bi-x"></i> Reject</button>`}
      </div>`).join('')}</div>` : '<p class="muted text-sm">Nothing waiting.</p>';
    A.$('[data-vault-tills]').innerHTML = vault.tills.length ? `<div class="list">${vault.tills.map(t => `
      <div class="list-row">${A.avatar(t.user_name, 'sm')}<div class="grow"><strong>${A.esc(t.user_name)}</strong><div class="text-xs muted">Open since ${A.dateTime(t.opened_at)} · in ${A.money(t.cash_in)} · out ${A.money(t.cash_out)}</div></div>
        <strong class="mono ${t.over_limit ? 'text-red' : ''}">${A.money(t.expected_now)}</strong>${t.over_limit ? '<span class="badge danger">Over limit</span>' : ''}
        <a class="act" href="index.php?page=till_report&id=${t.id}" target="_blank" title="Report"><i class="bi bi-printer"></i></a>
        <button class="act" data-close-session="${t.id}" title="Close till"><i class="bi bi-lock"></i></button></div>`).join('')}</div>` : '<p class="muted text-sm">No tills open.</p>';
    if (moves) moves.reload();
  }
  if (branchSel) branchSel.addEventListener('change', loadVault);

  const countForm = A.$('#vaultCountModal form');
  if (countForm) {
    const vSummary = () => { denomTotal(countForm, 'counted'); A.$('[data-vcount-summary]').innerHTML = varianceRows(+(vault?.balance || 0), +countForm.elements.namedItem('counted').value || 0, 'Vault per system'); };
    clearDenomsOnTotal(countForm, 'counted', vSummary);
    A.$('#vaultCountModal').addEventListener('form:open', () => { countForm.elements.namedItem('branch_id').value = vault?.branch_id || ''; vSummary(); });
    A.$('#vaultBankModal').addEventListener('form:open', () => { A.$('#vaultBankModal input[name=branch_id]').value = vault?.branch_id || ''; });
  }

  const moves = supervise ? new A.DataTable('#moveTable', {
    endpoint: 'vault/movements', filters: '#moveFilters', empty: 'No vault movements', emptyIcon: 'bank2',
    columns: [
      { label: 'When', render: r => `<span class="nowrap">${A.dateTime(r.created_at)}</span>` },
      { label: 'Movement', render: r => `<strong>${A.esc(r.type_label)}</strong>${r.reference ? `<div class="text-xs muted mono">${A.esc(r.reference)}</div>` : ''}` },
      { label: 'Teller', hideSm: true, render: r => r.user_name ? A.esc(r.user_name) : '—' },
      { label: 'Amount', align: 'right', render: r => A.money(r.amount, false) },
      { label: 'Vault effect', align: 'right', render: r => r.status !== 'completed' ? '—' : `<span class="${+r.vault_effect < 0 ? 'text-red' : +r.vault_effect > 0 ? 'text-green' : 'muted'} fw-bold">${+r.vault_effect > 0 ? '+' : ''}${A.money(r.vault_effect, false)}</span>` },
      { label: 'Vault after', align: 'right', hideSm: true, render: r => r.vault_after !== null ? A.money(r.vault_after, false) : '—' },
      { label: 'By', hideSm: true, render: r => `<span class="text-xs">${A.esc(r.requested_by_name || '—')}${r.approved_by_name && r.approved_by_name !== r.requested_by_name ? ` → ${A.esc(r.approved_by_name)}` : ''}</span>` },
      { label: 'Status', render: r => A.badge({ completed: 'active', pending: 'pending', rejected: 'rejected', cancelled: 'closed' }[r.status] || r.status, A.label(r.status)) },
    ],
  }) : null;

  /* ---------- Sessions ---------- */
  new A.DataTable('#tillTable', {
    endpoint: 'tills/list', filters: '#tillFilters', empty: 'No till sessions', emptyIcon: 'safe2',
    columns: [
      { label: 'Teller', render: r => `<div class="cell-member">${A.avatar(r.user_name, 'sm')}<div class="meta"><strong>${A.esc(r.user_name)}</strong><span>${A.esc(r.branch_name || 'Head office')}</span></div></div>` },
      { label: 'Opened', render: r => `<span class="nowrap">${A.dateTime(r.opened_at)}</span>` },
      { label: 'Closed', hideSm: true, hideMd: true, render: r => r.closed_at ? `<span class="nowrap">${A.dateTime(r.closed_at)}</span>${r.closed_by_name && r.closed_by_name !== r.user_name ? `<div class="text-xs muted">by ${A.esc(r.closed_by_name)}</div>` : ''}` : '—' },
      { label: 'Float', align: 'right', hideSm: true, hideMd: true, render: r => A.money(r.opening_float, false) },
      { label: 'Expected', align: 'right', render: r => A.money(r.expected_cash, false) },
      { label: 'Counted', align: 'right', render: r => r.counted_cash !== null ? A.money(r.counted_cash, false) : '—' },
      { label: 'Variance', align: 'right', render: r => r.variance === null ? '—' : +r.variance === 0 ? '<span class="badge success">Balanced</span>' : `<span class="${+r.variance < 0 ? 'text-red' : 'text-amber'} fw-bold">${A.money(r.variance, false)}</span>` },
      { label: 'Status', render: r => A.badge(r.status === 'open' ? 'active' : 'closed', A.label(r.status)) },
      { label: '', align: 'right', render: r => `<span class="nowrap"><a class="act" href="index.php?page=till_report&id=${r.id}" target="_blank" title="Cash report"><i class="bi bi-printer"></i></a>`
          + (r.status === 'open' && (supervise || r.user_id === A.cfg.user.id) ? '<button class="act" data-action="close" title="Close till"><i class="bi bi-lock"></i></button>' : '') + '</span>' },
    ],
    onAction: async (a, r) => { if (a === 'close') openClose((await A.get('tills/get', { id: r.id })).data); },
  });

  /* ---------- Buttons ---------- */
  document.addEventListener('click', async e => {
    if (e.target.closest('[data-close-till="mine"]') && mine) return openClose(mine);
    const cs = e.target.closest('[data-close-session]');
    if (cs) return openClose((await A.get('tills/get', { id: cs.dataset.closeSession })).data);
    const cancel = e.target.closest('[data-cancel-req]');
    if (cancel) {
      if (!(await A.confirm({ title: 'Cancel this request?', confirmText: 'Cancel request', tone: 'warning' }))) return;
      try { A.toast((await A.post('tills/cancel_request', { id: cancel.dataset.cancelReq })).message); refresh(); } catch (err) { A.toast(err.message, 'error'); }
      return;
    }
    const rel = e.target.closest('[data-release]');
    if (rel) {
      const p = vault.pending.find(x => String(x.id) === rel.dataset.release);
      if (!(await A.confirm({ title: `Release ${A.money(p.amount)}?`, message: `${p.type_label} for ${p.user_name}. Count the cash out of the vault and hand it over.`, confirmText: 'Release cash', tone: 'primary', icon: 'safe2' }))) return;
      try { A.toast((await A.post('vault/approve', { id: p.id })).message); refresh(); } catch (err) { A.toast(err.message, 'error'); }
      return;
    }
    const rej = e.target.closest('[data-reject]');
    if (rej) {
      const note = await A.confirm({ title: 'Reject request', confirmText: 'Reject', input: { label: 'Reason', placeholder: 'Why is this request rejected?' } });
      if (!note) return;
      try { A.toast((await A.post('vault/reject', { id: rej.dataset.reject, note })).message); refresh(); } catch (err) { A.toast(err.message, 'error'); }
    }
  });

  function refresh() { loadMine(); loadVault(); A.DataTable.all.forEach(t => t.reload()); }
  document.addEventListener('app:saved', () => { loadMine(); loadVault(); });
  loadMine();
  loadVault();
  setInterval(() => { if (!document.hidden) { loadMine(); loadVault(); } }, 30000);
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
