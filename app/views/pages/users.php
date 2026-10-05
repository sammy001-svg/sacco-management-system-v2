<?php
defined('SACCO') or exit;
$branches = DB::all('SELECT id, name FROM branches ORDER BY id');
$perms = permissions();
$roles = Auth::roles();
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Users &amp; Roles</span></div>
    <h1>Users &amp; roles</h1>
    <p>Staff accounts, their roles and branch access.</p>
  </div>
  <div class="page-actions"><button class="btn btn-primary" data-open="userModal"><i class="bi bi-person-plus"></i><span>New user</span></button></div>
</div>

<section class="card mb-3">
  <form class="toolbar" id="userFilters" autocomplete="off">
    <div class="search"><i class="bi bi-search"></i><input class="input" type="search" name="search" placeholder="Name or email…"></div>
    <select class="select" name="role" aria-label="Role"><option value="">All roles</option><?php foreach ($roles as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
  </form>
  <div id="usersTable"></div>
</section>

<section class="card">
  <div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-shield-lock"></i></span>Role permissions</h3><p>Administrators have every permission. Edit <code>app/permissions.php</code> to customise.</p></div></div>
  <div class="card-body flush"><div class="table-wrap"><table class="table compact">
    <thead><tr><th>Permission</th><?php foreach ($roles as $k => $v): ?><th class="num"><?= e($v) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php foreach ($perms as $p => $allowed): ?>
        <tr><td class="mono"><?= e($p) ?></td>
          <?php foreach (array_keys($roles) as $r): $ok = $r === 'admin' || in_array($r, $allowed, true); ?>
            <td class="num"><?= $ok ? '<i class="bi bi-check-circle-fill text-green"></i>' : '<i class="bi bi-dash muted"></i>' ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div></div>
</section>

<div class="modal" id="userModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="users/save" novalidate>
    <div class="modal-head"><div class="modal-icon navy"><i class="bi bi-person-gear"></i></div><div class="modal-title"><h3 data-title data-create="New user" data-edit="Edit user">New user</h3><p>Users with a branch can only see that branch's members and transactions.</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <input type="hidden" name="id">
      <div class="form-grid">
        <div class="field"><label>Full name <span class="req">*</span></label><input class="input" name="name" required maxlength="120"></div>
        <div class="field"><label>Email <span class="req">*</span></label><input class="input" type="email" name="email" required maxlength="190"></div>
        <div class="field"><label>Phone</label><input class="input" name="phone" maxlength="30"></div>
        <div class="field"><label>Role <span class="req">*</span></label><select class="select" name="role" required><?php foreach ($roles as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'teller' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Branch access</label><select class="select" name="branch_id"><option value="">All branches (head office)</option><?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Status</label><select class="select" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        <div class="field span-2"><label>Password <span class="req" data-mode-show="create">*</span></label>
          <div class="input-icon"><i class="bi bi-key"></i><input class="input" type="password" name="password" minlength="8" autocomplete="new-password"><button type="button" class="input-action" data-toggle-password><i class="bi bi-eye"></i></button></div>
          <div class="field-hint"><span data-mode-show="create">At least 8 characters with letters and numbers.</span><span data-mode-show="edit">Leave blank to keep the current password.</span></div>
        </div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><span>Save user</span></button></div>
  </form>
</div>

<?php ob_start(); ?>
<script>
(function () {
  const A = App, me = A.cfg.user.id;
  const roles = <?= json_encode($roles) ?>;
  const form = A.$('#userModal form');
  form.addEventListener('form:reset', () => (form.elements.namedItem('password').required = true));
  A.$('#userModal').addEventListener('form:open', e => (form.elements.namedItem('password').required = !(e.detail.data && e.detail.data.id)));

  new A.DataTable('#usersTable', {
    endpoint: 'users/list', filters: '#userFilters', empty: 'No users found', emptyIcon: 'person-gear',
    columns: [
      { label: 'User', sort: 'name', render: r => `<div class="cell-member">${A.avatar(r.name)}<div class="meta"><strong>${A.esc(r.name)}${r.id === me ? ' <span class="tag">You</span>' : ''}</strong><span>${A.esc(r.email)}</span></div></div>` },
      { label: 'Role', sort: 'role', render: r => `<span class="tag"><i class="bi bi-shield"></i>${A.esc(roles[r.role] || r.role)}</span>` },
      { label: 'Branch', render: r => A.esc(r.branch_name || 'All branches') },
      { label: 'Phone', key: 'phone', hideSm: true },
      { label: 'Last login', sort: 'last_login_at', hideSm: true, render: r => r.last_login_at ? A.timeAgo(r.last_login_at) : '<span class="muted">Never</span>' },
      { label: 'Status', render: r => r.locked_until && new Date(r.locked_until.replace(' ', 'T')) > new Date() ? A.badge('suspended', 'Locked') : A.badge(r.status) },
      { label: '', align: 'right', render: r => `<div class="row-actions">${r.locked_until ? `<button class="act" data-action="unlock" title="Unlock"><i class="bi bi-unlock"></i></button>` : ''}<button class="act" data-action="edit" title="Edit"><i class="bi bi-pencil-square"></i></button></div>` },
    ],
    onAction: async (action, r) => {
      if (action === 'edit') return A.openForm('userModal', r);
      try { A.toast((await A.post('users/unlock', { id: r.id })).message); document.dispatchEvent(new CustomEvent('app:saved', { detail: {} })); } catch (e) { A.toast(e.message, 'error'); }
    },
  });
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
