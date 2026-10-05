<?php
defined('SACCO') or exit;
$canEdit = Auth::can('loans.products');
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><a href="index.php?page=loans">Loans</a><i class="bi bi-chevron-right"></i><span>Products</span></div>
    <h1>Loan products</h1>
    <p>Pricing, limits, eligibility and guarantor rules for each credit product.</p>
  </div>
  <div class="page-actions">
    <?php if ($canEdit): ?><button class="btn btn-primary" data-open="loanProductModal"><i class="bi bi-plus-lg"></i><span>New product</span></button><?php endif; ?>
  </div>
</div>

<div class="grid-cards" data-products>
  <?php for ($i = 0; $i < 3; $i++): ?><div class="card"><div class="card-body"><span class="skeleton mb-2" style="width:50%"></span><span class="skeleton mb-2"></span><span class="skeleton" style="width:70%"></span></div></div><?php endfor; ?>
</div>

<?php if ($canEdit): ?>
<div class="modal modal-lg" id="loanProductModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="loans/product_save" novalidate>
    <div class="modal-head"><div class="modal-icon"><i class="bi bi-box-seam"></i></div><div class="modal-title"><h3 data-title data-create="New loan product" data-edit="Edit loan product">New loan product</h3><p>Changes apply to new applications only; existing loans keep their terms.</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <input type="hidden" name="id">
      <div class="form-grid cols-3">
        <div class="field"><label>Code <span class="req">*</span></label><input class="input" name="code" required maxlength="20" placeholder="e.g. DEV"></div>
        <div class="field span-2"><label>Name <span class="req">*</span></label><input class="input" name="name" required maxlength="120"></div>
        <div class="field span-all"><label>Description</label><input class="input" name="description" maxlength="255"></div>
        <div class="form-section">Pricing</div>
        <div class="field"><label>Interest rate (%) <span class="req">*</span></label><input class="input" type="number" name="interest_rate" min="0" max="100" step="0.001" required></div>
        <div class="field"><label>Rate period</label><select class="select" name="rate_period"><option value="annual">Per annum</option><option value="monthly">Per month</option></select></div>
        <div class="field"><label>Interest method</label><select class="select" name="interest_method"><option value="reducing">Reducing balance</option><option value="flat">Flat rate</option></select></div>
        <div class="field"><label>Processing fee (%)</label><input class="input" type="number" name="processing_fee_rate" min="0" max="100" step="0.001" value="0" data-default="0" required></div>
        <div class="field"><label>Late penalty (%)</label><input class="input" type="number" name="penalty_rate" min="0" max="100" step="0.001" value="0" data-default="0" required><div class="field-hint">Of each overdue installment</div></div>
        <div class="field"><label>Status</label><select class="select" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        <div class="form-section">Limits &amp; eligibility</div>
        <div class="field"><label>Minimum amount <span class="req">*</span></label><input class="input" type="number" name="min_amount" min="0" step="0.01" required></div>
        <div class="field"><label>Maximum amount <span class="req">*</span></label><input class="input" type="number" name="max_amount" min="1" step="0.01" required></div>
        <div class="field"><label>Savings multiplier</label><input class="input" type="number" name="savings_multiplier" min="0" max="100" step="0.01" value="3" data-default="3" required><div class="field-hint">Max loan = × savings (0 = no limit)</div></div>
        <div class="field"><label>Min. term (months) <span class="req">*</span></label><input class="input" type="number" name="min_term" min="1" max="600" required value="1" data-default="1"></div>
        <div class="field"><label>Max. term (months) <span class="req">*</span></label><input class="input" type="number" name="max_term" min="1" max="600" required value="12" data-default="12"></div>
        <div class="field"><label>Guarantors required</label><input class="input" type="number" name="min_guarantors" min="0" max="20" required value="0" data-default="0"></div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><span>Save product</span></button></div>
  </form>
</div>
<?php endif; ?>

<?php ob_start(); ?>
<script>
(function () {
  const A = App, canEdit = <?= $canEdit ? 'true' : 'false' ?>;
  let products = [];
  async function load() {
    try { products = (await A.get('loans/products')).data; } catch (e) { A.toast(e.message, 'error'); return; }
    A.$('[data-products]').innerHTML = products.length ? products.map(p => `<article class="card product-card hoverable">
      <div class="pc-head">
        <div class="flex justify-between items-center gap-1"><h3>${A.esc(p.name)}</h3>${A.badge(p.status)}</div>
        <p>${A.esc(p.description || '')}</p>
        <div class="pc-rate">${Number(p.interest_rate)}% <small>${p.rate_period === 'monthly' ? 'per month' : 'per annum'} · ${A.label(p.interest_method)}</small></div>
      </div>
      <div class="pc-body"><div class="kv">
        <div><span>Amount</span><strong>${A.compact(p.min_amount)} – ${A.compact(p.max_amount)}</strong></div>
        <div><span>Term</span><strong>${p.min_term} – ${p.max_term} months</strong></div>
        <div><span>Processing fee</span><strong>${Number(p.processing_fee_rate)}%</strong></div>
        <div><span>Late penalty</span><strong>${Number(p.penalty_rate)}%</strong></div>
        <div><span>Eligibility</span><strong>${Number(p.savings_multiplier) ? Number(p.savings_multiplier) + '× savings' : 'No savings limit'}</strong></div>
        <div><span>Guarantors</span><strong>${Number(p.min_guarantors) || 'None'}</strong></div>
      </div></div>
      <div class="pc-foot"><div><div class="text-xs muted">${A.num(p.active_loans)} active loans</div><strong>${A.money(p.portfolio)}</strong></div>
        ${canEdit ? `<button class="btn btn-sm" data-edit="${p.id}"><i class="bi bi-pencil"></i><span>Edit</span></button>` : `<span class="tag mono">${A.esc(p.code)}</span>`}</div>
    </article>`).join('') : '<div class="card"><div class="empty"><div class="empty-icon"><i class="bi bi-box-seam"></i></div><h4>No loan products yet</h4></div></div>';
  }
  document.addEventListener('click', e => { const b = e.target.closest('[data-edit]'); if (b) A.openForm('loanProductModal', products.find(p => String(p.id) === b.dataset.edit)); });
  document.addEventListener('app:saved', load);
  load();
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
