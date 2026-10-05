<?php
defined('SACCO') or exit;
$needsCharts = true;
$user = Auth::user();
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$firstName = explode(' ', $user['name'])[0];
?>
<div class="page-head">
  <div>
    <span class="eyebrow"><i class="bi bi-calendar3"></i> <?= date('l, j F Y') ?></span>
    <h1><?= e($greeting) ?>, <?= e($firstName) ?> <span aria-hidden="true">👋</span></h1>
    <p>Here's how <?= e(setting('sacco_name')) ?> is performing<?= $user['branch_name'] ? ' at ' . e($user['branch_name']) : ' across all branches' ?>.</p>
  </div>
  <div class="page-actions">
    <?php if (Auth::can('reports.view')): ?><a class="btn" href="index.php?page=reports"><i class="bi bi-bar-chart-line"></i><span>Reports</span></a><?php endif; ?>
    <?php if (Auth::can('savings.transact')): ?><button class="btn btn-primary" data-open="depositModal"><i class="bi bi-arrow-down-left-circle"></i><span>Receive deposit</span></button><?php endif; ?>
  </div>
</div>

<div class="grid-kpi mb-3" id="kpiMain">
  <div class="stat-card featured">
    <div class="stat-top"><span class="stat-label">Total member savings</span><span class="stat-icon"><i class="bi bi-piggy-bank"></i></span></div>
    <div class="stat-value" data-kpi="savings" data-format="money"><span class="skeleton" style="width:70%;height:24px;opacity:.3"></span></div>
    <div data-spark="deposits"></div>
    <div class="stat-meta"><span class="trend up" data-kpi-text="deposits_month"></span> deposited this month</div>
  </div>
  <?= kpi_card('Loan portfolio', 'portfolio', 'cash-stack', 'navy', 'money', '<span class="trend" data-par></span> PAR 30 · <span data-kpi-text="loans_active"></span> active loans', '', 'repaid') ?>
  <?= kpi_card('Share capital', 'shares', 'pie-chart', 'teal', 'money', '<span data-kpi-text="shares_month"></span> bought this month', '', 'shares') ?>
  <?= kpi_card('Members', 'members', 'people', 'blue', 'num', '<span class="trend up" data-kpi-text="members_new"></span> joined this month', '', 'growth') ?>
</div>

<div class="grid-kpi mb-3">
  <?= kpi_card('Repayments this month', 'repaid_month', 'cash-coin', 'green', 'money') ?>
  <?= kpi_card('Disbursed this month', 'disbursed_month', 'send-check', 'amber', 'money') ?>
  <?= kpi_card('Income this month', 'income_month', 'graph-up-arrow', 'teal', 'money', 'Interest, penalties &amp; fees') ?>
  <?= kpi_card('Awaiting action', 'loans_pending', 'hourglass-split', 'rose', 'num', '<span data-kpi-text="loans_approved"></span> approved, awaiting disbursement') ?>
</div>

<div class="grid-main mb-3">
  <section class="card">
    <div class="card-head">
      <div><h3><span class="h-icon"><i class="bi bi-bar-chart"></i></span>Savings flow</h3><p>Member deposits vs withdrawals, last 12 months</p></div>
      <div class="legend"><span><i style="background:var(--chart-1)"></i>Deposits</span><span><i style="background:var(--chart-2)"></i>Withdrawals</span></div>
    </div>
    <div class="card-body"><div class="chart-box"><canvas id="chartSavings" role="img" aria-label="Monthly deposits and withdrawals"></canvas></div></div>
  </section>

  <section class="card">
    <div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-heart-pulse"></i></span>Portfolio health</h3><p>Risk and liquidity at a glance</p></div></div>
    <div class="card-body">
      <div class="flex justify-between items-center mb-1"><span class="muted text-sm fw-bold">Portfolio at risk (30+ days)</span><strong data-par-big>—</strong></div>
      <div class="progress mb-2"><span data-par-bar style="width:0"></span></div>
      <div class="flex justify-between items-center mb-1"><span class="muted text-sm fw-bold">Liquidity (savings + shares − loans)</span><strong data-liq-big>—</strong></div>
      <div class="progress mb-3"><span data-liq-bar style="width:0"></span></div>
      <div class="form-section" style="margin-top:4px">Outstanding by product</div>
      <div data-products><span class="skeleton"></span></div>
    </div>
  </section>
</div>

<div class="grid-2 mb-3">
  <section class="card">
    <div class="card-head">
      <div><h3><span class="h-icon"><i class="bi bi-arrow-repeat"></i></span>Lending activity</h3><p>Repayments collected vs loans disbursed</p></div>
      <div class="legend"><span><i style="background:var(--chart-1)"></i>Repayments</span><span><i style="background:var(--chart-2)"></i>Disbursements</span></div>
    </div>
    <div class="card-body"><div class="chart-box sm"><canvas id="chartLoans" role="img" aria-label="Monthly repayments and disbursements"></canvas></div></div>
  </section>
  <section class="card">
    <div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-person-plus"></i></span>Member growth</h3><p>New members joining each month</p></div></div>
    <div class="card-body"><div class="chart-box sm"><canvas id="chartGrowth" role="img" aria-label="New members per month"></canvas></div></div>
  </section>
</div>

<div class="grid-main">
  <section class="card">
    <div class="card-head">
      <div><h3><span class="h-icon"><i class="bi bi-activity"></i></span>Recent transactions</h3><p>Latest ledger postings</p></div>
      <?php if (Auth::can('transactions.view')): ?><a class="btn btn-sm" href="index.php?page=transactions">View ledger <i class="bi bi-arrow-right"></i></a><?php endif; ?>
    </div>
    <div class="card-body flush"><div class="list" data-recent><div class="list-item"><span class="skeleton"></span></div></div></div>
  </section>
  <section class="card">
    <div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-calendar-event"></i></span>Upcoming installments</h3><p>Due in the next 10 days</p></div></div>
    <div class="card-body flush"><div class="list" data-dues><div class="list-item"><span class="skeleton"></span></div></div></div>
  </section>
</div>

<?php ob_start(); ?>
<script>
(function () {
  const A = App;
  const baseOptions = (t, opts = {}) => ({
    responsive: true, maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: { legend: { display: false }, tooltip: A.tooltipStyle(t) },
    scales: {
      x: { grid: { display: false }, border: { display: false }, ticks: { color: t.text, maxRotation: 0, autoSkipPadding: 12 } },
      y: { grid: { color: t.grid }, border: { display: false }, ticks: { color: t.text, callback: v => opts.count ? v : A.compact(v), precision: 0 }, beginAtZero: true },
    },
  });

  async function load() {
    let d;
    try { d = (await A.get('dashboard/summary')).data; } catch (e) { A.toast(e.message, 'error'); return; }
    const k = d.kpis, c = d.charts;

    A.fillKpis(document, k);
    A.$$('[data-kpi-text]').forEach(el => {
      const key = el.dataset.kpiText, v = k[key];
      el.textContent = /month$/.test(key) && key !== 'members_new' ? A.money(v) : A.num(v);
    });
    const par = Number(k.par30);
    const parTone = par > 10 ? 'down' : par > 5 ? 'flat' : 'up';
    A.$('[data-par]').className = 'trend ' + parTone;
    A.$('[data-par]').textContent = par.toFixed(1) + '%';
    A.$('[data-par-big]').textContent = par.toFixed(2) + '%';
    A.$('[data-par-big]').className = par > 10 ? 'text-red' : par > 5 ? 'text-amber' : 'text-green';
    A.$('[data-par-bar]').style.width = Math.min(100, par * 4) + '%';
    A.$('[data-par-bar]').style.background = par > 10 ? '#e11d48' : par > 5 ? '#d97706' : '';
    A.$('[data-liq-big]').textContent = Number(k.liquidity).toFixed(1) + '%';
    A.$('[data-liq-bar]').style.width = Math.max(0, Math.min(100, k.liquidity)) + '%';
    A.$$('[data-spark]').forEach(el => (el.innerHTML = A.sparkline(c[el.dataset.spark] || [])));

    const totalPortfolio = c.by_product.reduce((s, p) => s + Number(p.balance), 0) || 1;
    A.$('[data-products]').innerHTML = c.by_product.length ? c.by_product.map(p => {
      const pct = Number(p.balance) / totalPortfolio * 100;
      return `<div class="mb-2"><div class="flex justify-between text-sm mb-1"><span class="fw-bold">${A.esc(p.name)} <span class="muted">· ${A.num(p.loans)}</span></span><span>${A.money(p.balance)}</span></div><div class="progress"><span style="width:${pct.toFixed(1)}%"></span></div></div>`;
    }).join('') : '<p class="muted text-sm">No active loans yet.</p>';

    A.chart('chartSavings', t => ({
      type: 'bar',
      data: { labels: c.labels, datasets: [
        { label: 'Deposits', data: c.deposits, backgroundColor: t.c1, borderRadius: 4, borderSkipped: 'start', categoryPercentage: .72, barPercentage: .86, maxBarThickness: 22 },
        { label: 'Withdrawals', data: c.withdrawals, backgroundColor: t.c2, borderRadius: 4, borderSkipped: 'start', categoryPercentage: .72, barPercentage: .86, maxBarThickness: 22 },
      ] },
      options: baseOptions(t),
    }));
    A.chart('chartLoans', t => ({
      type: 'line',
      data: { labels: c.labels, datasets: [
        { label: 'Repayments', data: c.repaid, borderColor: t.c1, backgroundColor: t.c1, borderWidth: 2, tension: .35, pointRadius: 0, pointHoverRadius: 5, pointHoverBorderWidth: 2, pointHoverBorderColor: t.surface },
        { label: 'Disbursements', data: c.disbursed, borderColor: t.c2, backgroundColor: t.c2, borderWidth: 2, tension: .35, pointRadius: 0, pointHoverRadius: 5, pointHoverBorderWidth: 2, pointHoverBorderColor: t.surface },
      ] },
      options: baseOptions(t),
    }));
    A.chart('chartGrowth', t => ({
      type: 'line',
      data: { labels: c.labels, datasets: [{
        label: 'New members', data: c.growth, isCount: true, borderColor: t.c1, borderWidth: 2, tension: .35, pointRadius: 0, pointHoverRadius: 5,
        fill: true, backgroundColor: t.c1 + (t.dark ? '33' : '22'),
      }] },
      options: baseOptions(t, { count: true }),
    }));

    A.$('[data-recent]').innerHTML = d.recent.length ? d.recent.map(r => {
      const [name, icon, tone] = A.txnInfo(r.type);
      const who = r.member_id ? `${r.first_name} ${r.last_name}` : 'SACCO';
      const sign = r.flow === 'in' ? '+' : r.flow === 'out' ? '−' : '';
      return `<div class="list-item"><span class="txn-ico ${tone}"><i class="bi bi-${icon}"></i></span>
        <div class="meta"><strong>${A.esc(name)} · ${A.esc(who)}</strong><span class="mono">${A.esc(r.txn_ref)}</span> <span>· ${A.esc(A.METHODS[r.payment_method] || r.payment_method)}</span></div>
        <div class="end"><strong class="${r.flow === 'in' ? 'amount-in' : r.flow === 'out' ? 'amount-out' : ''}">${sign}${A.money(r.amount)}</strong><span>${A.timeAgo(r.created_at)}</span></div></div>`;
    }).join('') : '<div class="empty"><div class="empty-icon"><i class="bi bi-receipt"></i></div><h4>No transactions yet</h4></div>';

    A.$('[data-dues]').innerHTML = d.dues.length ? d.dues.map(r => `<a class="list-item" href="#" data-view-loan="${r.loan_id}">${A.avatar(r.first_name + ' ' + r.last_name, 'sm')}
        <div class="meta"><strong>${A.esc(r.first_name + ' ' + r.last_name)}</strong><span>${A.esc(r.loan_no)} · #${r.installment_no} · ${A.esc(r.phone)}</span></div>
        <div class="end"><strong>${A.money(r.amount)}</strong><span>${A.date(r.due_date)}</span></div></a>`).join('')
      : '<div class="empty"><div class="empty-icon"><i class="bi bi-calendar-check"></i></div><h4>Nothing due soon</h4><p>No installments in the next 10 days.</p></div>';
  }

  document.addEventListener('click', e => { const a = e.target.closest('[data-view-loan]'); if (a) { e.preventDefault(); A.loans.view(a.dataset.viewLoan); } });
  document.addEventListener('app:saved', A.debounce(load, 400));
  load();
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
