<?php
defined('SACCO') or exit;
$needsCharts = true;
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Reports</span></div>
    <h1>Financial reports</h1>
    <p>Income statement, cash flow, portfolio quality and membership for any period.</p>
  </div>
  <div class="page-actions no-print">
    <button class="btn" type="button" id="exportLedger"><i class="bi bi-download"></i><span>Ledger CSV</span></button>
    <button class="btn btn-primary" type="button" onclick="window.print()"><i class="bi bi-printer"></i><span>Print report</span></button>
  </div>
</div>

<section class="card mb-3 no-print">
  <form class="toolbar" id="reportFilters" onsubmit="return false">
    <div class="tabs" data-tabs="range">
      <button type="button" class="tab active" data-tab="r-month" data-range="month">This month</button>
      <button type="button" class="tab" data-tab="r-last" data-range="last">Last month</button>
      <button type="button" class="tab" data-tab="r-quarter" data-range="quarter">This quarter</button>
      <button type="button" class="tab" data-tab="r-ytd" data-range="ytd">Year to date</button>
      <button type="button" class="tab" data-tab="r-12" data-range="12m">Last 12 months</button>
    </div>
    <input class="input" type="date" name="date_from" aria-label="From">
    <input class="input" type="date" name="date_to" aria-label="To">
    <?= branch_filter() ?>
  </form>
</section>

<p class="muted mb-2" data-period></p>

<div class="grid-kpi mb-3">
  <?= kpi_card('Total income', 'income_total', 'graph-up-arrow', 'green', 'money', 'Interest, penalties &amp; fees', 'featured') ?>
  <?= kpi_card('Operating expenses', 'expense_total', 'wallet2', 'rose', 'money') ?>
  <?= kpi_card('Surplus / (deficit)', 'surplus', 'trophy', 'navy', 'money') ?>
  <?= kpi_card('Net cash flow', 'net', 'arrow-down-up', 'teal', 'money', 'In <strong data-cash-in></strong> · Out <strong data-cash-out></strong>') ?>
</div>

<div class="grid-2 mb-3">
  <section class="card">
    <div class="card-head"><h3><span class="h-icon"><i class="bi bi-journal-text"></i></span>Income statement</h3></div>
    <div class="card-body flush"><div class="table-wrap"><table class="table compact"><tbody data-income></tbody></table></div></div>
  </section>
  <section class="card">
    <div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-shield-exclamation"></i></span>Portfolio at risk</h3><p>Outstanding balance by days past due, as of today</p></div></div>
    <div class="card-body"><div class="chart-box sm"><canvas id="chartPar" role="img" aria-label="Portfolio at risk by ageing bucket"></canvas></div></div>
    <div class="table-wrap"><table class="table compact"><thead><tr><th>Bucket</th><th class="num">Loans</th><th class="num">Outstanding</th><th class="num">Share</th></tr></thead><tbody data-par></tbody></table></div>
  </section>
</div>

<div class="grid-2 mb-3">
  <section class="card">
    <div class="card-head"><h3><span class="h-icon"><i class="bi bi-arrow-left-right"></i></span>Ledger activity</h3></div>
    <div class="card-body flush"><div class="table-wrap"><table class="table compact"><thead><tr><th>Type</th><th>Flow</th><th class="num">Count</th><th class="num">Amount</th></tr></thead><tbody data-activity></tbody></table></div></div>
  </section>
  <section class="card">
    <div class="card-head"><h3><span class="h-icon"><i class="bi bi-box-seam"></i></span>Loan products</h3></div>
    <div class="card-body flush"><div class="table-wrap"><table class="table compact"><thead><tr><th>Product</th><th class="num">Active</th><th class="num">Outstanding</th><th class="num">Disbursed (period)</th></tr></thead><tbody data-products></tbody></table></div></div>
  </section>
</div>

<div class="grid-2">
  <section class="card">
    <div class="card-head"><h3><span class="h-icon"><i class="bi bi-trophy"></i></span>Top savers</h3></div>
    <div class="card-body flush"><div class="list" data-savers></div></div>
  </section>
  <section class="card">
    <div class="card-head"><h3><span class="h-icon"><i class="bi bi-people"></i></span>Membership</h3></div>
    <div class="card-body"><div class="kv" data-membership></div></div>
  </section>
</div>

<?php ob_start(); ?>
<script>
(function () {
  const A = App, form = A.$('#reportFilters');
  const F = n => form.elements.namedItem(n);
  const iso = d => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  function setRange(r) {
    const now = new Date(); let from, to = now;
    if (r === 'month') from = new Date(now.getFullYear(), now.getMonth(), 1);
    if (r === 'last') { from = new Date(now.getFullYear(), now.getMonth() - 1, 1); to = new Date(now.getFullYear(), now.getMonth(), 0); }
    if (r === 'quarter') from = new Date(now.getFullYear(), Math.floor(now.getMonth() / 3) * 3, 1);
    if (r === 'ytd') from = new Date(now.getFullYear(), 0, 1);
    if (r === '12m') from = new Date(now.getFullYear(), now.getMonth() - 11, 1);
    F('date_from').value = iso(from); F('date_to').value = iso(to);
  }
  const row = (k, v, cls = '') => `<tr class="${cls}"><td>${k}</td><td class="num ${cls ? 'strong' : ''}">${v}</td></tr>`;

  async function load() {
    let d;
    try { d = (await A.get('reports/summary', Object.fromEntries(new FormData(form)))).data; } catch (e) { A.toast(e.message, 'error'); return; }
    A.$('[data-period]').innerHTML = `<i class="bi bi-calendar-range"></i> Period: <strong>${A.date(d.period.from)}</strong> to <strong>${A.date(d.period.to)}</strong>`;
    A.fillKpis(document, { income_total: d.income_total, expense_total: d.expense_total, surplus: d.surplus, net: d.cash.net });
    A.$('[data-cash-in]').textContent = A.compact(d.cash.in);
    A.$('[data-cash-out]').textContent = A.compact(d.cash.out);

    A.$('[data-income]').innerHTML =
      `<tr><td colspan="2" class="text-xs fw-bold muted" style="text-transform:uppercase;letter-spacing:.08em">Income</td></tr>`
      + d.income.map(i => row(A.esc(i.label), A.money(i.amount, false))).join('')
      + row('<strong>Total income</strong>', A.money(d.income_total, false), 'text-green')
      + `<tr><td colspan="2" class="text-xs fw-bold muted" style="text-transform:uppercase;letter-spacing:.08em">Expenses</td></tr>`
      + (d.expenses.length ? d.expenses.map(e => row(A.esc(e.label), A.money(e.amount, false))).join('') : row('<span class="muted">No expenses</span>', '—'))
      + row('<strong>Total expenses</strong>', A.money(d.expense_total, false), 'text-red')
      + row(`<strong>${d.surplus >= 0 ? 'Surplus' : 'Deficit'}</strong>`, A.money(d.surplus, false), d.surplus >= 0 ? 'text-green' : 'text-red');

    A.$('[data-par]').innerHTML = d.par.map(p => `<tr><td>${A.esc(p.bucket)}</td><td class="num">${A.num(p.loans)}</td><td class="num strong">${A.money(p.balance, false)}</td><td class="num">${p.share.toFixed(1)}%</td></tr>`).join('')
      + `<tr><td class="strong">Total</td><td class="num strong">${A.num(d.par.reduce((s, p) => s + p.loans, 0))}</td><td class="num strong">${A.money(d.portfolio, false)}</td><td class="num">100%</td></tr>`;

    A.chart('chartPar', t => ({
      type: 'bar',
      data: { labels: d.par.map(p => p.bucket), datasets: [{ label: 'Outstanding', data: d.par.map(p => p.balance), backgroundColor: d.par.map((p, i) => i === 0 ? t.c1 : t.c3), borderRadius: 4, borderSkipped: 'start', maxBarThickness: 26 }] },
      options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: A.tooltipStyle(t) },
        scales: { x: { grid: { color: t.grid }, border: { display: false }, ticks: { color: t.text, callback: v => A.compact(v) } }, y: { grid: { display: false }, border: { display: false }, ticks: { color: t.text } } },
      },
    }));

    A.$('[data-activity]').innerHTML = d.activity.length ? d.activity.map(a => `<tr><td>${A.esc(a.label)}</td><td>${A.badge(a.flow, a.flow === 'none' ? 'Non-cash' : a.flow === 'in' ? 'Cash in' : 'Cash out')}</td><td class="num">${A.num(a.n)}</td><td class="num strong">${A.money(a.total, false)}</td></tr>`).join('')
      : '<tr><td colspan="4"><div class="empty">No activity in this period</div></td></tr>';
    A.$('[data-products]').innerHTML = d.products.map(p => `<tr><td class="strong">${A.esc(p.name)}</td><td class="num">${A.num(p.active)}</td><td class="num">${A.money(p.outstanding, false)}</td><td class="num">${A.money(p.disbursed, false)} <span class="muted text-xs">(${A.num(p.disbursed_count)})</span></td></tr>`).join('');
    A.$('[data-savers]').innerHTML = d.top_savers.map((s, i) => `<a class="list-item" href="index.php?page=member&id=${s.id}"><span class="avatar sm" data-hue="${i % 6}">${i + 1}</span><div class="meta"><strong>${A.esc(s.first_name + ' ' + s.last_name)}</strong><span>${A.esc(s.member_no)}</span></div><div class="end"><strong>${A.money(s.total)}</strong></div></a>`).join('') || '<div class="empty">No savers yet</div>';
    const m = d.membership;
    A.$('[data-membership]').innerHTML = [['Joined in period', A.num(m.joined)], ['Active members', A.num(m.active)], ['Exited (all time)', A.num(m.exited)], ['Total registered', A.num(m.total)]]
      .map(([k, v]) => `<div><span>${k}</span><strong style="font-size:22px">${v}</strong></div>`).join('');
  }

  A.$('[data-tabs="range"]').addEventListener('click', e => { const t = e.target.closest('[data-range]'); if (t) { setRange(t.dataset.range); load(); } });
  form.addEventListener('change', e => {
    if (e.target.type === 'date') A.$$('[data-tabs="range"] .tab').forEach(t => t.classList.remove('active'));
    load();
  });
  A.$('#exportLedger').addEventListener('click', () => {
    const p = new URLSearchParams(Object.fromEntries(new FormData(form)));
    location.href = 'api.php?r=transactions/export&' + p.toString();
  });
  setRange('month');
  load();
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
