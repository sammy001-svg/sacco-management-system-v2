<?php
defined('SACCO') or exit;
$can = [
    'deposit'  => Auth::can('savings.transact'),
    'withdraw' => Auth::can('savings.transact'),
    'shares'   => Auth::can('shares.transact'),
    'repay'    => Auth::can('loans.repay'),
];
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Operations</span><i class="bi bi-chevron-right"></i><span>Teller counter</span></div>
    <h1>Teller counter</h1>
    <p>Find the member, check who is at the counter, post the transaction and hand over the receipt.</p>
  </div>
  <div class="page-actions hide-sm">
    <span class="text-xs muted"><span class="kbd">F2</span> search · <span class="kbd">F3</span> deposit · <span class="kbd">F4</span> withdraw · <span class="kbd">F6</span> shares · <span class="kbd">F7</span> repay · <span class="kbd">F8</span> last receipt</span>
  </div>
</div>

<div class="counter-grid">
  <div>
    <section class="card mb-3">
      <div class="card-body">
        <div class="counter-search">
          <i class="bi bi-search"></i>
          <input class="input" id="counterSearch" type="search" autocomplete="off" placeholder="Member no., name, national ID or phone" aria-label="Find member">
          <span class="kbd">F2</span>
        </div>
        <div class="counter-results" id="counterResults"></div>
      </div>
    </section>

    <section class="card mb-3" id="snapCard" hidden>
      <div class="card-body" id="snap"></div>
    </section>

    <section class="card" id="emptyCard">
      <div class="card-body"><div class="empty"><div class="empty-icon"><i class="bi bi-person-badge"></i></div><h4>No member selected</h4><p>Search above and press Enter to serve the first match.</p></div></div>
    </section>
  </div>

  <aside>
    <section class="card mb-3">
      <div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-safe2"></i></span>My till</h3><p data-till-sub>Loading…</p></div></div>
      <div class="card-body" id="tillPanel"></div>
    </section>
    <section class="card">
      <div class="card-head"><div><h3><span class="h-icon"><i class="bi bi-receipt"></i></span>My postings today</h3><p data-today-sub></p></div></div>
      <div id="todayTable"></div>
    </section>
  </aside>
</div>

<?php require APP_PATH . '/views/partials/till_modals.php'; ?>

<?php ob_start(); ?>
<script>
(function () {
  const A = App, can = <?= json_encode($can) ?>;
  const search = A.$('#counterSearch'), results = A.$('#counterResults');
  let hits = [], focus = 0, member = null, snap = null, till = null, lastRef = null, seq = 0;
  const docUrl = id => `api.php?r=documents/view&id=${id}`;
  const serviceable = s => ['active', 'dormant'].includes(s);

  /* ---------- Search ---------- */
  const find = A.debounce(async () => {
    const q = search.value.trim(), my = ++seq;
    if (!q) { results.innerHTML = ''; hits = []; return; }
    let rows;
    try { rows = (await A.get('members/search', { q })).data; } catch (e) { return A.toast(e.message, 'error'); }
    if (my !== seq) return;
    hits = rows; focus = 0;
    renderHits();
  }, 220);
  function renderHits() {
    results.innerHTML = hits.length ? hits.map((m, i) => `
      <div class="list-row ${i === focus ? 'active' : ''}" data-hit="${i}">${A.avatar(m.name, 'sm')}
        <div class="grow"><strong>${A.esc(m.name)}</strong><div class="text-xs muted"><span class="mono">${A.esc(m.member_no)}</span> · ${A.esc(m.phone || '')} · ${A.esc(m.branch_name || '')}</div></div>
        <span class="text-sm">${A.money(m.savings_total ?? 0)}</span>${A.badge(m.status)}</div>`).join('')
      : '<p class="muted text-sm" style="padding:8px 4px">No member matches that search.</p>';
  }
  search.addEventListener('input', find);
  search.addEventListener('keydown', e => {
    if (e.key === 'ArrowDown' && hits.length) { e.preventDefault(); focus = (focus + 1) % hits.length; renderHits(); }
    else if (e.key === 'ArrowUp' && hits.length) { e.preventDefault(); focus = (focus - 1 + hits.length) % hits.length; renderHits(); }
    else if (e.key === 'Enter') { e.preventDefault(); if (hits[focus]) pick(hits[focus]); }
    else if (e.key === 'Escape') { search.value = ''; results.innerHTML = ''; hits = []; }
  });
  results.addEventListener('click', e => { const r = e.target.closest('[data-hit]'); if (r) pick(hits[+r.dataset.hit]); });

  /* ---------- Member snapshot ---------- */
  async function pick(m) {
    member = m;
    results.innerHTML = ''; hits = []; search.value = '';
    A.$('#emptyCard').hidden = true;
    A.$('#snapCard').hidden = false;
    A.$('#snap').innerHTML = '<div class="muted text-sm">Loading member…</div>';
    await loadSnap();
  }
  async function loadSnap() {
    if (!member) return;
    let id, sum, acc;
    try {
      [id, sum, acc] = await Promise.all([
        A.get('members/identity', { id: member.id }), A.get('members/summary', { id: member.id }), A.get('members/accounts', { member_id: member.id }),
      ]);
    } catch (e) { return A.toast(e.message, 'error'); }
    snap = { id: id.data, sum: sum.data, acc: acc.data };
    renderSnap();
  }
  function renderSnap() {
    const { id, sum, acc } = snap, ok = serviceable(id.status);
    const missing = ['photo', 'signature', 'id_front'].filter(k => !id.docs[k]);
    const active = sum.accounts.filter(a => a.status === 'active');
    const qa = (key, icon, title, hint, kbd, enabled) => `<button class="qa" data-qa="${key}" ${enabled ? '' : 'disabled'}><i class="bi bi-${icon}"></i><strong>${title}</strong><span class="text-xs muted">${hint}</span><span class="kbd">${kbd}</span></button>`;
    A.$('#snap').innerHTML = `
      <div class="snap-head">
        ${id.docs.photo ? `<img class="snap-photo" src="${docUrl(id.docs.photo)}" alt="Member photo">` : `<div class="snap-photo"><i class="bi bi-person"></i></div>`}
        <div class="grow">
          <div class="flex items-center gap-1" style="flex-wrap:wrap"><h2>${A.esc(id.name)}</h2>${A.badge(id.status)}</div>
          <div class="text-sm muted"><span class="mono">${A.esc(id.member_no)}</span> · ID <span class="mono">${A.esc(id.national_id)}</span> · ${A.esc(id.phone || '—')}</div>
          ${id.docs.signature ? `<img class="snap-sign" src="${docUrl(id.docs.signature)}" alt="Specimen signature">` : ''}
          ${missing.length ? `<div class="id-missing text-xs fw-bold text-amber mt-1"><i class="bi bi-exclamation-triangle"></i>&nbsp;Missing ${missing.map(k => ({ photo: 'photo', signature: 'signature', id_front: 'ID copy' })[k]).join(', ')} — check the original ID.</div>` : ''}
        </div>
        <div class="btn-row">
          ${id.docs.id_front ? `<a class="btn btn-ghost btn-sm" href="${docUrl(id.docs.id_front)}" target="_blank"><i class="bi bi-person-vcard"></i><span>ID copy</span></a>` : ''}
          <a class="btn btn-ghost btn-sm" href="index.php?page=member&id=${id.id}"><i class="bi bi-person-lines-fill"></i><span>Profile</span></a>
          <a class="btn btn-ghost btn-sm" href="index.php?page=statement&id=${id.id}" target="_blank"><i class="bi bi-file-earmark-text"></i><span>Statement</span></a>
        </div>
      </div>
      ${ok ? '' : `<div class="alert alert-danger mt-2"><i class="bi bi-slash-circle"></i> This member is ${A.esc(A.label(id.status))}. Counter transactions are not allowed.</div>`}
      <div class="snap-balances">
        <div class="bal"><span>Savings</span><strong>${A.money(sum.savings)}</strong></div>
        <div class="bal"><span>Share capital</span><strong>${A.money(sum.shares.balance)}</strong><div class="text-xs muted">${A.num(sum.shares.shares)} shares</div></div>
        <div class="bal"><span>Loan balance</span><strong class="${sum.loan_balance > 0 ? 'text-amber' : ''}">${A.money(sum.loan_balance)}</strong><div class="text-xs muted">${sum.active_loans} active</div></div>
        <div class="bal"><span>Free to withdraw</span><strong>${A.money(sum.free_savings)}</strong><div class="text-xs muted">after guarantees</div></div>
      </div>
      <div class="quick-actions">
        ${can.deposit ? qa('deposit', 'arrow-down-left-circle', 'Deposit', 'Cash into savings', 'F3', ok && active.length) : ''}
        ${can.withdraw ? qa('withdraw', 'arrow-up-right-circle', 'Withdraw', 'ID check required', 'F4', ok && active.some(a => +a.allow_withdrawal)) : ''}
        ${can.shares ? qa('shares', 'pie-chart', 'Buy shares', 'Grow share capital', 'F6', ok) : ''}
        ${can.repay ? qa('repay', 'cash-coin', 'Repay loan', acc.loans.length ? `${acc.loans.length} active loan(s)` : 'No active loans', 'F7', acc.loans.length > 0) : ''}
      </div>
      <div class="form-section mt-3">Accounts</div>
      <div class="list">${active.map(a => `<div class="list-row"><span class="txn-ico tone-green" style="width:30px;height:30px;font-size:13px"><i class="bi bi-piggy-bank"></i></span>
        <div class="grow"><strong class="mono">${A.esc(a.account_no)}</strong><div class="text-xs muted">${A.esc(a.product_name)}${+a.allow_withdrawal ? '' : ' · locked'}</div></div><strong>${A.money(a.balance)}</strong></div>`).join('')
        + acc.loans.map(l => `<div class="list-row"><span class="txn-ico tone-amber" style="width:30px;height:30px;font-size:13px"><i class="bi bi-cash-stack"></i></span>
        <div class="grow"><strong class="mono">${A.esc(l.loan_no)}</strong><div class="text-xs muted">${A.esc(l.product_name)} · due now ${A.money(l.due_now)}</div></div><strong>${A.money(l.balance)}</strong></div>`).join('')
        || '<p class="muted text-sm">No open accounts.</p>'}</div>`;
  }

  function act(key) {
    if (!snap) { search.focus(); return A.toast('Find the member first.', 'warning'); }
    const btn = A.$(`[data-qa="${key}"]`);
    if (!btn || btn.disabled) return;
    const m = { id: snap.id.id, name: snap.id.name, member_no: snap.id.member_no };
    const active = snap.sum.accounts.filter(a => a.status === 'active');
    const first = list => (list[0] ? list[0].id : '');
    if (key === 'deposit') A.openForm('depositModal', { member_id: m, account_id: first(active) });
    if (key === 'withdraw') A.openForm('withdrawModal', { member_id: m, account_id: first(active.filter(a => +a.allow_withdrawal)) });
    if (key === 'shares') A.openForm('sharesModal', { member_id: m });
    if (key === 'repay') A.openForm('repayModal', { member_id: m, loan_id: first(snap.acc.loans) });
    if (!till && key !== 'repay') A.toast('Your till is not open — cash postings will be refused until it is.', 'warning');
  }
  A.$('#snap').addEventListener('click', e => { const b = e.target.closest('[data-qa]'); if (b) act(b.dataset.qa); });

  /* ---------- Till ---------- */
  async function loadTill() {
    let d;
    try { d = (await A.get('tills/mine')).data; } catch (e) { return; }
    till = d.session;
    const waiting = (d.pending || []).find(p => p.type === 'float');
    const others = (d.pending || []).filter(p => p.type !== 'float');
    const sub = A.$('[data-till-sub]'), panel = A.$('#tillPanel');
    if (!till) {
      sub.innerHTML = waiting ? '<span class="badge warning">Waiting for float</span>' : '<span class="badge danger">Closed</span>';
      panel.innerHTML = `<p class="text-sm muted">${waiting ? `Float of <strong>${A.money(waiting.amount)}</strong> requested ${A.dateTime(waiting.created_at)}. Your till opens once a supervisor releases it.` : d.required ? 'Cash postings are blocked until your till is open.' : 'Tills are optional on this system.'}</p>
        ${waiting ? '' : '<button class="btn btn-primary" style="width:100%" data-open="floatModal"><i class="bi bi-unlock"></i><span>Request float</span></button>'}`;
      return;
    }
    sub.innerHTML = `<span class="badge success">Open</span> since ${A.dateTime(till.opened_at)}`;
    panel.innerHTML = `<div class="till-strip">
        <div><div class="text-xs muted fw-bold">EXPECTED IN DRAWER</div><div class="expected ${till.over_limit ? 'text-red' : ''}">${A.money(till.expected_now)}</div>
          ${till.over_limit ? `<div class="text-xs text-red fw-bold"><i class="bi bi-exclamation-triangle"></i> Above the ${A.money(till.cash_limit)} limit — drop cash to the vault.</div>` : ''}</div>
        <div class="row"><span>Float + top-ups</span><strong>${A.money(+till.opening_float + +till.top_ups)}</strong></div>
        <div class="row"><span>Cash in · ${till.count_in}</span><strong class="text-green">${A.money(till.cash_in)}</strong></div>
        <div class="row"><span>Cash out · ${till.count_out}</span><strong class="text-red">${A.money(till.cash_out)}</strong></div>
        <div class="row"><span>Dropped to vault</span><strong>${A.money(till.drops)}</strong></div>
        ${others.length ? `<div class="text-xs text-amber fw-bold"><i class="bi bi-hourglass-split"></i> ${others.length} request(s) waiting for a supervisor</div>` : ''}
        <div class="btn-row mt-1">
          <button class="btn btn-ghost btn-sm" data-open="topupModal"><i class="bi bi-plus-circle"></i><span>Top-up</span></button>
          <button class="btn btn-ghost btn-sm" data-open="dropModal"><i class="bi bi-box-arrow-in-down"></i><span>Drop</span></button>
          <a class="btn btn-ghost btn-sm" href="index.php?page=till_report&id=${till.id}" target="_blank"><i class="bi bi-printer"></i><span>Report</span></a>
          <a class="btn btn-warning btn-sm" href="index.php?page=tills"><i class="bi bi-lock"></i><span>Close</span></a>
        </div></div>`;
  }

  /* ---------- Today ---------- */
  const today = new A.DataTable('#todayTable', {
    endpoint: 'transactions/list', perPage: 8, compact: true, empty: 'Nothing posted yet today', emptyIcon: 'receipt', emptyHint: 'Your postings appear here with a reprint button.',
    params: () => ({ mine: 1, date_from: A.today(), date_to: A.today() }),
    columns: [
      { label: 'Member', render: r => { const [n] = A.txnInfo(r.type); return `<strong class="text-sm">${A.esc(r.member_id ? r.first_name + ' ' + r.last_name : 'SACCO')}</strong><div class="text-xs muted">${A.esc(n)} · ${A.dateTime(r.created_at).split(',').pop()}</div>`; } },
      { label: 'Amount', align: 'right', render: r => `<span class="${r.status === 'reversed' ? 'muted' : r.flow === 'in' ? 'amount-in' : r.flow === 'out' ? 'amount-out' : 'strong'}" style="${r.status === 'reversed' ? 'text-decoration:line-through' : ''}">${A.money(r.amount, false)}</span>` },
      { label: '', align: 'right', render: r => r.txn_ref ? `<button class="act" data-action="print" title="Print receipt ${A.esc(r.txn_ref)}"><i class="bi bi-printer"></i></button>` : '' },
    ],
    onAction: (a, r) => { if (a === 'print') A.printReceipt(r.txn_ref); },
    onLoad: (res) => { A.$('[data-today-sub]').textContent = `${A.num(res.meta?.total ?? 0)} posting(s)`; },
  });

  /* ---------- Keyboard ---------- */
  document.addEventListener('keydown', e => {
    if (document.querySelector('.modal.open')) return;
    const map = { F2: () => { search.focus(); search.select(); }, F3: () => act('deposit'), F4: () => act('withdraw'), F6: () => act('shares'), F7: () => act('repay'),
      F8: () => (lastRef ? A.printReceipt(lastRef) : A.toast('No receipt yet in this session.', 'info')) };
    if (map[e.key]) { e.preventDefault(); map[e.key](); }
  });

  document.addEventListener('app:saved', e => {
    const ref = e.detail?.res?.receipt?.ref;
    if (ref) lastRef = ref;
    loadSnap(); loadTill(); today.reload();
  });
  loadTill();
  setInterval(() => { if (!document.hidden) loadTill(); }, 30000);
  setTimeout(() => search.focus(), 100);
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
