<?php
defined('SACCO') or exit;
$canTransact = Auth::can('savings.transact');
$canProducts = Auth::can('savings.products');
$products = DB::all('SELECT id, name FROM savings_products ORDER BY is_default DESC, name');
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Savings</span></div>
    <h1>Savings</h1>
    <p>Member deposit accounts, transactions and savings products.</p>
  </div>
  <div class="page-actions">
    <?php if ($canTransact): ?>
      <button class="btn" data-open="openAccountModal"><i class="bi bi-wallet2"></i><span>Open account</span></button>
      <button class="btn" data-open="withdrawModal"><i class="bi bi-arrow-up-right-circle"></i><span>Withdraw</span></button>
      <button class="btn btn-primary" data-open="depositModal"><i class="bi bi-arrow-down-left-circle"></i><span>Receive deposit</span></button>
    <?php endif; ?>
  </div>
</div>

<div class="grid-kpi mb-3">
  <?= kpi_card('Total savings', 'total', 'piggy-bank', 'green', 'money', '<span data-txt="accounts"></span> open accounts', 'featured') ?>
  <?= kpi_card('Deposits this month', 'deposits_month', 'arrow-down-left-circle', 'teal', 'money', 'Today: <strong data-txt="deposits_today"></strong>') ?>
  <?= kpi_card('Withdrawals this month', 'withdrawals_month', 'arrow-up-right-circle', 'amber', 'money', 'Today: <strong data-txt="withdrawals_today"></strong>') ?>
  <?= kpi_card('Net inflow this month', 'net_month', 'graph-up-arrow', 'navy', 'money') ?>
</div>

<div class="flex items-center justify-between wrap gap-2 mb-2">
  <div class="tabs" data-tabs="savings">
    <button class="tab active" data-tab="sv-accounts"><i class="bi bi-wallet2"></i>Accounts</button>
    <button class="tab" data-tab="sv-products"><i class="bi bi-box-seam"></i>Products</button>
  </div>
  <?php if ($canProducts): ?><button class="btn btn-soft btn-sm" data-open="savingsProductModal"><i class="bi bi-plus-lg"></i><span>New product</span></button><?php endif; ?>
</div>

<div class="tab-panel active" id="sv-accounts" data-group="savings">
  <section class="card">
    <form class="toolbar" id="savingsFilters" autocomplete="off">
      <div class="search"><i class="bi bi-search"></i><input class="input" type="search" name="search" placeholder="Account no (SV…) or member…"></div>
      <select class="select" name="product_id" aria-label="Product"><option value="">All products</option><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select>
      <select class="select" name="status" aria-label="Status"><option value="">All statuses</option><option value="active">Active</option><option value="frozen">Frozen</option><option value="closed">Closed</option></select>
      <?= branch_filter() ?>
      <button class="btn btn-sm ml-auto" type="button" id="exportSavings"><i class="bi bi-download"></i><span>Export</span></button>
    </form>
    <div id="savingsTable"></div>
  </section>
</div>

<div class="tab-panel" id="sv-products" data-group="savings">
  <div class="grid-cards" data-products></div>
</div>

<?php if ($canProducts): ?>
<div class="modal" id="savingsProductModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="savings/product_save" novalidate>
    <div class="modal-head"><div class="modal-icon"><i class="bi bi-box-seam"></i></div><div class="modal-title"><h3 data-title data-create="New savings product" data-edit="Edit savings product">New savings product</h3><p>Define interest, minimum balance and withdrawal rules.</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <input type="hidden" name="id">
      <div class="form-grid">
        <div class="field"><label>Code <span class="req">*</span></label><input class="input" name="code" required maxlength="20" placeholder="e.g. JUN"></div>
        <div class="field"><label>Name <span class="req">*</span></label><input class="input" name="name" required maxlength="120" placeholder="e.g. Junior Savings"></div>
        <div class="field span-2"><label>Description</label><input class="input" name="description" maxlength="255"></div>
        <div class="field"><label>Interest rate (% p.a.) <span class="req">*</span></label><input class="input" type="number" name="interest_rate" min="0" max="100" step="0.001" required value="0" data-default="0"></div>
        <div class="field"><label>Minimum balance <span class="req">*</span></label><input class="input" type="number" name="min_balance" min="0" step="0.01" required value="0" data-default="0"></div>
        <div class="field"><label>Withdrawal fee <span class="req">*</span></label><input class="input" type="number" name="withdrawal_fee" min="0" step="0.01" required value="0" data-default="0"></div>
        <div class="field"><label>Status</label><select class="select" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
      </div>
      <label class="switch"><input type="checkbox" name="allow_withdrawal" value="1" checked><span class="track"></span>Allow withdrawals</label>
      <label class="switch"><input type="checkbox" name="is_default" value="1"><span class="track"></span>Default account for new members</label>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><span>Save product</span></button></div>
  </form>
</div>
<?php endif; ?>

<?php ob_start(); ?>
<script>
(function () {
  const A = App, canTransact = <?= $canTransact ? 'true' : 'false' ?>, canProducts = <?= $canProducts ? 'true' : 'false' ?>;
  let products = [];

  async function loadStats() {
    try {
      const s = (await A.get('savings/stats', Object.fromEntries(new FormData(A.$('#savingsFilters'))))).data;
      s.net_month = s.deposits_month - s.withdrawals_month;
      A.fillKpis(document, s);
      A.$('[data-txt="accounts"]').textContent = A.num(s.accounts);
      A.$('[data-txt="deposits_today"]').textContent = A.money(s.deposits_today);
      A.$('[data-txt="withdrawals_today"]').textContent = A.money(s.withdrawals_today);
    } catch (e) { /* non-critical */ }
  }

  async function loadProducts() {
    try { products = (await A.get('savings/products')).data; } catch (e) { return; }
    A.$('[data-products]').innerHTML = products.map(p => `<article class="card product-card hoverable">
      <div class="pc-head"><div class="flex justify-between items-center gap-1"><h3>${A.esc(p.name)}</h3>${Number(p.is_default) ? '<span class="badge success">Default</span>' : ''}</div>
        <p>${A.esc(p.description || '')}</p><div class="pc-rate">${Number(p.interest_rate)}% <small>per annum</small></div></div>
      <div class="pc-body"><div class="kv">
        <div><span>Code</span><strong class="mono">${A.esc(p.code)}</strong></div>
        <div><span>Min. balance</span><strong>${A.money(p.min_balance)}</strong></div>
        <div><span>Withdrawals</span><strong>${Number(p.allow_withdrawal) ? 'Allowed' : '<span class="text-amber">Locked</span>'}</strong></div>
        <div><span>Withdrawal fee</span><strong>${A.money(p.withdrawal_fee)}</strong></div>
      </div></div>
      <div class="pc-foot"><div><div class="text-xs muted">${A.num(p.accounts)} accounts</div><strong>${A.money(p.total_balance)}</strong></div>
        <div class="flex gap-1">${A.badge(p.status)}${canProducts ? `<button class="btn btn-sm" data-edit-product="${p.id}"><i class="bi bi-pencil"></i><span>Edit</span></button>` : ''}</div></div>
    </article>`).join('');
  }

  const table = new A.DataTable('#savingsTable', {
    endpoint: 'savings/list', exportEndpoint: 'savings/export', filters: '#savingsFilters', sort: 'balance', dir: 'desc',
    empty: 'No savings accounts found', emptyIcon: 'wallet2',
    columns: [
      { label: 'Account', sort: 'account_no', render: r => `<span class="mono strong">${A.esc(r.account_no)}</span><div class="text-xs muted">${A.esc(r.product_name)}</div>` },
      { label: 'Member', sort: 'member', render: r => `<a class="cell-member" href="index.php?page=member&id=${r.member_id}">${A.avatar(r.first_name + ' ' + r.last_name, 'sm')}<div class="meta"><strong>${A.esc(r.first_name + ' ' + r.last_name)}</strong><span>${A.esc(r.member_no)}</span></div></a>` },
      { label: 'Phone', key: 'phone', hideSm: true },
      { label: 'Balance', sort: 'balance', align: 'right', render: r => `<span class="strong">${A.money(r.balance, false)}</span>` },
      { label: 'Status', render: r => A.badge(r.status) },
      { label: 'Opened', sort: 'opened_at', hideSm: true, render: r => A.date(r.opened_at) },
      { label: '', align: 'right', render: r => canTransact ? `<div class="row-actions">
          ${r.status === 'active' ? `<button class="act" data-action="deposit" title="Deposit"><i class="bi bi-arrow-down-left-circle"></i></button>` : ''}
          ${r.status === 'active' && Number(r.allow_withdrawal) ? `<button class="act" data-action="withdraw" title="Withdraw"><i class="bi bi-arrow-up-right-circle"></i></button>` : ''}
          ${r.status === 'active' ? `<button class="act" data-action="frozen" title="Freeze account"><i class="bi bi-snow"></i></button>` : ''}
          ${r.status === 'frozen' ? `<button class="act" data-action="active" title="Unfreeze account"><i class="bi bi-sun"></i></button>` : ''}
          ${r.status !== 'closed' && Number(r.balance) === 0 ? `<button class="act danger" data-action="closed" title="Close account"><i class="bi bi-x-circle"></i></button>` : ''}
        </div>` : '' },
    ],
    onAction: async (action, r) => {
      const member = { id: r.member_id, name: r.first_name + ' ' + r.last_name, member_no: r.member_no };
      if (action === 'deposit') return A.openForm('depositModal', { member_id: member, account_id: r.id });
      if (action === 'withdraw') return A.openForm('withdrawModal', { member_id: member, account_id: r.id });
      const words = { frozen: ['Freeze', 'No deposits or withdrawals will be allowed until it is unfrozen.'], active: ['Unfreeze', 'The account will accept transactions again.'], closed: ['Close', 'Closed accounts cannot be reopened.'] }[action];
      if (!await A.confirm({ title: `${words[0]} ${r.account_no}?`, message: words[1], confirmText: words[0] + ' account', tone: action === 'active' ? 'primary' : 'warning', icon: 'wallet2' })) return;
      try { A.toast((await A.post('savings/set_status', { id: r.id, status: action })).message); table.reload(); }
      catch (e) { A.toast(e.message, 'error'); }
    },
    onLoad: loadStats,
  });

  A.$('#exportSavings').addEventListener('click', () => (location.href = table.exportUrl()));
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-edit-product]');
    if (b) A.openForm('savingsProductModal', products.find(p => String(p.id) === b.dataset.editProduct));
  });
  document.addEventListener('app:saved', e => { loadProducts(); });
  loadProducts();
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
