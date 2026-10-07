<?php
defined('SACCO') or exit;
$supervise = Auth::can('tills.supervise');
$cur = e(currency());
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Operations</span><i class="bi bi-chevron-right"></i><span>Teller tills</span></div>
    <h1>Teller tills</h1>
    <p>Open your drawer with a float, take cash all day, then close with a physical count. Differences post to Cash Over / Short.</p>
  </div>
</div>

<div class="grid-main mb-3">
  <section class="card">
    <div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-safe2"></i></span>My till</h3><p data-my-sub>Loading…</p></div><div data-my-actions></div></div>
    <div class="card-body"><div class="grid-kpi" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px" data-my-kpis></div></div>
  </section>
  <section class="card">
    <div class="card-head"><h3><span class="h-icon"><i class="bi bi-info-circle"></i></span>How it works</h3></div>
    <div class="card-body"><ol class="text-sm" style="margin:0;padding-left:18px;line-height:1.9">
      <li>Count the float you receive and <strong>open your till</strong>.</li>
      <li>Every cash deposit, withdrawal, share purchase or repayment you post is tracked against your drawer.</li>
      <li>At day end, count notes and coins and <strong>close</strong>. The system compares the count with the expected cash.</li>
      <li>Shortages and overages post to the ledger automatically and appear in the history below.</li>
    </ol></div>
  </section>
</div>

<section class="card">
  <div class="card-head"><h3><span class="h-icon"><i class="bi bi-clock-history"></i></span><?= $supervise ? 'All tills' : 'My till history' ?></h3></div>
  <form class="toolbar" id="tillFilters" autocomplete="off">
    <select class="select" name="status"><option value="">Open &amp; closed</option><option value="open">Open</option><option value="closed">Closed</option></select>
    <?php if ($supervise): ?><select class="select" name="user_id"><option value="">All tellers</option><?php foreach (DB::all("SELECT id, name FROM users WHERE status = 'active' ORDER BY name") as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select><?= branch_filter() ?><?php endif; ?>
    <input class="input" type="date" name="date_from" value="<?= date('Y-m-d', strtotime('-30 days')) ?>" aria-label="From">
    <input class="input" type="date" name="date_to" value="<?= today() ?>" aria-label="To">
  </form>
  <div id="tillTable"></div>
</section>

<div class="modal modal-sm" id="tillOpenModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="tills/open" novalidate>
    <div class="modal-head"><div class="modal-icon"><i class="bi bi-safe2"></i></div><div class="modal-title"><h3>Open till</h3><p>Enter the float you counted into your drawer.</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body"><div class="field"><label>Opening float <span class="req">*</span></label><div class="input-group"><span class="addon"><?= $cur ?></span><input class="input input-amount" type="number" name="opening_float" min="0" step="0.01" required placeholder="0.00"></div></div></div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-unlock"></i><span>Open till</span></button></div>
  </form>
</div>

<div class="modal modal-lg" id="tillCloseModal" role="dialog" aria-modal="true" aria-hidden="true" data-static>
  <form class="modal-dialog" data-api="tills/close" novalidate>
    <div class="modal-head"><div class="modal-icon warning"><i class="bi bi-lock"></i></div><div class="modal-title"><h3 data-close-title>Close till</h3><p>Count each denomination in the drawer. The total fills in automatically.</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <input type="hidden" name="id">
      <div class="form-section">Cash count</div>
      <div class="denoms mb-2"><?php foreach (TillService::DENOMINATIONS as $d): ?>
        <label class="denom"><span><?= number_format($d) ?> ×</span><input class="input input-sm" type="number" min="0" step="1" name="denoms[<?= $d ?>]" data-denom="<?= $d ?>" placeholder="0"></label>
      <?php endforeach; ?></div>
      <div class="field"><label>Counted total <span class="muted text-xs">(or type it directly)</span></label><div class="input-group"><span class="addon"><?= $cur ?></span><input class="input input-amount" type="number" name="counted_cash" min="0" step="0.01" placeholder="0.00"></div></div>
      <div class="summary-box" data-close-summary></div>
      <div class="field"><label>Notes</label><input class="input" name="notes" maxlength="255" placeholder="Explain any difference"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-warning"><i class="bi bi-lock"></i><span>Close till</span></button></div>
  </form>
</div>

<?php ob_start(); ?>
<script>
(function () {
  const A = App, supervise = <?= $supervise ? 'true' : 'false' ?>;
  let mine = null, closing = null;
  const kpi = (l, v, tone, icon) => `<div class="stat-card" data-tone="${tone}" style="padding:14px 16px"><div class="stat-top"><span class="stat-label">${l}</span><span class="stat-icon" style="width:36px;height:36px;font-size:16px"><i class="bi bi-${icon}"></i></span></div><div class="stat-value" style="font-size:19px">${v}</div></div>`;

  async function loadMine() {
    let d;
    try { d = (await A.get('tills/mine')).data; } catch (e) { return A.toast(e.message, 'error'); }
    mine = d.session;
    if (!mine) {
      A.$('[data-my-sub]').innerHTML = d.required ? '<span class="text-amber fw-bold">Closed</span> — cash transactions are blocked until you open your till.' : 'Closed. Tills are optional (see Settings → Controls).';
      A.$('[data-my-actions]').innerHTML = '<button class="btn btn-primary" data-open="tillOpenModal"><i class="bi bi-unlock"></i><span>Open till</span></button>';
      A.$('[data-my-kpis]').innerHTML = '<div class="empty" style="grid-column:1/-1;padding:20px"><div class="empty-icon"><i class="bi bi-safe2"></i></div><h4>No open till</h4><p>Open your till to start the day.</p></div>';
      return;
    }
    A.$('[data-my-sub]').innerHTML = `<span class="badge success">Open</span> since ${A.dateTime(mine.opened_at)}${mine.branch_name ? ' · ' + A.esc(mine.branch_name) : ''}`;
    A.$('[data-my-actions]').innerHTML = '<button class="btn btn-warning" data-close-till="mine"><i class="bi bi-lock"></i><span>Close till</span></button>';
    A.$('[data-my-kpis]').innerHTML = kpi('Opening float', A.money(mine.opening_float), 'navy', 'safe2')
      + kpi(`Cash in · ${mine.count_in}`, A.money(mine.cash_in), 'green', 'box-arrow-in-down')
      + kpi(`Cash out · ${mine.count_out}`, A.money(mine.cash_out), 'rose', 'box-arrow-up')
      + kpi('Expected in drawer', A.money(mine.expected_now), 'teal', 'calculator');
  }

  function openClose(s) {
    closing = s;
    A.openForm('tillCloseModal', { id: s.id });
    A.$('[data-close-title]').textContent = `Close till — ${s.user_name}`;
    summary();
  }
  function summary() {
    const f = A.$('#tillCloseModal form');
    let total = 0, any = false;
    A.$$('[data-denom]', f).forEach(i => { if (+i.value) { any = true; total += +i.dataset.denom * +i.value; } });
    if (any) f.elements.namedItem('counted_cash').value = total.toFixed(2);
    const counted = +f.elements.namedItem('counted_cash').value || 0;
    const v = Math.round((counted - closing.expected_now) * 100) / 100;
    A.$('[data-close-summary]').innerHTML = `<div class="summary-row"><span>Expected cash</span><strong>${A.money(closing.expected_now)}</strong></div>
      <div class="summary-row"><span>Counted</span><strong>${A.money(counted)}</strong></div>
      <div class="summary-row total"><span>${v === 0 ? 'Balanced' : v < 0 ? 'Shortage' : 'Overage'}</span><strong class="${v === 0 ? 'text-green' : v < 0 ? 'text-red' : 'text-amber'}">${A.money(Math.abs(v))}</strong></div>`;
  }
  A.$('#tillCloseModal form').addEventListener('input', e => { if (e.target.name === 'counted_cash') A.$$('[data-denom]').forEach(i => (i.value = '')); summary(); });

  const table = new A.DataTable('#tillTable', {
    endpoint: 'tills/list', filters: '#tillFilters', empty: 'No till sessions', emptyIcon: 'safe2',
    columns: [
      { label: 'Teller', render: r => `<div class="cell-member">${A.avatar(r.user_name, 'sm')}<div class="meta"><strong>${A.esc(r.user_name)}</strong><span>${A.esc(r.branch_name || 'Head office')}</span></div></div>` },
      { label: 'Opened', render: r => `<span class="nowrap">${A.dateTime(r.opened_at)}</span>` },
      { label: 'Closed', hideSm: true, render: r => r.closed_at ? `<span class="nowrap">${A.dateTime(r.closed_at)}</span>${r.closed_by_name && r.closed_by_name !== r.user_name ? `<div class="text-xs muted">by ${A.esc(r.closed_by_name)}</div>` : ''}` : '—' },
      { label: 'Float', align: 'right', hideSm: true, render: r => A.money(r.opening_float, false) },
      { label: 'Expected', align: 'right', render: r => A.money(r.expected_cash, false) },
      { label: 'Counted', align: 'right', render: r => r.counted_cash !== null ? A.money(r.counted_cash, false) : '—' },
      { label: 'Variance', align: 'right', render: r => r.variance === null ? '—' : +r.variance === 0 ? '<span class="badge success">Balanced</span>' : `<span class="${+r.variance < 0 ? 'text-red' : 'text-amber'} fw-bold">${A.money(r.variance, false)}</span>` },
      { label: 'Status', render: r => A.badge(r.status === 'open' ? 'active' : 'closed', A.label(r.status)) },
      { label: '', align: 'right', render: r => r.status === 'open' && (supervise || r.user_id === A.cfg.user.id) ? '<button class="act" data-action="close" title="Close till"><i class="bi bi-lock"></i></button>' : '' },
    ],
    onAction: async (a, r) => { if (a === 'close') openClose((await A.get('tills/get', { id: r.id })).data); },
  });

  document.addEventListener('click', e => { if (e.target.closest('[data-close-till="mine"]') && mine) openClose(mine); });
  document.addEventListener('app:saved', () => { loadMine(); setTimeout(() => location.reload(), 900); });
  loadMine();
  setInterval(() => { if (!document.hidden && mine) loadMine(); }, 30000);
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
