<?php
defined('SACCO') or exit;
$products = DB::all('SELECT id, name FROM loan_products ORDER BY name');
$status = (string) input('status', '');
$tabs = ['' => ['All loans', 'collection'], 'pending' => ['Pending', 'hourglass-split'], 'approved' => ['Approved', 'patch-check'], 'active' => ['Active', 'lightning-charge'],
    'arrears' => ['In arrears', 'exclamation-octagon'], 'closed' => ['Closed', 'check2-all'], 'rejected' => ['Rejected', 'x-octagon'], 'written_off' => ['Written off', 'journal-x']];
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Loans</span></div>
    <h1>Loans</h1>
    <p>Applications, approvals, disbursements and collections.</p>
  </div>
  <div class="page-actions">
    <button class="btn" type="button" id="exportLoans"><i class="bi bi-download"></i><span>Export</span></button>
    <?php if (Auth::can('loans.repay')): ?><button class="btn" data-open="repayModal"><i class="bi bi-cash-coin"></i><span>Repayment</span></button><?php endif; ?>
    <?php if (Auth::can('loans.apply')): ?><button class="btn btn-primary" data-open="loanApplyModal"><i class="bi bi-file-earmark-plus"></i><span>New application</span></button><?php endif; ?>
  </div>
</div>

<div class="grid-kpi mb-3">
  <?= kpi_card('Outstanding portfolio', 'portfolio', 'cash-stack', 'green', 'money', '<span data-txt="active"></span> active loans', 'featured') ?>
  <?= kpi_card('Portfolio at risk (30+)', 'par30', 'activity', 'rose', 'pct', 'Arrears: <strong data-txt="arrears"></strong>') ?>
  <?= kpi_card('Pending approval', 'pending_amount', 'hourglass-split', 'amber', 'money', '<span data-txt="pending"></span> applications · <span data-txt="approved"></span> to disburse') ?>
  <?= kpi_card('Collected this month', 'repaid_month', 'cash-coin', 'teal', 'money', 'Disbursed: <strong data-txt="disbursed_month"></strong>') ?>
</div>

<section class="card">
  <form id="loanFilters" autocomplete="off">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <div style="padding:18px 22px 0"><div class="tabs" data-tabs="loanstatus">
      <?php foreach ($tabs as $k => [$t, $icon]): ?>
        <button type="button" class="tab <?= $status === $k ? 'active' : '' ?>" data-tab="ls-<?= $k ?: 'all' ?>" data-status="<?= e($k) ?>"><i class="bi bi-<?= $icon ?>"></i><?= e($t) ?></button>
      <?php endforeach; ?>
    </div></div>
    <div class="toolbar">
      <div class="search"><i class="bi bi-search"></i><input class="input" type="search" name="search" placeholder="Loan no (LN…) or member…"></div>
      <select class="select" name="product_id" aria-label="Product"><option value="">All products</option><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select>
      <?= branch_filter() ?>
      <input class="input" type="date" name="date_from" title="Applied from" aria-label="Applied from">
      <input class="input" type="date" name="date_to" title="Applied to" aria-label="Applied to">
    </div>
  </form>
  <div id="loansTable"></div>
</section>

<?php ob_start(); ?>
<script>
(function () {
  const A = App;
  const can = { approve: A.can('loans.approve'), disburse: A.can('loans.disburse'), repay: A.can('loans.repay') };
  const filters = A.$('#loanFilters');

  async function loadStats() {
    try {
      const s = (await A.get('loans/stats', { branch_id: filters.elements.namedItem('branch_id')?.value || '' })).data;
      A.fillKpis(document, s);
      ['active', 'pending', 'approved'].forEach(k => (A.$(`[data-txt="${k}"]`).textContent = A.num(s[k])));
      A.$('[data-txt="arrears"]').textContent = A.money(s.arrears);
      A.$('[data-txt="disbursed_month"]').textContent = A.money(s.disbursed_month);
    } catch (e) { /* non-critical */ }
  }

  const table = new A.DataTable('#loansTable', {
    endpoint: 'loans/list', exportEndpoint: 'loans/export', filters,
    empty: 'No loans match this view', emptyIcon: 'cash-stack',
    onRowClick: r => A.loans.view(r.id),
    rowClass: r => (Number(r.arrears) > 0 ? 'has-arrears' : ''),
    columns: [
      { label: 'Loan', sort: 'loan_no', render: r => `<span class="mono strong">${A.esc(r.loan_no)}</span><div class="text-xs muted">${A.esc(r.product_name)}</div>` },
      { label: 'Member', sort: 'member', render: r => `<div class="cell-member">${A.avatar(r.first_name + ' ' + r.last_name, 'sm')}<div class="meta"><strong>${A.esc(r.first_name + ' ' + r.last_name)}</strong><span>${A.esc(r.member_no)}</span></div></div>` },
      { label: 'Principal', sort: 'principal', align: 'right', render: r => A.money(r.principal, false) },
      { label: 'Balance', sort: 'balance', align: 'right', render: r => {
          if (!['active', 'closed', 'written_off'].includes(r.status)) return '<span class="muted">—</span>';
          const total = Number(r.total_payable) + Number(r.penalty_accrued);
          const pct = total ? Math.min(100, Number(r.amount_paid) / total * 100) : 0;
          return `<span class="strong">${A.money(r.balance, false)}</span><div class="progress" style="height:5px;margin-top:5px;min-width:90px"><span style="width:${pct.toFixed(0)}%"></span></div>`;
        } },
      { label: 'Installment', align: 'right', hideSm: true, hideMd: true, render: r => A.money(r.installment_amount, false) + `<div class="text-xs muted">${r.term_months} mo</div>` },
      { label: 'Arrears', align: 'right', render: r => Number(r.arrears) > 0 ? `<span class="text-red fw-bold">${A.money(r.arrears, false)}</span><div class="text-xs text-red">${r.days_overdue} days</div>` : '<span class="muted">—</span>' },
      { label: 'Status', render: r => A.badge(r.status) },
      { label: 'Applied', sort: 'applied_at', hideSm: true, render: r => `<span class="nowrap">${A.date(r.applied_at)}</span>` },
      { label: '', align: 'right', render: r => `<div class="row-actions">
          <button class="act" data-action="view" title="View details"><i class="bi bi-eye"></i></button>
          ${r.status === 'pending' && can.approve ? `<button class="act" data-action="approve" title="Approve"><i class="bi bi-patch-check"></i></button>` : ''}
          ${r.status === 'approved' && can.disburse ? `<button class="act" data-action="disburse" title="Disburse"><i class="bi bi-send-check"></i></button>` : ''}
          ${r.status === 'active' && can.repay ? `<button class="act" data-action="repay" title="Record repayment"><i class="bi bi-cash-coin"></i></button>` : ''}
        </div>` },
    ],
    onAction: (action, r) => {
      if (action === 'view') return A.loans.view(r.id);
      A.loans[action](r);
    },
    onLoad: loadStats,
  });

  A.$('[data-tabs="loanstatus"]').addEventListener('click', e => {
    const t = e.target.closest('[data-status]');
    if (!t) return;
    filters.elements.namedItem('status').value = t.dataset.status;
    const url = new URL(location.href);
    t.dataset.status ? url.searchParams.set('status', t.dataset.status) : url.searchParams.delete('status');
    history.replaceState(null, '', url);
    table.reload(true);
  });
  A.$('#exportLoans').addEventListener('click', () => (location.href = table.exportUrl()));
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
