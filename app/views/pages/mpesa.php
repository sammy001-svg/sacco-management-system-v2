<?php
defined('SACCO') or exit;
$manage = Auth::can('mpesa.manage');
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Operations</span><i class="bi bi-chevron-right"></i><span>M-Pesa payments</span></div>
    <h1>M-Pesa payments</h1>
    <p>Payment prompts sent to members' phones. Confirmed payments post to the member's account automatically, with the M-Pesa receipt as reference.</p>
  </div>
  <?php if (Auth::can('mpesa.request')): ?>
  <div class="page-actions"><button class="btn btn-primary" data-open="stkModal"><i class="bi bi-phone-vibrate"></i><span>Request payment</span></button></div>
  <?php endif; ?>
</div>

<div data-mpesa-alert></div>
<div class="grid-kpi mb-3" data-kpis></div>

<section class="card">
  <div class="card-head"><h3><span class="h-icon"><i class="bi bi-phone"></i></span>Payment requests</h3></div>
  <form class="toolbar" id="mpesaFilters" autocomplete="off">
    <div class="search"><i class="bi bi-search"></i><input class="input" type="search" name="search" placeholder="M-Pesa receipt, member no. or phone"></div>
    <select class="select" name="status"><option value="">All statuses</option><?php foreach (MpesaService::STATUSES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select>
    <select class="select" name="purpose"><option value="">All purposes</option><?php foreach (MpesaService::PURPOSES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select>
    <input class="input" type="date" name="date_from" value="<?= date('Y-m-d', strtotime('-7 days')) ?>" aria-label="From">
    <input class="input" type="date" name="date_to" value="<?= today() ?>" aria-label="To">
  </form>
  <div id="mpesaTable"></div>
</section>

<?php ob_start(); ?>
<script>
(function () {
  const A = App, manage = <?= $manage ? 'true' : 'false' ?>;
  const tone = { pending: 'pending', paid: 'active', unposted: 'overdue', failed: 'rejected', cancelled: 'closed', timeout: 'dormant' };

  async function kpis() {
    let d;
    try { d = (await A.get('mpesa/summary')).data; } catch (e) { return; }
    A.$('[data-mpesa-alert]').innerHTML = !d.configured
      ? `<div class="alert alert-danger mb-3"><i class="bi bi-plug"></i> M-Pesa is not connected. Add the Daraja keys to <code>.env</code> — see <a href="index.php?page=settings#integrations">Settings → Integrations</a>.</div>`
      : d.env !== 'live' ? '<div class="alert alert-warning mb-3"><i class="bi bi-cone-striped"></i> Sandbox mode: prompts go to Safaricom\'s test system and no real money moves. Set <code>MPESA_ENV=live</code> to go live.</div>' : '';
    A.$('[data-kpis]').innerHTML = [
      ['Prompts today', A.num(d.requests || 0), 'navy', 'send'],
      ['Paid today', `${A.num(d.paid || 0)} · ${A.money(d.amount)}`, 'green', 'check2-circle'],
      ['Waiting for PIN', A.num(d.pending || 0), 'amber', 'hourglass-split'],
      ['Needs posting', A.num(d.unposted || 0), d.unposted > 0 ? 'rose' : 'teal', 'exclamation-octagon'],
    ].map(([l, v, t, i]) => `<div class="stat-card" data-tone="${t}"><div class="stat-top"><span class="stat-label">${l}</span><span class="stat-icon"><i class="bi bi-${i}"></i></span></div><div class="stat-value" style="font-size:22px">${v}</div></div>`).join('');
  }

  const table = new A.DataTable('#mpesaTable', {
    endpoint: 'mpesa/list', filters: '#mpesaFilters', empty: 'No payment requests', emptyIcon: 'phone',
    columns: [
      { label: 'When', render: r => `<span class="nowrap">${A.dateTime(r.created_at)}</span><div class="text-xs muted">${A.esc(r.requested_by_name || '')}</div>` },
      { label: 'Member', render: r => `<a href="index.php?page=member&id=${r.member_id}" class="strong nowrap">${A.esc(r.first_name + ' ' + r.last_name)}</a><div class="text-xs muted">${A.esc(r.member_no)} · ${A.esc(r.phone_masked)}</div>` },
      { label: 'For', hideSm: true, render: r => A.esc(r.purpose_label) },
      { label: 'Amount', align: 'right', render: r => `<strong>${A.money(r.paid_amount || r.amount, false)}</strong>` },
      { label: 'M-Pesa receipt', render: r => r.mpesa_receipt ? `<span class="mono">${A.esc(r.mpesa_receipt)}</span>` : (r.status === 'paid' && r.confirmed_via === 'query' ? '<span class="text-xs muted">Confirmed by status check</span>' : '—') },
      { label: 'Posted', hideSm: true, render: r => r.txn_ref ? `<span class="mono text-sm">${A.esc(r.txn_ref)}</span>` : (r.post_error ? `<span class="text-xs text-red" title="${A.esc(r.post_error)}">${A.esc(r.post_error.slice(0, 60))}</span>` : '—') },
      { label: 'Status', render: r => `${A.badge(tone[r.status] || r.status, r.status_label)}${['failed', 'cancelled', 'timeout'].includes(r.status) && r.result_desc ? `<div class="text-xs muted" style="max-width:200px">${A.esc(r.result_desc)}</div>` : ''}` },
      { label: '', align: 'right', render: r => `<span class="nowrap">${r.status === 'pending' ? '<button class="act" data-action="query" title="Check with M-Pesa"><i class="bi bi-arrow-repeat"></i></button>' : ''}`
          + (r.txn_ref ? '<button class="act" data-action="print" title="Print receipt"><i class="bi bi-printer"></i></button>' : '')
          + (r.status === 'unposted' && manage ? '<button class="act" data-action="repost" title="Post again"><i class="bi bi-arrow-clockwise"></i></button><button class="act" data-action="savings" title="Post to savings instead"><i class="bi bi-piggy-bank"></i></button>' : '') + '</span>' },
    ],
    onAction: async (a, r) => {
      try {
        if (a === 'print') return A.printReceipt(r.txn_ref);
        if (a === 'query') A.toast((await A.post('mpesa/query', { id: r.id })).message, 'info');
        if (a === 'repost' || a === 'savings') {
          if (!(await A.confirm({ title: a === 'savings' ? 'Post to savings?' : 'Post again?', message: a === 'savings' ? `${A.money(r.paid_amount)} (${r.mpesa_receipt || 'M-Pesa'}) will be deposited to the member's main savings account instead.` : 'Try posting this payment to its original account again.', confirmText: 'Post', tone: 'primary', icon: 'arrow-clockwise' }))) return;
          A.toast((await A.post('mpesa/repost', { id: r.id, target: a === 'savings' ? 'savings' : '' })).message);
        }
        table.reload(); kpis();
      } catch (e) { A.toast(e.message, 'error'); }
    },
  });

  document.addEventListener('app:saved', () => { kpis(); });
  kpis();
  setInterval(() => { if (!document.hidden) { kpis(); table.reload(); } }, 20000);
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
