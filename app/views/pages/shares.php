<?php
defined('SACCO') or exit;
$canTransact = Auth::can('shares.transact');
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Share Capital</span></div>
    <h1>Share capital</h1>
    <p>The member share register — ownership of the SACCO.</p>
  </div>
  <div class="page-actions">
    <button class="btn" type="button" id="exportShares"><i class="bi bi-download"></i><span>Export register</span></button>
    <?php if ($canTransact): ?>
      <button class="btn" data-open="transferSharesModal"><i class="bi bi-arrow-left-right"></i><span>Transfer</span></button>
      <button class="btn btn-primary" data-open="sharesModal"><i class="bi bi-pie-chart"></i><span>Buy shares</span></button>
    <?php endif; ?>
  </div>
</div>

<div class="grid-kpi mb-3">
  <?= kpi_card('Total share capital', 'capital', 'pie-chart-fill', 'green', 'money', '', 'featured') ?>
  <?= kpi_card('Shares issued', 'shares', 'grid-3x3-gap', 'navy', 'num') ?>
  <?= kpi_card('Shareholders', 'holders', 'people', 'teal', 'num') ?>
  <?= kpi_card('Price per share', 'price', 'tag', 'amber', 'money', '<span data-below></span> below minimum capital') ?>
</div>

<section class="card">
  <form class="toolbar" id="shareFilters" autocomplete="off">
    <div class="search"><i class="bi bi-search"></i><input class="input" type="search" name="search" placeholder="Search member…"></div>
    <label class="check mb-0"><input type="checkbox" name="holders_only" value="1" checked> Shareholders only</label>
    <?= branch_filter() ?>
  </form>
  <div id="sharesTable"></div>
</section>

<?php ob_start(); ?>
<script>
(function () {
  const A = App, canTransact = <?= $canTransact ? 'true' : 'false' ?>;
  let totalCapital = 0;
  async function loadStats() {
    try {
      const s = (await A.get('shares/stats', Object.fromEntries(new FormData(A.$('#shareFilters'))))).data;
      totalCapital = s.capital;
      A.fillKpis(document, s);
      A.$('[data-below]').textContent = A.num(s.below_min);
    } catch (e) { /* non-critical */ }
  }
  loadStats();

  const table = new A.DataTable('#sharesTable', {
    endpoint: 'shares/list', exportEndpoint: 'shares/export', filters: '#shareFilters', sort: 'balance', dir: 'desc',
    empty: 'No shareholders found', emptyIcon: 'pie-chart',
    columns: [
      { label: 'Member', sort: 'member', render: r => `<a class="cell-member" href="index.php?page=member&id=${r.member_id}">${A.avatar(r.first_name + ' ' + r.last_name)}<div class="meta"><strong>${A.esc(r.first_name + ' ' + r.last_name)}</strong><span>${A.esc(r.member_no)} · ${A.esc(r.phone)}</span></div></a>` },
      { label: 'Shares', sort: 'shares', align: 'right', render: r => `<span class="strong">${A.num(r.shares)}</span>` },
      { label: 'Share capital', sort: 'balance', align: 'right', render: r => `<span class="strong">${A.money(r.balance, false)}</span>` },
      { label: 'Ownership', hideSm: true, render: r => { const p = totalCapital ? r.balance / totalCapital * 100 : 0; return `<div class="flex items-center gap-1" style="min-width:140px"><div class="progress" style="flex:1"><span style="width:${Math.min(100, p * 10).toFixed(1)}%"></span></div><span class="text-xs fw-bold">${p.toFixed(2)}%</span></div>`; } },
      { label: 'Member status', render: r => A.badge(r.status) },
      { label: 'Last activity', hideSm: true, render: r => r.updated_at ? A.timeAgo(r.updated_at) : '—' },
      { label: '', align: 'right', render: r => canTransact ? `<div class="row-actions"><button class="act" data-action="buy" title="Buy shares"><i class="bi bi-plus-circle"></i></button>${Number(r.shares) ? `<button class="act" data-action="transfer" title="Transfer shares"><i class="bi bi-arrow-left-right"></i></button>` : ''}</div>` : '' },
    ],
    onAction: (action, r) => {
      const member = { id: r.member_id, name: r.first_name + ' ' + r.last_name, member_no: r.member_no };
      if (action === 'buy') A.openForm('sharesModal', { member_id: member });
      if (action === 'transfer') A.openForm('transferSharesModal', { from_member_id: member });
    },
    onLoad: loadStats,
  });
  A.$('#exportShares').addEventListener('click', () => (location.href = table.exportUrl()));
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
