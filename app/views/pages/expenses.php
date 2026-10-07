<?php
defined('SACCO') or exit;
$canManage = Auth::can('expenses.manage');
$ownBranch = Auth::user()['branch_id'] ?? null;
$branches = DB::all("SELECT id, name FROM branches WHERE status = 'active' ORDER BY id");
// Expense categories are the general ledger's expense accounts.
$expenseAccounts = DB::all("SELECT id, code, name FROM gl_accounts WHERE type = 'expense' AND status = 'active' AND (system_key IS NULL OR system_key = 'general_expense') ORDER BY code");
$categories = array_values(array_unique([...array_column($expenseAccounts, 'name'), ...array_column(DB::all('SELECT DISTINCT category FROM expenses ORDER BY category'), 'category')]));
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Expenses</span></div>
    <h1>Operating expenses</h1>
    <p>Record and track the SACCO's running costs. Every expense posts to the ledger.</p>
  </div>
  <div class="page-actions">
    <button class="btn" type="button" id="exportExpenses"><i class="bi bi-download"></i><span>Export</span></button>
    <?php if ($canManage): ?><button class="btn btn-primary" data-open="expenseModal"><i class="bi bi-plus-lg"></i><span>Record expense</span></button><?php endif; ?>
  </div>
</div>

<div class="grid-kpi mb-3">
  <?= kpi_card('This month', 'month', 'calendar-month', 'rose', 'money', '<span data-change></span> vs last month') ?>
  <?= kpi_card('Last month', 'last_month', 'calendar2', 'navy', 'money') ?>
  <?= kpi_card('Year to date', 'year', 'calendar-range', 'amber', 'money') ?>
  <?= kpi_card('Top category (month)', 'top_amount', 'tags', 'teal', 'money', '<span data-top></span>') ?>
</div>

<section class="card">
  <form class="toolbar" id="expenseFilters" autocomplete="off">
    <div class="search"><i class="bi bi-search"></i><input class="input" type="search" name="search" placeholder="Payee, description, reference…"></div>
    <select class="select" name="category" aria-label="Category"><option value="">All categories</option><?php foreach ($categories as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select>
    <?= branch_filter() ?>
    <input class="input" type="date" name="date_from" value="<?= date('Y-01-01') ?>" aria-label="From">
    <input class="input" type="date" name="date_to" value="<?= today() ?>" aria-label="To">
    <span class="ml-auto muted text-sm">Filtered total: <strong class="text-red" data-filtered-total>—</strong></span>
  </form>
  <div id="expenseTable"></div>
</section>

<?php if ($canManage): ?>
<div class="modal" id="expenseModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="expenses/save" novalidate>
    <div class="modal-head"><div class="modal-icon danger"><i class="bi bi-wallet2"></i></div><div class="modal-title"><h3 data-title data-create="Record expense" data-edit="Edit expense">Record expense</h3><p>Posted to the ledger as a cash outflow.</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <input type="hidden" name="id">
      <div class="form-grid">
        <div class="field"><label>Expense account <span class="req">*</span></label><select class="select" name="gl_account_id" required><option value="">Choose…</option><?php foreach ($expenseAccounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['code'] . ' · ' . $a['name']) ?></option><?php endforeach; ?></select><div class="field-hint">Posts to this account in the general ledger.</div></div>
        <div class="field"><label>Date <span class="req">*</span></label><input class="input" type="date" name="expense_date" required data-default="today" max="<?= today() ?>"></div>
        <div class="field"><label>Amount <span class="req">*</span></label><div class="input-group"><span class="addon"><?= e(currency()) ?></span><input class="input input-amount" type="number" name="amount" min="1" step="0.01" required></div></div>
        <div class="field"><label>Payee</label><input class="input" name="payee" maxlength="120" placeholder="Supplier / person paid"></div>
        <?php if ($ownBranch): ?>
          <input type="hidden" name="branch_id" value="<?= (int) $ownBranch ?>" data-keep>
        <?php else: ?>
          <div class="field"><label>Branch <span class="req">*</span></label><select class="select" name="branch_id" required><?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="field"><label>Reference</label><input class="input" name="payment_ref" maxlength="80" placeholder="Invoice / cheque no."></div>
        <div class="field span-2"><label>Description</label><input class="input" name="description" maxlength="255"></div>
        <div class="field span-2"><label>Paid via</label>
          <div class="segmented"><?php foreach (['cash' => ['Cash', 'cash'], 'mobile_money' => ['Mobile', 'phone'], 'bank' => ['Bank', 'bank'], 'cheque' => ['Cheque', 'card-text']] as $v => [$t, $i]): ?><label><input type="radio" name="payment_method" value="<?= $v ?>" <?= $v === 'bank' ? 'checked' : '' ?>><span><i class="bi bi-<?= $i ?>"></i><?= $t ?></span></label><?php endforeach; ?></div>
        </div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><span>Save expense</span></button></div>
  </form>
</div>
<?php endif; ?>

<?php ob_start(); ?>
<script>
(function () {
  const A = App, canManage = <?= $canManage ? 'true' : 'false' ?>;
  async function loadStats() {
    try {
      const s = (await A.get('expenses/stats', { branch_id: A.$('#expenseFilters [name=branch_id]')?.value || '' })).data;
      A.fillKpis(document, s);
      const ch = A.$('[data-change]');
      if (s.change === null) ch.textContent = '—';
      else { ch.className = 'trend ' + (s.change > 0 ? 'down' : 'up'); ch.textContent = (s.change > 0 ? '+' : '') + s.change + '%'; }
      A.$('[data-top]').textContent = s.top_category;
    } catch (e) { /* non-critical */ }
  }
  const table = new A.DataTable('#expenseTable', {
    endpoint: 'expenses/list', exportEndpoint: 'expenses/export', filters: '#expenseFilters', sort: 'expense_date', dir: 'desc',
    empty: 'No expenses recorded', emptyIcon: 'wallet2',
    columns: [
      { label: 'Date', sort: 'expense_date', render: r => `<span class="nowrap">${A.date(r.expense_date)}</span>` },
      { label: 'Category', sort: 'category', render: r => `<span class="tag">${A.esc(r.category)}</span>` },
      { label: 'Payee', render: r => `<span class="strong">${A.esc(r.payee || '—')}</span><div class="text-xs muted">${A.esc(r.description || '')}</div>` },
      { label: 'Method', hideSm: true, render: r => A.esc(A.METHODS[r.payment_method] || r.payment_method) + (r.payment_ref ? `<div class="text-xs muted">${A.esc(r.payment_ref)}</div>` : '') },
      { label: 'Branch', key: 'branch_name', hideSm: true },
      { label: 'Amount', sort: 'amount', align: 'right', render: r => `<span class="amount-out">${A.money(r.amount, false)}</span>` },
      { label: 'Ledger ref', hideSm: true, render: r => `<span class="mono text-xs">${A.esc(r.txn_ref || '')}</span>` },
      { label: '', align: 'right', render: () => canManage ? `<div class="row-actions"><button class="act" data-action="edit" title="Edit"><i class="bi bi-pencil-square"></i></button><button class="act danger" data-action="void" title="Void"><i class="bi bi-trash3"></i></button></div>` : '' },
    ],
    onAction: async (action, r) => {
      if (action === 'edit') return A.openForm('expenseModal', r);
      if (!await A.confirm({ title: 'Void this expense?', message: `${r.category} · ${A.money(r.amount)} — the ledger entry will be reversed.`, confirmText: 'Void expense' })) return;
      try { A.toast((await A.post('expenses/delete', { id: r.id })).message); document.dispatchEvent(new CustomEvent('app:saved', { detail: { route: 'expenses/delete' } })); }
      catch (e) { A.toast(e.message, 'error'); }
    },
    onLoad: res => { A.$('[data-filtered-total]').textContent = A.money(res.total); loadStats(); },
  });
  A.$('#exportExpenses').addEventListener('click', () => (location.href = table.exportUrl()));
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
