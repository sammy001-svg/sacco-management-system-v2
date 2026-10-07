<?php
defined('SACCO') or exit;
$products = DB::all('SELECT id, name, interest_rate FROM savings_products WHERE interest_rate > 0 ORDER BY name');
$headOffice = !(Auth::user()['branch_id'] ?? null);
$lastYear = (int) date('Y') - 1;
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Accounting</span><i class="bi bi-chevron-right"></i><span>Interest &amp; dividends</span></div>
    <h1>Interest &amp; dividends</h1>
    <p>Calculate, review and credit savings interest and share dividends to members' savings accounts.</p>
  </div>
  <div class="page-actions">
    <?php if ($headOffice): ?><button class="btn btn-primary" data-open="runModal"><i class="bi bi-plus-lg"></i><span>New run</span></button><?php endif; ?>
  </div>
</div>

<?php if (!$headOffice): ?>
  <div class="info-strip warning"><i class="bi bi-building-lock"></i><span>Interest and dividend runs are SACCO-wide and are managed by head office.</span></div>
<?php else: ?>
<section class="card"><div id="runsTable"></div></section>

<div class="modal" id="runModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="distributions/create" novalidate>
    <div class="modal-head"><div class="modal-icon"><i class="bi bi-percent"></i></div><div class="modal-title"><h3>New interest / dividend run</h3><p>Nothing is posted until you review the run and press Post.</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <div class="field"><label>Run type</label>
        <div class="segmented"><label><input type="radio" name="kind" value="savings_interest" checked><span><i class="bi bi-piggy-bank"></i>Savings interest</span></label><label><input type="radio" name="kind" value="dividend"><span><i class="bi bi-pie-chart"></i>Dividend on shares</span></label></div></div>
      <div class="field"><label>Title <span class="req">*</span></label><input class="input" name="title" required maxlength="120" data-default="Interest on savings <?= $lastYear ?>" value="Interest on savings <?= $lastYear ?>"></div>
      <div class="form-grid">
        <div class="field"><label>Period start <span class="req">*</span></label><input class="input" type="date" name="period_from" required data-default="<?= $lastYear ?>-01-01" value="<?= $lastYear ?>-01-01"></div>
        <div class="field"><label>Period end <span class="req">*</span></label><input class="input" type="date" name="period_to" required data-default="<?= $lastYear ?>-12-31" value="<?= $lastYear ?>-12-31" max="<?= today() ?>"></div>
        <div class="field"><label>Basis</label><select class="select" name="method"><option value="average">Average daily balance</option><option value="closing">Balance at period end</option></select></div>
        <div class="field" data-show-when="kind:dividend"><label>Dividend rate (%) <span class="req">*</span></label><input class="input" type="number" name="rate" min="0.001" max="100" step="0.001" required placeholder="e.g. 12"></div>
        <div class="field" data-show-when="kind:savings_interest"><label>Products</label><select class="select" name="product_id"><option value="">All interest-bearing products</option><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (<?= (float) $p['interest_rate'] ?>% p.a.)</option><?php endforeach; ?></select></div>
        <div class="field"><label>Withholding tax (%)</label><input class="input" type="number" name="wht_rate" min="0" max="100" step="0.001" required data-default="<?= e((string) (float) setting('wht_interest_rate', 0)) ?>" value="<?= e((string) (float) setting('wht_interest_rate', 0)) ?>"></div>
        <div class="field"><label>Posting date <span class="req">*</span></label><input class="input" type="date" name="posting_date" required data-default="today" max="<?= today() ?>"></div>
      </div>
      <div class="info-strip"><i class="bi bi-info-circle"></i><span data-kind-hint>Interest uses each product's annual rate, pro-rated for the days in the period.</span></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-calculator"></i><span>Calculate</span></button></div>
  </form>
</div>

<div class="modal modal-xl" id="runView" role="dialog" aria-modal="true" aria-hidden="true" data-static>
  <div class="modal-dialog">
    <div class="modal-head"><div class="modal-icon navy"><i class="bi bi-percent"></i></div><div class="modal-title"><h3 data-r-title>Run</h3><p data-r-sub></p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <div data-r-progress hidden class="mb-3"><div class="flex justify-between text-sm mb-1"><strong data-r-plabel>Working…</strong><span data-r-pcount></span></div><div class="progress"><span data-r-pbar style="width:0"></span></div></div>
      <div class="grid-kpi mb-3" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px" data-r-kpis></div>
      <form class="toolbar" id="lineFilters" style="padding:0 0 12px" onsubmit="return false">
        <div class="search"><i class="bi bi-search"></i><input class="input" type="search" name="search" placeholder="Search member…"></div>
        <select class="select" name="status"><option value="">All lines</option><option value="pending">Pending</option><option value="posted">Posted</option><option value="skipped">Skipped</option></select>
      </form>
      <div id="linesTable"></div>
    </div>
    <div class="modal-foot" data-r-actions></div>
  </div>
</div>
<?php endif; ?>

<?php ob_start(); ?>
<script>
(function () {
  const A = App;
  if (!A.$('#runsTable')) return;
  const whtDefaults = { savings_interest: <?= json_encode((float) setting('wht_interest_rate', 0)) ?>, dividend: <?= json_encode((float) setting('wht_dividend_rate', 0)) ?> };
  const kinds = <?= json_encode(DistributionService::KINDS) ?>;
  const tones = { computing: 'warning', draft: 'info', posting: 'warning', posted: 'success' };
  let lines = null, current = null, busy = false;

  const runs = new A.DataTable('#runsTable', {
    endpoint: 'distributions/list', empty: 'No runs yet', emptyIcon: 'percent', emptyHint: 'Create a run to calculate interest or dividends.',
    onRowClick: r => openRun(r.id),
    columns: [
      { label: 'Run', render: r => `<span class="strong">${A.esc(r.title)}</span><div class="text-xs muted">${A.esc(kinds[r.kind])}${r.product_name ? ' · ' + A.esc(r.product_name) : ''}</div>` },
      { label: 'Period', render: r => `<span class="nowrap text-sm">${A.date(r.period_from)} – ${A.date(r.period_to)}</span>` },
      { label: 'Rate', hideSm: true, render: r => r.kind === 'dividend' ? `${+r.rate}%` : 'Product rates' },
      { label: 'Members', align: 'right', render: r => A.num(r.line_count) },
      { label: 'Gross', align: 'right', render: r => A.money(r.gross, false) },
      { label: 'Net credited', align: 'right', render: r => `<span class="strong">${A.money(r.net, false)}</span>` },
      { label: 'Status', render: r => `<span class="badge ${tones[r.status]}">${A.label(r.status)}</span>` },
    ],
  });

  // Form: hint + WHT default follow the selected kind
  const form = A.$('#runModal form');
  form.addEventListener('change', e => {
    if (e.target.name !== 'kind') return;
    const div = e.target.value === 'dividend';
    form.elements.namedItem('wht_rate').value = whtDefaults[e.target.value];
    form.elements.namedItem('title').value = (div ? 'Dividend on shares ' : 'Interest on savings ') + <?= $lastYear ?>;
    A.$('[data-kind-hint]', form).textContent = div ? 'The dividend rate applies to each member\'s share capital for the period and is credited to their savings account.' : 'Interest uses each product\'s annual rate, pro-rated for the days in the period.';
  });
  form.addEventListener('form:success', e => { if (e.detail.id) setTimeout(() => openRun(e.detail.id, true), 250); });

  const m = A.$('#runView');
  const kpi = (l, v, tone) => `<div class="stat-card" data-tone="${tone}" style="padding:14px 16px"><span class="stat-label">${l}</span><div class="stat-value" style="font-size:18px">${v}</div></div>`;

  function render(r) {
    current = r;
    A.$('[data-r-title]', m).innerHTML = `${A.esc(r.title)} &nbsp;<span class="badge ${tones[r.status]}">${A.label(r.status)}</span>`;
    A.$('[data-r-sub]', m).textContent = `${kinds[r.kind]} · ${A.date(r.period_from)} – ${A.date(r.period_to)} · ${r.method === 'average' ? 'average daily balance' : 'closing balance'}${r.kind === 'dividend' ? ` · ${+r.rate}%` : ''} · WHT ${+r.wht_rate}% · posting date ${A.date(r.posting_date)}`;
    A.$('[data-r-kpis]', m).innerHTML = kpi('Members', A.num(r.line_count), 'navy') + kpi('Gross', A.money(r.gross), 'teal') + kpi('Withholding tax', A.money(r.tax), 'amber') + kpi('Net to savings', A.money(r.net), 'green');
    const act = A.$('[data-r-actions]', m);
    const btns = [];
    if (['computing', 'draft'].includes(r.status) && !busy) btns.push(`<button class="btn btn-ghost text-red left" data-run-delete><i class="bi bi-trash3"></i><span>Delete draft</span></button>`);
    if (['draft', 'posting'].includes(r.status) && r.can_post && !busy) btns.push(`<button class="btn btn-primary" data-run-post><i class="bi bi-send-check"></i><span>${r.status === 'posting' ? 'Resume posting' : 'Post to members'}</span></button>`);
    btns.push(`<button class="btn ${btns.length ? '' : 'btn-primary'}" data-close ${busy ? 'disabled' : ''}>Close</button>`);
    act.innerHTML = btns.join('');
  }

  function progress(label, done, total) {
    const p = A.$('[data-r-progress]', m);
    p.hidden = !label;
    if (!label) return;
    A.$('[data-r-plabel]', m).textContent = label;
    A.$('[data-r-pcount]', m).textContent = total ? `${A.num(done)} / ${A.num(total)}` : A.num(done);
    A.$('[data-r-pbar]', m).style.width = (total ? Math.min(100, done / total * 100) : 100) + '%';
  }

  async function loop(action, label, total) {
    busy = true; render(current);
    try {
      while (true) {
        const r = (await A.post('distributions/' + action, { id: current.id })).data;
        Object.assign(current, r);
        progress(label, action === 'compute' ? +r.scanned : +r.posted_count + +r.skipped_count, action === 'compute' ? 0 : +r.line_count);
        render(current);
        if ((action === 'compute' && r.status !== 'computing') || (action === 'post' && r.status === 'posted')) break;
      }
    } catch (e) { A.toast(e.message, 'error'); }
    busy = false;
    progress(null);
    current = (await A.get('distributions/get', { id: current.id })).data;
    render(current);
    lines && lines.reload(true);
    runs.reload();
  }

  async function openRun(id, compute = false) {
    current = (await A.get('distributions/get', { id })).data;
    render(current);
    A.Modal.open(m);
    if (!lines) {
      lines = new A.DataTable('#linesTable', {
        endpoint: 'distributions/lines', filters: '#lineFilters', params: () => ({ id: current.id }), perPage: 15, compact: true, empty: 'No lines yet', emptyIcon: 'list',
        columns: [
          { label: 'Member', render: l => `<a href="index.php?page=member&id=${l.member_id}" class="strong">${A.esc(l.first_name + ' ' + l.last_name)}</a><div class="text-xs muted">${A.esc(l.member_no)}</div>` },
          { label: 'Basis', sort: 'basis', align: 'right', render: l => A.money(l.basis, false) },
          { label: 'Rate', align: 'right', hideSm: true, render: l => `${+l.rate}%` },
          { label: 'Gross', sort: 'gross', align: 'right', render: l => A.money(l.gross, false) },
          { label: 'Tax', align: 'right', hideSm: true, render: l => A.money(l.tax, false) },
          { label: 'Net', align: 'right', render: l => `<span class="strong">${A.money(l.net, false)}</span>` },
          { label: 'Status', render: l => A.badge(l.status === 'posted' ? 'posted' : l.status === 'skipped' ? 'inactive' : 'pending', A.label(l.status)) + (l.txn_ref ? `<div class="text-xs mono muted">${A.esc(l.txn_ref)}</div>` : '') },
        ],
      });
    } else lines.reload(true);
    if (compute || current.status === 'computing') loop('compute', 'Calculating balances…');
  }

  m.addEventListener('click', async e => {
    if (e.target.closest('[data-run-post]')) {
      const ok = await A.confirm({ title: 'Post this run?', message: `${A.money(current.net)} will be credited to ${A.num(current.line_count)} members' savings accounts. This cannot be deleted afterwards.`, confirmText: 'Post to members', tone: 'warning', icon: 'send-check' });
      if (ok) loop('post', 'Crediting members…', +current.line_count);
    }
    if (e.target.closest('[data-run-delete]')) {
      if (!await A.confirm({ title: 'Delete this draft run?', confirmText: 'Delete draft' })) return;
      try { A.toast((await A.post('distributions/delete', { id: current.id })).message); A.Modal.close(m); runs.reload(); } catch (err) { A.toast(err.message, 'error'); }
    }
  });
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
