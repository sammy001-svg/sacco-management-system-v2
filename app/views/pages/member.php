<?php
defined('SACCO') or exit;
$m = MemberService::findOrFail(input_int('id'));
$name = member_name($m);
$pageTitle = $name;
$picker = json_encode(['id' => (int) $m['id'], 'name' => $name, 'member_no' => $m['member_no']]);
$prefill = fn(array $extra = []) => e(json_encode(['member_id' => json_decode($picker, true)] + $extra));
$statusActions = array_diff(MemberService::STATUSES, [$m['status'], 'pending']);
?>
<div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><a href="index.php?page=members">Members</a><i class="bi bi-chevron-right"></i><span><?= e($m['member_no']) ?></span></div>

<section class="profile-hero">
  <div class="row">
    <span class="avatar xl" data-hero-avatar data-hue="<?= crc32($name) % 6 ?: '' ?>"><?= e(initials($name)) ?></span>
    <div style="min-width:0">
      <div class="flex items-center gap-1 wrap"><h2><?= e($name) ?></h2><span class="badge"><?= e(label($m['status'])) ?></span></div>
      <div class="sub">
        <span><i class="bi bi-hash"></i><?= e($m['member_no']) ?></span>
        <span><i class="bi bi-telephone"></i><?= e($m['phone']) ?></span>
        <?php if ($m['email']): ?><span><i class="bi bi-envelope"></i><?= e($m['email']) ?></span><?php endif; ?>
        <span><i class="bi bi-geo-alt"></i><?= e($m['branch_name']) ?></span>
        <span><i class="bi bi-calendar-check"></i>Member since <?= fmt_date($m['joined_at'], 'M Y') ?></span>
      </div>
    </div>
    <div class="actions">
      <?php if (Auth::can('savings.transact')): ?>
        <button class="btn btn-primary" data-open="depositModal" data-prefill="<?= $prefill() ?>"><i class="bi bi-arrow-down-left-circle"></i><span>Deposit</span></button>
        <button class="btn" data-open="withdrawModal" data-prefill="<?= $prefill() ?>"><i class="bi bi-arrow-up-right-circle"></i><span>Withdraw</span></button>
      <?php endif; ?>
      <?php if (Auth::can('loans.apply')): ?><button class="btn" data-open="loanApplyModal" data-prefill="<?= $prefill() ?>"><i class="bi bi-file-earmark-plus"></i><span>Apply loan</span></button><?php endif; ?>
      <div class="dropdown">
        <button class="btn" type="button" data-dropdown-toggle aria-label="More actions"><i class="bi bi-three-dots"></i></button>
        <div class="dropdown-menu">
          <?php if (Auth::can('shares.transact')): ?><button class="dropdown-item" data-open="sharesModal" data-prefill="<?= $prefill() ?>"><i class="bi bi-pie-chart"></i>Buy shares</button><?php endif; ?>
          <?php if (Auth::can('loans.repay')): ?><button class="dropdown-item" data-open="repayModal" data-prefill="<?= $prefill() ?>"><i class="bi bi-cash-coin"></i>Loan repayment</button><?php endif; ?>
          <?php if (Auth::can('savings.transact')): ?><button class="dropdown-item" data-open="openAccountModal" data-prefill="<?= $prefill() ?>"><i class="bi bi-wallet2"></i>Open savings account</button><?php endif; ?>
          <a class="dropdown-item" href="index.php?page=statement&id=<?= (int) $m['id'] ?>" target="_blank"><i class="bi bi-printer"></i>Print statement</a>
          <?php if (Auth::can('members.manage')): ?>
            <div class="dropdown-divider"></div>
            <button class="dropdown-item" data-edit-member><i class="bi bi-pencil-square"></i>Edit details</button>
            <?php foreach ($statusActions as $s): ?>
              <button class="dropdown-item <?= in_array($s, ['suspended', 'exited'], true) ? 'danger' : '' ?>" data-set-status="<?= $s ?>"><i class="bi bi-<?= ['active' => 'person-check', 'dormant' => 'moon-stars', 'suspended' => 'slash-circle', 'exited' => 'door-open'][$s] ?? 'person-gear' ?>"></i>Mark as <?= label($s) ?></button>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<div class="grid-kpi mb-3">
  <?= kpi_card('Total savings', 'savings', 'piggy-bank', 'green', 'money', '<span data-txt="accounts"></span>') ?>
  <?= kpi_card('Share capital', 'share_capital', 'pie-chart', 'teal', 'money', '<span data-txt="shares"></span> shares') ?>
  <?= kpi_card('Loan balance', 'loan_balance', 'cash-stack', 'amber', 'money', '<span data-txt="loans"></span>') ?>
  <?= kpi_card('Free to guarantee', 'free_savings', 'shield-check', 'navy', 'money', 'Guaranteeing <span data-txt="guaranteed"></span>') ?>
</div>

<section class="card">
  <div class="underline-tabs" data-tabs="member">
    <button class="tab active" data-tab="tab-accounts"><i class="bi bi-wallet2"></i>Savings accounts</button>
    <button class="tab" data-tab="tab-loans"><i class="bi bi-cash-stack"></i>Loans</button>
    <button class="tab" data-tab="tab-txns"><i class="bi bi-arrow-left-right"></i>Transactions</button>
    <button class="tab" data-tab="tab-guarantees"><i class="bi bi-shield-check"></i>Guarantees</button>
    <button class="tab" data-tab="tab-docs"><i class="bi bi-folder2"></i>Documents</button>
    <button class="tab" data-tab="tab-profile"><i class="bi bi-person-vcard"></i>Profile</button>
  </div>

  <div class="tab-panel active" id="tab-accounts" data-group="member"><div class="table-wrap"><table class="table">
    <thead><tr><th>Account</th><th>Product</th><th class="num">Balance</th><th class="hide-sm">Rate</th><th>Status</th><th class="hide-sm">Opened</th><th></th></tr></thead>
    <tbody data-accounts-body><tr><td colspan="7"><span class="skeleton"></span></td></tr></tbody></table></div></div>

  <div class="tab-panel" id="tab-loans" data-group="member"><div id="memberLoans"></div></div>
  <div class="tab-panel" id="tab-txns" data-group="member"><div id="memberTxns"></div></div>
  <div class="tab-panel" id="tab-guarantees" data-group="member"><div class="list" data-guarantees></div></div>

  <div class="tab-panel" id="tab-docs" data-group="member">
    <div class="toolbar" style="justify-content:space-between">
      <div class="kyc-list" data-kyc></div>
      <?php if (Auth::can('members.manage')): ?><button class="btn btn-primary btn-sm" data-open="docModal"><i class="bi bi-cloud-upload"></i><span>Upload document</span></button><?php endif; ?>
    </div>
    <div class="doc-grid" data-docs></div>
  </div>

  <div class="tab-panel" id="tab-profile" data-group="member"><div class="card-body">
    <div class="form-section">Personal</div>
    <div class="kv mb-3">
      <div><span>Full name</span><strong><?= e($name) ?></strong></div>
      <div><span>Gender</span><strong><?= e(label($m['gender'] ?? '') ?: '—') ?></strong></div>
      <div><span>Date of birth</span><strong><?= fmt_date($m['date_of_birth']) ?></strong></div>
      <div><span>National ID</span><strong><?= e($m['national_id']) ?></strong></div>
      <div><span>Tax PIN</span><strong><?= e($m['tax_pin'] ?: '—') ?></strong></div>
      <div><span>Address</span><strong><?= e($m['address'] ?: '—') ?></strong></div>
    </div>
    <div class="form-section">Employment</div>
    <div class="kv mb-3">
      <div><span>Occupation</span><strong><?= e($m['occupation'] ?: '—') ?></strong></div>
      <div><span>Employer</span><strong><?= e($m['employer'] ?: '—') ?></strong></div>
      <div><span>Monthly income</span><strong><?= $m['monthly_income'] !== null ? e(money($m['monthly_income'])) : '—' ?></strong></div>
    </div>
    <div class="form-section">Next of kin</div>
    <div class="kv">
      <div><span>Name</span><strong><?= e($m['next_of_kin_name'] ?: '—') ?></strong></div>
      <div><span>Phone</span><strong><?= e($m['next_of_kin_phone'] ?: '—') ?></strong></div>
      <div><span>Relationship</span><strong><?= e($m['next_of_kin_relationship'] ?: '—') ?></strong></div>
    </div>
  </div></div>
</section>

<?php if (Auth::can('members.manage')): ?>
<div class="modal" id="docModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="documents/upload" novalidate>
    <div class="modal-head"><div class="modal-icon"><i class="bi bi-cloud-upload"></i></div><div class="modal-title"><h3>Upload KYC document</h3><p>JPG, PNG, WEBP or PDF up to 5 MB. Stored privately, never publicly reachable.</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <input type="hidden" name="member_id" data-keep value="<?= (int) $m['id'] ?>">
      <div class="field"><label>Document type <span class="req">*</span></label><select class="select" name="type" required><option value="">Choose…</option>
        <?php foreach (['photo' => 'Passport photo', 'id_front' => 'National ID — front', 'id_back' => 'National ID — back', 'signature' => 'Signature specimen', 'tax_pin' => 'Tax PIN certificate', 'payslip' => 'Payslip', 'other' => 'Other document'] as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?>
      </select></div>
      <div class="field"><label class="dropzone" data-dropzone><input type="file" name="file" accept="image/jpeg,image/png,image/webp,application/pdf" required><i class="bi bi-image" style="font-size:30px"></i><div class="fw-bold mt-1" data-dz-label>Drop a file here or click to choose</div><div class="text-xs">On a phone you can take a photo directly</div></label><div class="field-error" data-error-for="file"></div></div>
      <img data-dz-preview alt="" style="display:none;max-width:100%;max-height:220px;border-radius:12px;margin:0 auto">
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-cloud-upload"></i><span>Upload</span></button></div>
  </form>
</div>
<?php endif; ?>

<?php ob_start(); ?>
<script>
(function () {
  const A = App, memberId = <?= (int) $m['id'] ?>, member = <?= $picker ?>;
  const canTransact = A.can('savings.transact');
  let txnAccount = null; // set when "statement" is clicked on a savings account

  async function loadSummary() {
    let d;
    try { d = (await A.get('members/summary', { id: memberId })).data; } catch (e) { A.toast(e.message, 'error'); return; }
    A.fillKpis(document, { savings: d.savings, share_capital: d.shares.balance, loan_balance: d.loan_balance, free_savings: d.free_savings });
    const txt = (k, v) => (A.$(`[data-txt="${k}"]`).textContent = v);
    txt('accounts', `${d.accounts.filter(a => a.status !== 'closed').length} account(s)`);
    txt('shares', A.num(d.shares.shares));
    txt('loans', `${d.active_loans} active${d.pending_loans ? ` · ${d.pending_loans} in pipeline` : ''}`);
    txt('guaranteed', A.money(d.guaranteed));

    A.$('[data-accounts-body]').innerHTML = d.accounts.map(a => `<tr>
      <td><span class="mono strong">${A.esc(a.account_no)}</span></td>
      <td>${A.esc(a.product_name)}${Number(a.allow_withdrawal) ? '' : ' <span class="tag"><i class="bi bi-lock"></i>Locked</span>'}</td>
      <td class="num strong">${A.money(a.balance)}</td>
      <td class="hide-sm">${Number(a.interest_rate)}% p.a.</td>
      <td>${A.badge(a.status)}</td>
      <td class="hide-sm">${A.date(a.opened_at)}</td>
      <td class="num"><div class="row-actions">${canTransact && a.status === 'active' ? `
        <button class="act" title="Deposit" data-acc-act="depositModal" data-acc="${a.id}"><i class="bi bi-arrow-down-left-circle"></i></button>
        ${Number(a.allow_withdrawal) ? `<button class="act" title="Withdraw" data-acc-act="withdrawModal" data-acc="${a.id}"><i class="bi bi-arrow-up-right-circle"></i></button>` : ''}` : ''}
        <button class="act" title="Statement" data-acc-statement="${a.id}"><i class="bi bi-list-columns-reverse"></i></button></div></td></tr>`).join('');

    A.$('[data-guarantees]').innerHTML = d.guarantees.length ? d.guarantees.map(g => `<a class="list-item" href="#" data-view-loan="${g.loan_id}">
        ${A.avatar(g.first_name + ' ' + g.last_name, 'sm')}<div class="meta"><strong>${A.esc(g.first_name + ' ' + g.last_name)}</strong><span>${A.esc(g.loan_no)} · ${A.esc(g.member_no)} · loan balance ${A.money(g.balance)}</span></div>
        <div class="end"><strong>${A.money(g.amount)}</strong>${A.badge(g.status === 'active' ? g.loan_status : 'released')}</div></a>`).join('')
      : '<div class="empty"><div class="empty-icon"><i class="bi bi-shield"></i></div><h4>No guarantees</h4><p>This member is not guaranteeing any loans.</p></div>';
  }

  new A.DataTable('#memberLoans', {
    endpoint: 'loans/list', params: { member_id: memberId }, perPage: 10, empty: 'No loans yet', emptyIcon: 'cash-stack', emptyHint: 'Loan applications for this member will appear here.',
    onRowClick: r => A.loans.view(r.id),
    columns: [
      { label: 'Loan', render: r => `<span class="mono strong">${A.esc(r.loan_no)}</span><div class="text-xs muted">${A.esc(r.product_name)}</div>` },
      { label: 'Principal', align: 'right', render: r => A.money(r.principal, false) },
      { label: 'Balance', align: 'right', render: r => `<span class="strong">${A.money(r.balance, false)}</span>` },
      { label: 'Arrears', align: 'right', hideSm: true, render: r => Number(r.arrears) ? `<span class="text-red fw-bold">${A.money(r.arrears, false)}</span><div class="text-xs muted">${r.days_overdue} days</div>` : '—' },
      { label: 'Status', render: r => A.badge(r.status) },
      { label: 'Applied', hideSm: true, render: r => A.date(r.applied_at) },
    ],
  });

  const txns = new A.DataTable('#memberTxns', {
    endpoint: 'transactions/list', params: () => Object.assign({ member_id: memberId }, txnAccount ? { account_type: 'savings', account_id: txnAccount } : {}),
    perPage: 15, empty: 'No transactions yet', emptyIcon: 'receipt',
    columns: [
      { label: 'Date', render: r => `<span class="nowrap">${A.dateTime(r.created_at)}</span>` },
      { label: 'Reference', render: r => `<span class="mono">${A.esc(r.txn_ref)}</span>${r.payment_ref ? `<div class="text-xs muted">${A.esc(r.payment_ref)}</div>` : ''}` },
      { label: 'Type', render: r => { const [n, i, t] = A.txnInfo(r.type); return `<span class="flex items-center gap-1"><span class="txn-ico ${t}" style="width:30px;height:30px;font-size:13px"><i class="bi bi-${i}"></i></span>${A.esc(n)}</span>`; } },
      { label: 'Narration', hideSm: true, render: r => `<span class="text-sm">${A.esc(r.narration || '')}</span>` },
      { label: 'Amount', align: 'right', render: r => `<span class="${['deposit', 'share_purchase', 'share_transfer_in'].includes(r.type) ? 'amount-in' : ['withdrawal', 'withdrawal_fee', 'share_transfer_out', 'penalty'].includes(r.type) ? 'amount-out' : 'strong'}">${A.money(r.amount, false)}</span>` },
      { label: 'Balance', align: 'right', hideSm: true, render: r => r.balance_after !== null ? A.money(r.balance_after, false) : '—' },
      { label: 'Status', render: r => A.badge(r.status) },
    ],
  });

  document.addEventListener('click', e => {
    const act = e.target.closest('[data-acc-act]');
    if (act) A.openForm(act.dataset.accAct, { member_id: member, account_id: act.dataset.acc });
    const st = e.target.closest('[data-acc-statement]');
    if (st) { txnAccount = st.dataset.accStatement; A.$('[data-tab="tab-txns"]').click(); txns.reload(true); A.toast('Showing transactions for the selected account. Switch tabs to reset.', 'info', 'Account statement'); }
    const vl = e.target.closest('[data-view-loan]');
    if (vl) { e.preventDefault(); A.loans.view(vl.dataset.viewLoan); }
    if (e.target.closest('[data-edit-member]')) A.members.edit(memberId);
    const ss = e.target.closest('[data-set-status]');
    if (ss) A.members.setStatus(member, ss.dataset.setStatus);
  });
  A.$('[data-tabs="member"]').addEventListener('tab:change', e => { if (e.detail !== 'tab-txns' && txnAccount) { txnAccount = null; txns.reload(true); } });

  document.addEventListener('app:saved', e => {
    const r = e.detail.route || '';
    if (r === 'members/save' || r === 'members/set_status') { setTimeout(() => location.reload(), 600); return; }
    loadSummary();
    if (r === 'documents/upload') loadDocs();
  });
  loadSummary();
  async function loadDocs() {
    let r;
    try { r = await A.get('documents/list', { member_id: memberId }); } catch (e) { return; }
    const canManage = A.can('members.manage');
    A.$('[data-kyc]').innerHTML = (r.kyc.complete ? '<span class="badge success">KYC complete</span>' : `<span class="badge warning">KYC incomplete · ${r.kyc.required.length - r.kyc.missing.length} of ${r.kyc.required.length}</span>`)
      + r.kyc.required.map(t => `<span class="tag">${r.kyc.missing.includes(t) ? '<i class="bi bi-circle text-amber"></i>' : '<i class="bi bi-check-circle-fill text-green"></i>'}${A.esc(r.types[t])}</span>`).join('');
    A.$('[data-tab="tab-docs"]').innerHTML = `<i class="bi bi-folder2"></i>Documents${r.kyc.complete ? '' : ' <span class="count" style="background:var(--warning-soft);color:var(--warning)">!</span>'}`;
    A.$('[data-docs]').innerHTML = r.data.length ? r.data.map(d => {
      const url = `api.php?r=documents/view&id=${d.id}`;
      return `<div class="doc-card"><a class="doc-thumb" href="${url}" target="_blank" rel="noopener">${d.mime.startsWith('image/') ? `<img src="${url}" alt="${A.esc(r.types[d.type])}" loading="lazy">` : '<i class="bi bi-file-earmark-pdf"></i>'}</a>
        <div class="doc-meta"><strong>${A.esc(r.types[d.type] || d.type)}</strong><span class="muted">${A.esc(d.original_name)}</span><div class="text-xs muted">${A.date(d.created_at)} · ${(d.size / 1024).toFixed(0)} KB${d.uploaded_by_name ? ' · ' + A.esc(d.uploaded_by_name) : ''}</div></div>
        <div class="doc-actions"><a class="act" href="${url}&download=1" title="Download"><i class="bi bi-download"></i></a>${canManage ? `<button class="act danger" data-doc-del="${d.id}" title="Delete"><i class="bi bi-trash3"></i></button>` : ''}</div></div>`;
    }).join('') : '<div class="empty" style="grid-column:1/-1"><div class="empty-icon"><i class="bi bi-folder2-open"></i></div><h4>No documents yet</h4><p>Upload a passport photo, ID copies and a signature specimen to complete KYC.</p></div>';
    const photo = r.data.find(d => d.type === 'photo');
    const hero = A.$('[data-hero-avatar]');
    hero.classList.toggle('photo', !!photo);
    hero.style.backgroundImage = photo ? `url('api.php?r=documents/view&id=${photo.id}')` : '';
  }
  document.addEventListener('click', async e => {
    const d = e.target.closest('[data-doc-del]');
    if (!d || !await A.confirm({ title: 'Delete this document?', confirmText: 'Delete document' })) return;
    try { A.toast((await A.post('documents/delete', { id: d.dataset.docDel })).message); loadDocs(); } catch (err) { A.toast(err.message, 'error'); }
  });
  const dz = A.$('#docModal [data-dropzone]');
  if (dz) {
    const input = A.$('input', dz), lbl = A.$('[data-dz-label]', dz), prev = A.$('#docModal [data-dz-preview]');
    const show = () => {
      const f = input.files[0];
      lbl.textContent = f ? f.name : 'Drop a file here or click to choose';
      if (f && f.type.startsWith('image/')) { prev.src = URL.createObjectURL(f); prev.style.display = 'block'; } else { prev.style.display = 'none'; }
    };
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.add('drag'); }));
    ['dragleave', 'drop'].forEach(ev => dz.addEventListener(ev, e => { e.preventDefault(); dz.classList.remove('drag'); }));
    dz.addEventListener('drop', e => { if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; show(); } });
    A.$('#docModal form').addEventListener('form:reset', show);
  }
  loadDocs();
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
