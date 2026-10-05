<?php
defined('SACCO') or exit;
$canManage = Auth::can('members.manage');
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Members</span></div>
    <h1>Members</h1>
    <p>Register, search and manage your SACCO's membership.</p>
  </div>
  <div class="page-actions">
    <button class="btn" type="button" id="exportMembers"><i class="bi bi-download"></i><span>Export CSV</span></button>
    <?php if ($canManage): ?><button class="btn btn-primary" data-open="memberModal"><i class="bi bi-person-plus"></i><span>New member</span></button><?php endif; ?>
  </div>
</div>

<div class="grid-kpi mb-3">
  <?= kpi_card('Total members', 'total', 'people', 'navy', 'num') ?>
  <?= kpi_card('Active members', 'active', 'person-check', 'green', 'num') ?>
  <?= kpi_card('Joined this month', 'new_month', 'person-plus', 'teal', 'num') ?>
  <?= kpi_card('Dormant', 'dormant', 'moon-stars', 'amber', 'num', '<span data-suspended></span> suspended') ?>
</div>

<section class="card">
  <form class="toolbar" id="memberFilters" autocomplete="off">
    <div class="search"><i class="bi bi-search"></i><input class="input" type="search" name="search" placeholder="Name, member no, ID or phone…"></div>
    <select class="select" name="status" aria-label="Status">
      <option value="">All statuses</option>
      <?php foreach (MemberService::STATUSES as $s): ?><option value="<?= $s ?>"><?= label($s) ?></option><?php endforeach; ?>
    </select>
    <?= branch_filter() ?>
    <input class="input" type="date" name="joined_from" aria-label="Joined from" title="Joined from">
    <input class="input" type="date" name="joined_to" aria-label="Joined to" title="Joined to">
  </form>
  <div id="membersTable"></div>
</section>

<?php ob_start(); ?>
<script>
(function () {
  const A = App, canManage = <?= $canManage ? 'true' : 'false' ?>, canTransact = A.can('savings.transact');
  const loadStats = async () => {
    try {
      const s = (await A.get('members/stats', Object.fromEntries(new FormData(A.$('#memberFilters'))))).data;
      A.fillKpis(document, s);
      A.$('[data-suspended]').textContent = A.num(s.suspended);
    } catch (e) { /* non-critical */ }
  };

  const table = new A.DataTable('#membersTable', {
    endpoint: 'members/list', exportEndpoint: 'members/export', filters: '#memberFilters',
    empty: 'No members found', emptyIcon: 'people',
    onRowClick: r => (location.href = `index.php?page=member&id=${r.id}`),
    columns: [
      { label: 'Member', sort: 'name', render: r => `<div class="cell-member">${A.avatar(r.first_name + ' ' + r.last_name)}<div class="meta"><strong>${A.esc(r.first_name + ' ' + r.last_name)}</strong><span class="mono">${A.esc(r.member_no)}</span></div></div>` },
      { label: 'Phone', render: r => `<span class="nowrap">${A.esc(r.phone)}</span>` },
      { label: 'National ID', key: 'national_id', hideSm: true },
      { label: 'Branch', hideSm: true, render: r => `<span class="nowrap">${A.esc(r.branch_name)}</span>` },
      { label: 'Savings', align: 'right', render: r => `<span class="strong">${A.money(r.savings_total, false)}</span>` },
      { label: 'Shares', align: 'right', hideSm: true, render: r => A.money(r.share_capital, false) },
      { label: 'Loan balance', align: 'right', render: r => Number(r.loan_balance) ? `<span class="text-amber fw-bold">${A.money(r.loan_balance, false)}</span>` : '<span class="muted">—</span>' },
      { label: 'Status', sort: 'status', render: r => A.badge(r.status) },
      { label: 'Joined', sort: 'joined_at', hideSm: true, render: r => `<span class="nowrap">${A.date(r.joined_at)}</span>` },
      { label: '', align: 'right', render: r => `<div class="row-actions">
          <a class="act" href="index.php?page=member&id=${r.id}" title="View profile"><i class="bi bi-eye"></i></a>
          ${canManage ? `<button class="act" data-action="edit" title="Edit"><i class="bi bi-pencil-square"></i></button>` : ''}
          ${canTransact ? `<button class="act" data-action="deposit" title="Receive deposit"><i class="bi bi-arrow-down-left-circle"></i></button>` : ''}
        </div>` },
    ],
    onAction: (action, r) => {
      if (action === 'edit') A.members.edit(r.id);
      if (action === 'deposit') A.openForm('depositModal', { member_id: { id: r.id, name: r.first_name + ' ' + r.last_name, member_no: r.member_no, savings_total: r.savings_total } });
    },
    onLoad: loadStats,
  });

  A.$('#exportMembers').addEventListener('click', () => (location.href = table.exportUrl()));
  A.$('#memberModal form')?.addEventListener('form:success', e => {
    if (e.detail.redirect && !A.$('#memberModal form').querySelector('[name=id]').value) setTimeout(() => (location.href = e.detail.redirect), 900);
  });
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
