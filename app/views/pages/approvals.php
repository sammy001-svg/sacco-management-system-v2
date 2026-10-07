<?php
defined('SACCO') or exit;
$decide = Auth::can('approvals.decide');
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Operations</span><i class="bi bi-chevron-right"></i><span>Approvals</span></div>
    <h1>Approvals</h1>
    <p><?= $decide ? 'Sensitive actions waiting for a second person. You cannot approve your own requests.' : 'Requests you have sent for approval.' ?></p>
  </div>
</div>

<section class="card">
  <form id="apFilters" autocomplete="off">
    <input type="hidden" name="status" value="pending">
    <div class="toolbar">
      <div class="tabs" data-tabs="apstatus">
        <?php foreach (['pending' => ['Pending', 'hourglass-split'], 'approved' => ['Approved', 'check2-circle'], 'rejected' => ['Rejected', 'x-octagon'], 'cancelled' => ['Cancelled', 'slash-circle']] as $k => [$t, $i]): ?>
          <button type="button" class="tab <?= $k === 'pending' ? 'active' : '' ?>" data-tab="ap-<?= $k ?>" data-status="<?= $k ?>"><i class="bi bi-<?= $i ?>"></i><?= $t ?></button>
        <?php endforeach; ?>
      </div>
      <select class="select" name="type"><option value="">All types</option><?php foreach (ApprovalService::TYPES as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
    </div>
  </form>
  <div id="apTable"></div>
</section>

<div class="modal" id="apModal" role="dialog" aria-modal="true" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-head"><div class="modal-icon warning"><i class="bi bi-check2-square"></i></div><div class="modal-title"><h3 data-ap-title>Request</h3><p data-ap-sub></p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body" data-ap-body></div>
    <div class="modal-foot" data-ap-actions></div>
  </div>
</div>

<?php ob_start(); ?>
<script>
(function () {
  const A = App, form = A.$('#apFilters');
  const tones = { pending: 'warning', approved: 'success', rejected: 'danger', cancelled: '' };
  let current = null;

  const table = new A.DataTable('#apTable', {
    endpoint: 'approvals/list', filters: form, empty: 'Nothing here', emptyIcon: 'check2-all', emptyHint: 'No requests in this view.',
    onRowClick: r => show(r),
    columns: [
      { label: '#', render: r => `<span class="mono strong">#${r.id}</span>` },
      { label: 'Type', render: r => `<span class="badge no-dot warning">${A.esc(r.type_label)}</span>` },
      { label: 'Request', render: r => `<span class="text-sm">${A.esc(r.summary)}</span>` },
      { label: 'Amount', align: 'right', render: r => r.amount !== null ? `<span class="strong">${A.money(r.amount, false)}</span>` : '—' },
      { label: 'Requested by', hideSm: true, render: r => `${A.esc(r.requested_by_name)}<div class="text-xs muted">${A.timeAgo(r.created_at)}</div>` },
      { label: 'Status', render: r => `<span class="badge ${tones[r.status]}">${A.label(r.status)}</span>` },
      { label: '', align: 'right', render: r => r.can_decide ? '<button class="btn btn-primary btn-xs" data-action="approve"><i class="bi bi-check2"></i>Approve</button>' : '' },
    ],
    onAction: (a, r) => { if (a === 'approve') { current = r; approve(); } },
  });

  function show(r) {
    current = r;
    A.$('[data-ap-title]').textContent = `#${r.id} · ${r.type_label}`;
    A.$('[data-ap-sub]').textContent = `Requested by ${r.requested_by_name} · ${A.dateTime(r.created_at)}${r.branch_name ? ' · ' + r.branch_name : ''}`;
    A.$('[data-ap-body]').innerHTML = `<p class="mb-2" style="font-size:15px">${A.esc(r.summary)}</p>
      <div class="kv">
        <div><span>Amount</span><strong>${r.amount !== null ? A.money(r.amount) : '—'}</strong></div>
        <div><span>Status</span><strong><span class="badge ${tones[r.status]}">${A.label(r.status)}</span></strong></div>
        ${r.member_id ? `<div><span>Member</span><strong><a href="index.php?page=member&id=${r.member_id}">Open profile</a></strong></div>` : ''}
        ${r.decided_by_name ? `<div><span>Decided by</span><strong>${A.esc(r.decided_by_name)} · ${A.date(r.decided_at)}</strong></div>` : ''}
        ${r.note ? `<div><span>Note</span><strong>${A.esc(r.note)}</strong></div>` : ''}
        ${r.result ? `<div><span>Result</span><strong>${A.esc(r.result)}</strong></div>` : ''}
      </div>
      ${r.status === 'pending' && !r.can_decide && !r.can_cancel ? '<div class="info-strip warning mt-2"><i class="bi bi-person-lock"></i><span>Another supervisor must decide this request.</span></div>' : ''}`;
    const b = [];
    if (r.can_cancel) b.push('<button class="btn btn-ghost text-red left" data-ap="cancel"><i class="bi bi-slash-circle"></i><span>Cancel request</span></button>');
    if (r.can_decide) b.push('<button class="btn btn-ghost text-red" data-ap="reject"><i class="bi bi-x-octagon"></i><span>Reject</span></button><button class="btn btn-primary" data-ap="approve"><i class="bi bi-check2-circle"></i><span>Approve &amp; apply</span></button>');
    else b.push('<button class="btn btn-primary" data-close>Close</button>');
    A.$('[data-ap-actions]').innerHTML = b.join('');
    A.Modal.open('apModal');
  }

  async function approve() {
    if (!await A.confirm({ title: `Approve request #${current.id}?`, message: current.summary + ' — it is applied immediately.', confirmText: 'Approve & apply', tone: 'primary', icon: 'check2-circle' })) return;
    try { A.toast((await A.post('approvals/approve', { id: current.id })).message); A.Modal.close('apModal'); done(); }
    catch (e) { A.toast(e.message, 'error'); }
  }
  async function decide(action) {
    const reason = await A.confirm(action === 'reject'
      ? { title: `Reject request #${current.id}?`, confirmText: 'Reject', input: { label: 'Reason', placeholder: 'Tell the requester why' } }
      : { title: 'Cancel this request?', confirmText: 'Cancel request' });
    if (!reason) return;
    try { A.toast((await A.post('approvals/' + action, { id: current.id, note: reason === true ? '' : reason })).message); A.Modal.close('apModal'); done(); }
    catch (e) { A.toast(e.message, 'error'); }
  }
  const done = () => document.dispatchEvent(new CustomEvent('app:saved', { detail: { route: 'approvals' } }));

  A.$('#apModal').addEventListener('click', e => {
    const b = e.target.closest('[data-ap]');
    if (!b) return;
    b.dataset.ap === 'approve' ? approve() : decide(b.dataset.ap);
  });
  A.$('[data-tabs="apstatus"]').addEventListener('click', e => {
    const t = e.target.closest('[data-status]');
    if (t) { form.elements.namedItem('status').value = t.dataset.status; table.reload(true); }
  });
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
