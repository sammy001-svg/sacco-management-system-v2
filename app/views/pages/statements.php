<?php
defined('SACCO') or exit;
$lock = Accounting::lockedThrough();
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Accounting</span><i class="bi bi-chevron-right"></i><span>Financial statements</span></div>
    <h1>Financial statements</h1>
    <p>Double-entry statements generated live from the general ledger.<?= $lock ? ' Books closed through <strong>' . e(fmt_date($lock)) . '</strong>.' : '' ?></p>
  </div>
  <div class="page-actions no-print">
    <button class="btn" type="button" id="exportTb"><i class="bi bi-download"></i><span>Trial balance CSV</span></button>
    <button class="btn btn-primary" type="button" onclick="window.print()"><i class="bi bi-printer"></i><span>Print</span></button>
  </div>
</div>

<div class="grid-kpi mb-3">
  <?= kpi_card('Total assets', 'total_assets', 'bank', 'navy', 'money', '', 'featured') ?>
  <?= kpi_card('Total liabilities', 'total_liabilities', 'journal-arrow-down', 'amber', 'money') ?>
  <?= kpi_card('Total equity', 'total_equity', 'shield-check', 'teal', 'money', 'Includes current surplus') ?>
  <?= kpi_card('Surplus (period)', 'surplus', 'graph-up-arrow', 'green', 'money', 'Income less expenses') ?>
</div>

<section class="card mb-3 no-print">
  <form class="toolbar" id="stmtFilters" onsubmit="return false">
    <div class="tabs" data-tabs="stmt">
      <button type="button" class="tab active" data-tab="st-tb"><i class="bi bi-list-columns"></i>Trial balance</button>
      <button type="button" class="tab" data-tab="st-is"><i class="bi bi-graph-up"></i>Income statement</button>
      <button type="button" class="tab" data-tab="st-bs"><i class="bi bi-bank"></i>Balance sheet</button>
      <button type="button" class="tab" data-tab="st-rec"><i class="bi bi-check2-square"></i>Reconciliation</button>
    </div>
    <label class="text-sm muted fw-bold" for="asAt">As at</label>
    <input class="input" type="date" id="asAt" name="as_at" value="<?= today() ?>">
    <label class="text-sm muted fw-bold" for="pFrom">Period</label>
    <input class="input" type="date" id="pFrom" name="date_from" value="<?= date('Y-01-01') ?>">
    <input class="input" type="date" name="date_to" value="<?= today() ?>" aria-label="Period end">
    <?= branch_filter() ?>
  </form>
</section>

<div class="tab-panel active" id="st-tb" data-group="stmt">
  <section class="card"><div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-list-columns"></i></span>Trial balance</h3><p data-tb-sub></p></div><span data-tb-check></span></div>
    <div class="card-body flush"><div class="table-wrap"><table class="table compact">
      <thead><tr><th>Code</th><th>Account</th><th class="hide-sm">Type</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
      <tbody data-tb></tbody><tfoot data-tb-foot></tfoot></table></div></div></section>
</div>

<div class="tab-panel" id="st-is" data-group="stmt">
  <section class="card"><div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-graph-up"></i></span>Income statement</h3><p data-is-sub></p></div></div>
    <div class="card-body flush"><div class="table-wrap"><table class="table compact"><tbody data-is></tbody></table></div></div></section>
</div>

<div class="tab-panel" id="st-bs" data-group="stmt">
  <section class="card"><div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-bank"></i></span>Balance sheet</h3><p data-bs-sub></p></div><span data-bs-check></span></div>
    <div class="card-body flush"><div class="table-wrap"><table class="table compact"><tbody data-bs></tbody></table></div></div></section>
</div>

<div class="tab-panel" id="st-rec" data-group="stmt">
  <section class="card"><div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-check2-square"></i></span>Ledger reconciliation</h3><p>The general ledger must agree with the member sub-ledgers at all times.</p></div></div>
    <div class="card-body flush"><div class="table-wrap"><table class="table compact">
      <thead><tr><th>Check</th><th class="num">General ledger</th><th class="num">Sub-ledger</th><th class="num">Difference</th><th>Result</th></tr></thead>
      <tbody data-rec></tbody></table></div></div></section>
</div>

<?php ob_start(); ?>
<script>
(function () {
  const A = App, form = A.$('#stmtFilters');
  const P = () => Object.fromEntries(new FormData(form));
  const m = v => A.money(v, false);
  const head = t => `<tr><td colspan="3" class="text-xs fw-bold muted" style="text-transform:uppercase;letter-spacing:.08em;padding-top:16px">${t}</td></tr>`;
  const line = (code, name, v, cls = '') => `<tr class="${cls}"><td class="mono" style="width:90px">${A.esc(code)}</td><td>${name}</td><td class="num ${cls ? 'strong' : ''}">${m(v)}</td></tr>`;
  const total = (name, v, cls = '') => `<tr><td></td><td class="strong">${name}</td><td class="num strong ${cls}" style="border-top:2px solid var(--border-strong)">${m(v)}</td></tr>`;
  const ok = (good, text) => `<span class="badge ${good ? 'success' : 'danger'}">${good ? 'Balanced' : text || 'Out of balance'}</span>`;

  async function load() {
    const p = P();
    try {
      const [tb, is, bs, rec] = await Promise.all([
        A.get('accounting/trial_balance', p), A.get('accounting/income_statement', p), A.get('accounting/balance_sheet', p), A.get('accounting/reconciliation'),
      ]);
      const T = tb.data, I = is.data, B = bs.data;
      A.fillKpis(document, { total_assets: B.total_assets, total_liabilities: B.total_liabilities, total_equity: B.total_equity, surplus: I.surplus });

      A.$('[data-tb-sub]').textContent = `As at ${A.date(T.as_at)}`;
      A.$('[data-tb-check]').innerHTML = ok(T.balanced);
      A.$('[data-tb]').innerHTML = T.rows.length ? T.rows.map(r => `<tr><td class="mono">${A.esc(r.code)}</td><td><a href="index.php?page=accounts&open=${r.id}">${A.esc(r.name)}</a></td><td class="hide-sm">${A.esc(A.label(r.type))}</td><td class="num">${r.debit ? m(r.debit) : ''}</td><td class="num">${r.credit ? m(r.credit) : ''}</td></tr>`).join('')
        : '<tr><td colspan="5"><div class="empty">No postings yet.</div></td></tr>';
      A.$('[data-tb-foot]').innerHTML = `<tr><td></td><td>Total</td><td class="hide-sm"></td><td class="num">${m(T.debit)}</td><td class="num">${m(T.credit)}</td></tr>`;

      A.$('[data-is-sub]').textContent = `${A.date(I.from)} to ${A.date(I.to)}`;
      A.$('[data-is]').innerHTML = head('Income') + I.income.map(r => line(r.code, A.esc(r.name), r.balance)).join('')
        + total('Total income', I.total_income, 'text-green')
        + head('Expenses') + I.expenses.map(r => line(r.code, A.esc(r.name), r.balance)).join('')
        + total('Total expenses', I.total_expenses, 'text-red')
        + total(I.surplus >= 0 ? 'Surplus for the period' : 'Deficit for the period', I.surplus, I.surplus >= 0 ? 'text-green' : 'text-red');

      A.$('[data-bs-sub]').textContent = `As at ${A.date(B.as_at)}`;
      A.$('[data-bs-check]').innerHTML = ok(B.balanced, 'Assets ≠ liabilities + equity');
      A.$('[data-bs]').innerHTML = head('Assets') + B.assets.map(r => line(r.code, A.esc(r.name), r.balance)).join('') + total('Total assets', B.total_assets)
        + head('Liabilities') + B.liabilities.map(r => line(r.code, A.esc(r.name), r.balance)).join('') + total('Total liabilities', B.total_liabilities)
        + head('Equity') + B.equity.map(r => line(r.code, A.esc(r.name), r.balance)).join('')
        + line('', '<em>Current surplus (not yet closed)</em>', B.current_surplus)
        + total('Total equity', B.total_equity)
        + total('Total liabilities and equity', B.total_liabilities + B.total_equity);

      A.$('[data-rec]').innerHTML = rec.data.map(c => `<tr><td>${A.esc(c.label)}</td><td class="num">${c.count ? A.num(c.ledger) : m(c.ledger)}</td><td class="num">${c.count ? A.num(c.subledger) : m(c.subledger)}</td><td class="num ${c.ok ? '' : 'text-red strong'}">${c.count ? A.num(c.difference) : m(c.difference)}</td><td>${c.ok ? '<span class="badge success">Agrees</span>' : '<span class="badge danger">Investigate</span>'}</td></tr>`).join('');
    } catch (e) { A.toast(e.message, 'error'); }
  }
  form.addEventListener('change', load);
  A.$('#exportTb').addEventListener('click', () => (location.href = 'api.php?r=accounting/trial_balance&export=1&' + new URLSearchParams(P())));
  load();
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
