<?php
defined('SACCO') or exit;
$canMembers = Auth::can('imports.members');
$canCheckoff = Auth::can('imports.checkoff');
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Operations</span><i class="bi bi-chevron-right"></i><span>Imports</span></div>
    <h1>Imports</h1>
    <p>Bring members over from another system and post employer payroll deductions in bulk. Every file is checked before anything is posted.</p>
  </div>
</div>

<div class="grid-2 mb-3">
  <?php if ($canCheckoff): ?>
  <section class="card hoverable">
    <div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-building"></i></span>Payroll deductions</h3><p>An employer's monthly remittance: each row is split into savings, shares and loan repayments.</p></div></div>
    <div class="card-body">
      <p class="text-sm muted mb-2">Columns: <code>member_no</code> (or national ID), <code>savings</code>, <code>shares</code>, <code>loan</code>, optional <code>loan_no</code>. Share amounts buy whole shares; any remainder and loan overpayments go to savings.</p>
      <div class="flex gap-1 wrap"><button class="btn btn-primary" data-open="checkoffModal"><i class="bi bi-cloud-upload"></i><span>Upload deductions</span></button><a class="btn" href="api.php?r=imports/template&kind=checkoff"><i class="bi bi-file-earmark-spreadsheet"></i><span>Template</span></a></div>
    </div>
  </section>
  <?php endif; ?>
  <?php if ($canMembers): ?>
  <section class="card hoverable">
    <div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-people"></i></span>Member migration</h3><p>Create members in bulk with their opening savings and share balances.</p></div></div>
    <div class="card-body">
      <p class="text-sm muted mb-2">Required: <code>first_name</code>, <code>last_name</code>, <code>national_id</code>, <code>phone</code>. Opening balances post against <em>Opening Balance Equity</em>; post your opening bank and loan journals against the same account.</p>
      <div class="flex gap-1 wrap"><button class="btn btn-primary" data-open="membersImportModal"><i class="bi bi-cloud-upload"></i><span>Upload members</span></button><a class="btn" href="api.php?r=imports/template&kind=members"><i class="bi bi-file-earmark-spreadsheet"></i><span>Template</span></a></div>
    </div>
  </section>
  <?php endif; ?>
</div>

<section class="card"><div class="card-head"><h3><span class="h-icon"><i class="bi bi-clock-history"></i></span>Import history</h3></div><div id="impTable"></div></section>

<?php
$dropzone = '<label class="dropzone" data-dropzone><input type="file" name="file" accept=".csv,text/csv" required><i class="bi bi-filetype-csv" style="font-size:30px"></i><div class="fw-bold mt-1" data-dz-label>Drop a CSV file here or click to choose</div><div class="text-xs">Excel: File → Save As → CSV UTF-8 · up to 50,000 rows</div></label><div class="field-error" data-error-for="file"></div>';
?>
<?php if ($canCheckoff): ?>
<div class="modal" id="checkoffModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="imports/upload_checkoff" novalidate>
    <div class="modal-head"><div class="modal-icon"><i class="bi bi-building"></i></div><div class="modal-title"><h3>Upload payroll deductions</h3><p>Nothing posts until you review the file.</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field"><label>Employer <span class="req">*</span></label><input class="input" name="source" required maxlength="120" placeholder="e.g. County Government of Kisumu"></div>
        <div class="field"><label>Payroll period <span class="req">*</span></label><input class="input" type="month" name="period" required data-default="<?= date('Y-m') ?>" value="<?= date('Y-m') ?>"></div>
        <div class="field"><label>Bank reference</label><input class="input" name="payment_ref" maxlength="80" placeholder="EFT / RTGS ref"></div>
        <div class="field"><label>Amount received</label><div class="input-group"><span class="addon"><?= e(currency()) ?></span><input class="input" type="number" name="amount_received" min="0" step="0.01" placeholder="Checks the file total"></div></div>
        <div class="field"><label>Posting date <span class="req">*</span></label><input class="input" type="date" name="posting_date" required data-default="today" max="<?= today() ?>"></div>
      </div>
      <div class="field"><?= $dropzone ?></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-search"></i><span>Validate file</span></button></div>
  </form>
</div>
<?php endif; ?>
<?php if ($canMembers): ?>
<div class="modal" id="membersImportModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="imports/upload_members" novalidate>
    <div class="modal-head"><div class="modal-icon"><i class="bi bi-people"></i></div><div class="modal-title"><h3>Upload members</h3><p>Duplicates, missing fields and bad dates are flagged before import.</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body"><div class="field"><?= $dropzone ?></div>
      <div class="info-strip"><i class="bi bi-info-circle"></i><span>Dates can be YYYY-MM-DD or DD/MM/YYYY. Leave <code>member_no</code> empty to auto-number. <code>branch_code</code> must match a branch code (e.g. HQ).</span></div></div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-search"></i><span>Validate file</span></button></div>
  </form>
</div>
<?php endif; ?>

<div class="modal modal-xl" id="impView" role="dialog" aria-modal="true" aria-hidden="true" data-static>
  <div class="modal-dialog">
    <div class="modal-head"><div class="modal-icon navy"><i class="bi bi-cloud-upload"></i></div><div class="modal-title"><h3 data-i-title>Import</h3><p data-i-sub></p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <div data-i-alert></div>
      <div data-i-progress hidden class="mb-3"><div class="flex justify-between text-sm mb-1"><strong>Posting…</strong><span data-i-count></span></div><div class="progress"><span data-i-bar style="width:0"></span></div></div>
      <div class="grid-kpi mb-3" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px" data-i-kpis></div>
      <form class="toolbar" id="rowFilters" style="padding:0 0 12px" onsubmit="return false">
        <select class="select" name="status"><option value="">All rows</option><option value="error">Errors</option><option value="valid">Ready</option><option value="done">Done</option><option value="failed">Failed</option></select>
      </form>
      <div id="rowsTable"></div>
    </div>
    <div class="modal-foot" data-i-actions></div>
  </div>
</div>

<?php ob_start(); ?>
<script>
(function () {
  const A = App;
  const tones = { validated: 'info', processing: 'warning', completed: 'success', cancelled: '' };
  const rowTone = { valid: 'info', error: 'danger', done: 'success', failed: 'danger' };
  let current = null, rows = null, rowsKind = null, busy = false;

  // Drag & drop + chosen file name
  A.$$('[data-dropzone]').forEach(z => {
    const input = A.$('input', z), lbl = A.$('[data-dz-label]', z);
    const show = () => (lbl.textContent = input.files[0] ? input.files[0].name : 'Drop a CSV file here or click to choose');
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach(ev => z.addEventListener(ev, e => { e.preventDefault(); z.classList.add('drag'); }));
    ['dragleave', 'drop'].forEach(ev => z.addEventListener(ev, e => { e.preventDefault(); z.classList.remove('drag'); }));
    z.addEventListener('drop', e => { if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; show(); } });
    z.closest('form').addEventListener('form:reset', show);
  });

  const hist = new A.DataTable('#impTable', {
    endpoint: 'imports/list', empty: 'No imports yet', emptyIcon: 'cloud-upload', onRowClick: r => open(r.id),
    columns: [
      { label: 'File', render: r => `<span class="strong">${A.esc(r.filename)}</span><div class="text-xs muted">${r.kind === 'members' ? 'Member migration' : 'Payroll deductions'}</div>` },
      { label: 'Uploaded', render: r => `${A.dateTime(r.created_at)}<div class="text-xs muted">${A.esc(r.user_name || '')}</div>` },
      { label: 'Rows', align: 'right', render: r => A.num(r.total_rows) },
      { label: 'Errors', align: 'right', render: r => +r.error_rows ? `<span class="text-red fw-bold">${A.num(r.error_rows)}</span>` : '0' },
      { label: 'Posted', align: 'right', render: r => A.num(r.processed_rows) + (+r.failed_rows ? ` <span class="text-red">(${r.failed_rows} failed)</span>` : '') },
      { label: 'Amount', align: 'right', render: r => A.money(r.amount, false) },
      { label: 'Status', render: r => `<span class="badge ${tones[r.status]}">${A.label(r.status)}</span>` },
    ],
  });

  function render(i) {
    current = i;
    const m = i.meta || {};
    A.$('[data-i-title]').innerHTML = `${A.esc(i.filename)} &nbsp;<span class="badge ${tones[i.status]}">${A.label(i.status)}</span>`;
    A.$('[data-i-sub]').textContent = i.kind === 'members' ? 'Member migration' : `Payroll deductions · ${m.source || ''} · ${m.period || ''}${m.payment_ref ? ' · ref ' + m.payment_ref : ''}`;
    const k = (l, v, tone) => `<div class="stat-card" data-tone="${tone}" style="padding:14px 16px"><span class="stat-label">${l}</span><div class="stat-value" style="font-size:19px">${v}</div></div>`;
    A.$('[data-i-kpis]').innerHTML = k('Rows', A.num(i.total_rows), 'navy') + k('Ready', A.num(i.valid_rows), 'teal') + k('Errors', A.num(i.error_rows), 'rose')
      + k('Posted', A.num(i.processed_rows), 'green') + k(i.kind === 'members' ? 'Opening balances' : 'Total to post', A.money(i.amount), 'amber');
    let alert = '';
    if (i.kind === 'checkoff' && m.amount_received) {
      const diff = Math.round((+i.amount - +m.amount_received) * 100) / 100;
      alert = diff === 0 ? `<div class="info-strip"><i class="bi bi-check2-circle"></i><span>File total matches the ${A.money(m.amount_received)} received.</span></div>`
        : `<div class="info-strip warning"><i class="bi bi-exclamation-triangle"></i><span>Valid rows total ${A.money(i.amount)} but ${A.money(m.amount_received)} was received — a difference of <strong>${A.money(Math.abs(diff))}</strong>.</span></div>`;
    }
    if (+i.error_rows && i.status === 'validated') alert += `<div class="info-strip warning"><i class="bi bi-exclamation-octagon"></i><span>${A.num(i.error_rows)} row(s) have errors and will be skipped. Fix them in a new file if they should be posted.</span></div>`;
    A.$('[data-i-alert]').innerHTML = alert;
    const b = [];
    if (i.status === 'validated' && !busy) b.push('<button class="btn btn-ghost text-red left" data-i="cancel"><i class="bi bi-x-circle"></i><span>Cancel import</span></button>');
    if (['validated', 'processing'].includes(i.status) && +i.valid_rows && !busy) b.push(`<button class="btn btn-primary" data-i="process"><i class="bi bi-send-check"></i><span>${i.status === 'processing' ? 'Resume' : i.kind === 'members' ? 'Import members' : 'Post deductions'} (${A.num(i.valid_rows - i.processed_rows - i.failed_rows)})</span></button>`);
    b.push(`<button class="btn" data-close ${busy ? 'disabled' : ''}>Close</button>`);
    A.$('[data-i-actions]').innerHTML = b.join('');
  }

  async function open(id) {
    render((await A.get('imports/get', { id })).data);
    A.Modal.open('impView');
    if (!rows || rowsKind !== current.kind) {
      rowsKind = current.kind;
      rows = new A.DataTable('#rowsTable', {
        endpoint: 'imports/rows', filters: '#rowFilters', params: () => ({ id: current.id }), perPage: 15, compact: true, empty: 'No rows', emptyIcon: 'list',
        columns: [
          { label: 'Row', render: r => `<span class="mono">${r.row_no}</span>` },
          { label: 'Member', render: r => current.kind === 'members'
              ? `<span class="strong">${A.esc((r.data.first_name || '') + ' ' + (r.data.last_name || ''))}</span><div class="text-xs muted">ID ${A.esc(r.data.national_id || '')} · ${A.esc(r.data.phone || '')}</div>`
              : `<span class="strong">${A.esc(r.data.name || r.data.member_no)}</span><div class="text-xs muted">${A.esc(r.data.member_no)}</div>` },
          ...(current.kind === 'members'
            ? [{ label: 'Opening savings', align: 'right', render: r => A.money(r.data.opening_savings, false) }, { label: 'Opening shares', align: 'right', render: r => A.money(r.data.opening_shares, false) }]
            : [{ label: 'Savings', align: 'right', render: r => A.money(r.data.savings, false) }, { label: 'Shares', align: 'right', render: r => A.money(r.data.shares, false) }, { label: 'Loan', align: 'right', render: r => A.money(r.data.loan, false) }]),
          { label: 'Status', render: r => `<span class="badge ${rowTone[r.status]}">${A.label(r.status)}</span>${r.message ? `<div class="text-xs ${r.status === 'error' || r.status === 'failed' ? 'text-red' : 'muted'}">${A.esc(r.message)}</div>` : ''}${r.member_id && r.status === 'done' ? `<div class="text-xs"><a href="index.php?page=member&id=${r.member_id}">View member</a></div>` : ''}` },
        ],
      });
    } else rows.reload(true);
  }

  async function process() {
    busy = true; render(current);
    const p = A.$('[data-i-progress]'); p.hidden = false;
    try {
      while (true) {
        const i = (await A.post('imports/process', { id: current.id })).data;
        Object.assign(current, i);
        const done = +i.processed_rows + +i.failed_rows;
        A.$('[data-i-count]').textContent = `${A.num(done)} / ${A.num(i.valid_rows)}`;
        A.$('[data-i-bar]').style.width = (i.valid_rows ? done / i.valid_rows * 100 : 100) + '%';
        render(current);
        if (i.status !== 'processing') break;
      }
      A.toast(`${A.num(current.processed_rows)} row(s) posted${+current.failed_rows ? `, ${current.failed_rows} failed — see the row list` : ''}.`, +current.failed_rows ? 'warning' : 'success');
    } catch (e) { A.toast(e.message, 'error'); }
    busy = false; p.hidden = true;
    render((await A.get('imports/get', { id: current.id })).data);
    rows.reload(true); hist.reload();
  }

  A.$('#impView').addEventListener('click', async e => {
    const b = e.target.closest('[data-i]');
    if (!b) return;
    if (b.dataset.i === 'process') {
      if (await A.confirm({ title: current.kind === 'members' ? 'Import these members?' : 'Post these deductions?', message: `${A.num(current.valid_rows)} valid row(s) totalling ${A.money(current.amount)} will be posted.`, confirmText: 'Post now', tone: 'primary', icon: 'send-check' })) process();
    } else if (await A.confirm({ title: 'Cancel this import?', message: 'Nothing has been posted from it.', confirmText: 'Cancel import' })) {
      try { A.toast((await A.post('imports/cancel', { id: current.id })).message); A.Modal.close('impView'); hist.reload(); } catch (err) { A.toast(err.message, 'error'); }
    }
  });
  A.$$('form[data-api^="imports/upload"]').forEach(f => f.addEventListener('form:success', e => setTimeout(() => open(e.detail.id), 250)));
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
