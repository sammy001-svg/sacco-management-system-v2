<?php
defined('SACCO') or exit;
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Transactions</span></div>
    <h1>Transaction ledger</h1>
    <p>Every money movement, immutable and fully traceable. Corrections are posted as reversals.</p>
  </div>
  <div class="page-actions">
    <button class="btn" type="button" id="exportTxns"><i class="bi bi-download"></i><span>Export CSV</span></button>
  </div>
</div>

<div class="grid-kpi mb-3">
  <?= kpi_card('Cash in', 'in', 'box-arrow-in-down', 'green', 'money', 'For the selected filters') ?>
  <?= kpi_card('Cash out', 'out', 'box-arrow-up', 'rose', 'money', 'For the selected filters') ?>
  <?= kpi_card('Net cash flow', 'net', 'arrow-down-up', 'navy', 'money') ?>
  <?= kpi_card('Postings', 'count', 'receipt', 'teal', 'num', 'Excludes reversed entries') ?>
</div>

<section class="card">
  <form class="toolbar" id="txnFilters" autocomplete="off">
    <div class="search"><i class="bi bi-search"></i><input class="input" type="search" name="search" placeholder="TX ref, payment ref, member no…"></div>
    <select class="select" name="type" aria-label="Type"><option value="">All types</option><?php foreach (Ledger::TYPES as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
    <select class="select" name="payment_method" aria-label="Method"><option value="">All methods</option><?php foreach (Ledger::METHODS as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
    <select class="select" name="flow" aria-label="Cash flow"><option value="">In &amp; out</option><option value="in">Cash in</option><option value="out">Cash out</option><option value="none">Non-cash</option></select>
    <?= branch_filter() ?>
    <input class="input" type="date" name="date_from" value="<?= date('Y-m-d', strtotime('-30 days')) ?>" aria-label="From date" title="From">
    <input class="input" type="date" name="date_to" value="<?= today() ?>" aria-label="To date" title="To">
  </form>
  <div id="txnTable"></div>
</section>

<?php ob_start(); ?>
<script>
(function () {
  const A = App;
  const inflow = ['in'], outflow = ['out'];
  async function loadSummary() {
    try { A.fillKpis(document, (await A.get('transactions/summary', table.params(false))).data); } catch (e) { /* non-critical */ }
  }
  const table = new A.DataTable('#txnTable', {
    endpoint: 'transactions/list', exportEndpoint: 'transactions/export', filters: '#txnFilters', perPage: 20,
    empty: 'No transactions in this period', emptyIcon: 'receipt',
    columns: [
      { label: 'Date', sort: 'created_at', render: r => `<span class="nowrap">${A.dateTime(r.created_at)}</span>` },
      { label: 'Reference', render: r => `<span class="mono strong">${A.esc(r.txn_ref)}</span>${r.payment_ref ? `<div class="text-xs muted mono">${A.esc(r.payment_ref)}</div>` : ''}` },
      { label: 'Member', render: r => r.member_id ? `<a href="index.php?page=member&id=${r.member_id}" class="strong nowrap">${A.esc(r.first_name + ' ' + r.last_name)}</a><div class="text-xs muted">${A.esc(r.member_no)}</div>` : '<span class="muted">SACCO</span>' },
      { label: 'Type', render: r => { const [n, i, t] = A.txnInfo(r.type); return `<span class="flex items-center gap-1 nowrap"><span class="txn-ico ${t}" style="width:30px;height:30px;font-size:13px"><i class="bi bi-${i}"></i></span>${A.esc(n)}</span>`; } },
      { label: 'Narration', hideSm: true, render: r => `<span class="text-sm truncate" style="display:inline-block;max-width:220px" title="${A.esc(r.narration || '')}">${A.esc(r.narration || '')}</span>` },
      { label: 'Method', hideSm: true, hideMd: true, render: r => `<span class="tag">${A.esc(A.METHODS[r.payment_method] || r.payment_method)}</span>` },
      { label: 'Amount', sort: 'amount', align: 'right', render: r => `<span class="${inflow.includes(r.flow) ? 'amount-in' : outflow.includes(r.flow) ? 'amount-out' : 'strong'}">${r.flow === 'in' ? '+' : r.flow === 'out' ? '−' : ''}${A.money(r.amount, false)}</span>` },
      { label: 'Status', render: r => A.badge(r.status) },
      { label: 'Posted by', hideSm: true, hideMd: true, render: r => `<span class="text-sm">${A.esc(r.user_name || 'System')}</span>` },
      { label: '', align: 'right', render: r => r.reversible ? `<button class="act danger" data-action="reverse" title="Reverse"><i class="bi bi-arrow-counterclockwise"></i></button>` : '' },
    ],
    onAction: async (action, r) => {
      if (action !== 'reverse') return;
      const reason = await A.confirm({ title: `Reverse ${r.txn_ref}?`, message: `${A.txnInfo(r.type)[0]} of ${A.money(r.amount)} will be undone with an equal and opposite entry.`, confirmText: 'Reverse transaction', input: { label: 'Reason for reversal', placeholder: 'e.g. Posted to wrong account' } });
      if (!reason) return;
      try {
        A.toast((await A.post('transactions/reverse', { id: r.id, reason })).message);
        document.dispatchEvent(new CustomEvent('app:saved', { detail: { route: 'transactions/reverse' } }));
      } catch (e) { A.toast(e.message, 'error'); }
    },
    onLoad: loadSummary,
  });
  A.$('#exportTxns').addEventListener('click', () => (location.href = table.exportUrl()));
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
