<?php
defined('SACCO') or exit;
$users = DB::all('SELECT id, name FROM users ORDER BY name');
$actions = array_column(DB::all('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action');
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Audit trail</span></div>
    <h1>Audit trail</h1>
    <p>Who did what, and when. Every sign-in, change and money movement is recorded.</p>
  </div>
</div>

<section class="card">
  <form class="toolbar" id="auditFilters" autocomplete="off">
    <div class="search"><i class="bi bi-search"></i><input class="input" type="search" name="search" placeholder="Search descriptions…"></div>
    <select class="select" name="user_id" aria-label="User"><option value="">All users</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select>
    <select class="select" name="action" aria-label="Action"><option value="">All actions</option><?php foreach ($actions as $a): ?><option value="<?= e($a) ?>"><?= e(label($a)) ?></option><?php endforeach; ?></select>
    <input class="input" type="date" name="date_from" aria-label="From">
    <input class="input" type="date" name="date_to" aria-label="To">
  </form>
  <div id="auditTable"></div>
</section>

<?php ob_start(); ?>
<script>
(function () {
  const A = App;
  const tone = a => /delete|reverse|reject|write_off|login_failed/.test(a) ? 'danger' : /create|approve|disburse|deposit|repay|login$/.test(a) ? 'success' : /update|save|status/.test(a) ? 'info' : '';
  new A.DataTable('#auditTable', {
    endpoint: 'audit/list', filters: '#auditFilters', perPage: 25, compact: true, empty: 'No audit entries', emptyIcon: 'shield-check',
    columns: [
      { label: 'When', render: r => `<span class="nowrap">${A.dateTime(r.created_at)}</span><div class="text-xs muted">${A.timeAgo(r.created_at)}</div>` },
      { label: 'User', render: r => r.user_name ? `<span class="strong">${A.esc(r.user_name)}</span><div class="text-xs muted">${A.esc(A.label(r.user_role || ''))}</div>` : '<span class="muted">System</span>' },
      { label: 'Action', render: r => `<span class="badge ${tone(r.action)}">${A.esc(A.label(r.action))}</span>` },
      { label: 'Description', render: r => `<span class="text-sm">${A.esc(r.description || '')}</span>${r.entity ? `<div class="text-xs muted">${A.esc(r.entity)}${r.entity_id ? ' #' + r.entity_id : ''}</div>` : ''}` },
      { label: 'IP address', hideSm: true, render: r => `<span class="mono text-xs">${A.esc(r.ip_address || '')}</span>` },
    ],
  });
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
