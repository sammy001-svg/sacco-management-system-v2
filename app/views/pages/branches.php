<?php
defined('SACCO') or exit;
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Branches</span></div>
    <h1>Branches</h1>
    <p>Your SACCO's service points. Staff assigned to a branch only see that branch's data.</p>
  </div>
  <div class="page-actions"><button class="btn btn-primary" data-open="branchModal"><i class="bi bi-plus-lg"></i><span>New branch</span></button></div>
</div>

<div class="grid-cards" data-branches></div>

<div class="modal" id="branchModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="branches/save" novalidate>
    <div class="modal-head"><div class="modal-icon"><i class="bi bi-buildings"></i></div><div class="modal-title"><h3 data-title data-create="New branch" data-edit="Edit branch">New branch</h3><p>Branch details appear on statements and reports.</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <input type="hidden" name="id">
      <div class="form-grid">
        <div class="field"><label>Code <span class="req">*</span></label><input class="input" name="code" required maxlength="20" placeholder="e.g. NKR"></div>
        <div class="field"><label>Name <span class="req">*</span></label><input class="input" name="name" required maxlength="120" placeholder="e.g. Nakuru Branch"></div>
        <div class="field span-2"><label>Location</label><input class="input" name="location" maxlength="190"></div>
        <div class="field"><label>Phone</label><input class="input" name="phone" maxlength="30"></div>
        <div class="field"><label>Email</label><input class="input" type="email" name="email" maxlength="190"></div>
        <div class="field"><label>Status</label><select class="select" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><span>Save branch</span></button></div>
  </form>
</div>

<?php ob_start(); ?>
<script>
(function () {
  const A = App;
  let rows = [];
  async function load() {
    try { rows = (await A.get('branches/list')).data; } catch (e) { A.toast(e.message, 'error'); return; }
    A.$('[data-branches]').innerHTML = rows.map(b => `<article class="card hoverable">
      <div class="card-head"><div class="flex items-center gap-2"><span class="stat-icon" style="--tone:var(--primary);--tone-soft:var(--primary-soft)"><i class="bi bi-building"></i></span>
        <div><h3>${A.esc(b.name)}</h3><p><span class="mono">${A.esc(b.code)}</span> · ${A.esc(b.location || 'No location')}</p></div></div>${A.badge(b.status)}</div>
      <div class="card-body"><div class="kv">
        <div><span>Members</span><strong>${A.num(b.members)}</strong></div>
        <div><span>Staff</span><strong>${A.num(b.staff)}</strong></div>
        <div><span>Savings</span><strong>${A.money(b.savings)}</strong></div>
        <div><span>Loan portfolio</span><strong>${A.money(b.portfolio)}</strong></div>
      </div></div>
      <div class="card-foot"><span class="muted text-sm"><i class="bi bi-telephone"></i> ${A.esc(b.phone || '—')}</span>
        <div class="flex gap-1">${!b.members ? `<button class="btn btn-ghost btn-sm text-red" data-del="${b.id}"><i class="bi bi-trash3"></i></button>` : ''}<button class="btn btn-sm" data-edit="${b.id}"><i class="bi bi-pencil"></i><span>Edit</span></button></div></div>
    </article>`).join('');
  }
  document.addEventListener('click', async e => {
    const ed = e.target.closest('[data-edit]');
    if (ed) A.openForm('branchModal', rows.find(r => String(r.id) === ed.dataset.edit));
    const del = e.target.closest('[data-del]');
    if (del && await A.confirm({ title: 'Delete this branch?', message: 'Only branches with no members or records can be deleted.', confirmText: 'Delete branch' })) {
      try { A.toast((await A.post('branches/delete', { id: del.dataset.del })).message); load(); } catch (err) { A.toast(err.message, 'error'); }
    }
  });
  document.addEventListener('app:saved', load);
  load();
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
