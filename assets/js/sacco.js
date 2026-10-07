/* =====================================================================
   SaccoPro — domain behaviours for the shared pop-up forms
   ===================================================================== */
(function () {
  'use strict';
  const A = window.App;
  const { $, $$, esc, money, num, date, dateTime, badge, avatar, label } = A;

  /* ------------------------------------------------------------------
   | Conditional fields:  data-show-when="field:value|value2"
   | Hidden blocks also disable their inputs so they are neither
   | validated nor submitted.
   * ---------------------------------------------------------------- */
  function fieldValue(form, name) {
    for (const el of form.querySelectorAll(`[name="${CSS.escape(name)}"]`)) {
      if (el.type === 'radio') { if (el.checked) return el.value; continue; }
      if (el.type === 'checkbox') return el.checked ? el.value || '1' : '';
      return el.value;
    }
    return '';
  }
  function applyConditions(form) {
    $$('[data-show-when]', form).forEach(el => {
      const [name, vals] = el.dataset.showWhen.split(':');
      const show = vals.split('|').includes(fieldValue(form, name));
      el.hidden = !show;
      $$('input, select, textarea', el).forEach(i => (i.disabled = !show));
    });
  }
  document.addEventListener('change', e => { const f = e.target.closest('form'); if (f) applyConditions(f); });
  document.addEventListener('form:reset', e => applyConditions(e.target), true);
  $$('form').forEach(applyConditions);

  /* ------------------------------------------------------------------
   | Member accounts → savings / loan selects
   * ---------------------------------------------------------------- */
  async function loadMemberAccounts(form, memberId) {
    const accSelects = $$('select[data-accounts]', form);
    const loanSelects = $$('select[data-loans]', form);
    if (!accSelects.length && !loanSelects.length) return;
    const placeholder = '<option value="">Select a member first</option>';
    if (!memberId) {
      [...accSelects, ...loanSelects].forEach(s => { s.innerHTML = placeholder; s.dispatchEvent(new Event('change', { bubbles: true })); });
      form._accounts = null;
      return;
    }
    [...accSelects, ...loanSelects].forEach(s => (s.innerHTML = '<option value="">Loading…</option>'));
    try {
      const d = (await A.get('members/accounts', { member_id: memberId })).data;
      form._accounts = d;
      accSelects.forEach(s => {
        let list = d.accounts.filter(a => a.status === 'active');
        if (s.dataset.accounts === 'withdrawable') list = list.filter(a => Number(a.allow_withdrawal));
        s.innerHTML = list.length
          ? list.map(a => `<option value="${a.id}" data-balance="${a.balance}" data-min="${a.min_balance}" data-fee="${a.withdrawal_fee}">${esc(a.account_no)} · ${esc(a.product_name)} — ${money(a.balance)}</option>`).join('')
          : '<option value="">No eligible savings accounts</option>';
        if (s.dataset.pending && list.some(a => String(a.id) === String(s.dataset.pending))) s.value = s.dataset.pending;
        delete s.dataset.pending;
        s.dispatchEvent(new Event('change', { bubbles: true }));
      });
      loanSelects.forEach(s => {
        s.innerHTML = d.loans.length
          ? d.loans.map(l => `<option value="${l.id}">${esc(l.loan_no)} · ${esc(l.product_name)} — bal ${money(l.balance)}</option>`).join('')
          : '<option value="">No active loans</option>';
        if (s.dataset.pending && d.loans.some(l => String(l.id) === String(s.dataset.pending))) s.value = s.dataset.pending;
        delete s.dataset.pending;
        s.dispatchEvent(new Event('change', { bubbles: true }));
      });
      form.dispatchEvent(new CustomEvent('accounts:loaded', { detail: d }));
    } catch (e) {
      A.toast(e.message, 'error');
    }
  }

  document.addEventListener('picker:change', e => {
    const form = e.target.closest('form');
    if (form && e.target.dataset.name === 'member_id') loadMemberAccounts(form, e.detail ? e.detail.id : null);
  });

  document.addEventListener('change', e => {
    const s = e.target;
    if (!s.matches || !s.matches('select[data-accounts]')) return;
    const hint = s.closest('.field')?.querySelector('[data-account-hint]');
    if (!hint) return;
    const o = s.selectedOptions[0];
    if (!o || !o.value) { hint.textContent = ''; return; }
    const bal = Number(o.dataset.balance), min = Number(o.dataset.min), fee = Number(o.dataset.fee);
    hint.innerHTML = s.dataset.accounts === 'withdrawable'
      ? `Available: <strong>${money(Math.max(0, bal - min - fee))}</strong> · Min. balance ${money(min)}${fee ? ' · Withdrawal fee ' + money(fee) : ''}`
      : `Current balance: <strong>${money(bal)}</strong>`;
  });

  /* ------------------------------------------------------------------
   | Shares
   * ---------------------------------------------------------------- */
  const sharesForm = $('#sharesModal form');
  if (sharesForm) {
    const total = () => {
      const n = Number(sharesForm.elements.namedItem('shares').value) || 0;
      $('[data-total]', sharesForm).textContent = n ? money(n * A.cfg.sharePrice) : '—';
    };
    sharesForm.addEventListener('input', total);
    sharesForm.addEventListener('form:reset', () => { total(); $('[data-holding]', sharesForm).textContent = '—'; });
    sharesForm.addEventListener('accounts:loaded', e => {
      $('[data-holding]', sharesForm).textContent = `${num(e.detail.shares.shares)} shares · ${money(e.detail.shares.balance)}`;
    });
  }

  const transferForm = $('#transferSharesModal form');
  if (transferForm) {
    const hint = $('[data-from-holding]', transferForm);
    transferForm.addEventListener('picker:change', async e => {
      if (e.target.dataset.name !== 'from_member_id') return;
      hint.textContent = '';
      if (!e.detail) return;
      try {
        const d = (await A.get('members/accounts', { member_id: e.detail.id })).data;
        hint.innerHTML = `Holds <strong>${num(d.shares.shares)}</strong> shares worth ${money(d.shares.balance)}`;
        transferForm.elements.namedItem('shares').max = d.shares.shares;
      } catch (err) { A.toast(err.message, 'error'); }
    });
  }

  /* ------------------------------------------------------------------
   | Loan application: live quote, eligibility, guarantors
   * ---------------------------------------------------------------- */
  const applyForm = $('#loanApplyModal form');
  if (applyForm) {
    const F = n => applyForm.elements.namedItem(n);
    const memberPicker = $('#loanMemberPicker');
    const gBox = $('[data-guarantors]', applyForm);
    const strip = $('[data-eligibility]', applyForm);
    let gIndex = 0;

    const opt = () => F('product_id').selectedOptions[0];
    const q = k => $(`[data-q="${k}"]`, applyForm);

    function resetQuote() {
      q('installment').textContent = money(0);
      ['total_interest', 'total_payable', 'processing_fee', 'net_disbursement'].forEach(k => (q(k).textContent = '—'));
      q('maturity').textContent = '';
      $('[data-schedule]', applyForm).innerHTML = `<tr><td colspan="5"><div class="empty" style="padding:24px"><div class="empty-icon"><i class="bi bi-calculator"></i></div>Choose a product, amount and term to preview.</div></td></tr>`;
    }

    function renderEligibility(el) {
      if (!el) { strip.hidden = true; return; }
      const min = Number(opt()?.dataset.min || 0);
      const short = el.max_eligible !== undefined && el.max_eligible < min;
      strip.hidden = false;
      strip.className = 'info-strip' + (short ? ' warning' : '');
      strip.innerHTML = `<i class="bi bi-${short ? 'exclamation-triangle' : 'shield-check'}"></i><span>Savings <strong>${money(el.savings)}</strong> · Loan balance <strong>${money(el.outstanding)}</strong>${el.pending ? ` · Pending ${money(el.pending)}` : ''}${el.max_eligible !== undefined ? ` · Eligible up to <strong>${money(el.max_eligible)}</strong>` : ''}</span>`;
      if (el.max_eligible !== undefined) $('[data-amount-hint]', applyForm).innerHTML = `${money(min)} – <strong>${money(el.max_eligible)}</strong> for this member`;
    }

    const calc = A.debounce(async () => {
      const product = F('product_id').value, amount = Number(F('amount').value), term = Number(F('term_months').value);
      const member = A.Picker.value(memberPicker);
      if (!product) { resetQuote(); renderEligibility(null); return; }
      try {
        const d = (await A.get('loans/calculate', { product_id: product, amount: amount > 0 ? amount : '', term_months: term || '', member_id: member })).data;
        renderEligibility(d.eligibility || null);
        if (!d.quote || !(term > 0)) { resetQuote(); return; }
        const Q = d.quote;
        q('installment').textContent = money(Q.installment);
        q('total_interest').textContent = money(Q.total_interest);
        q('total_payable').textContent = money(Q.total_payable);
        q('processing_fee').textContent = money(Q.processing_fee);
        q('net_disbursement').textContent = money(Q.net_disbursement);
        q('maturity').textContent = `Matures ${date(Q.maturity_date)}`;
        $('[data-schedule]', applyForm).innerHTML = Q.installments.map(r => `<tr><td>${r.installment_no}</td><td class="nowrap">${date(r.due_date)}</td><td class="num">${money(r.principal, false)}</td><td class="num">${money(r.interest, false)}</td><td class="num strong">${money(r.total, false)}</td></tr>`).join('');
      } catch (e) { /* quote is advisory; server validates on submit */ }
    }, 280);

    function addGuarantor() {
      const i = gIndex++;
      const row = document.createElement('div');
      row.className = 'g-row';
      row.innerHTML = `<div class="field g-member"><label>Guarantor</label><div class="picker" data-name="guarantors[${i}][member_id]" data-required data-active-only data-exclude-from="#loanMemberPicker" data-required-message="Select the guarantor."></div></div>
        <div class="field"><label>Amount guaranteed</label><div class="input-group"><span class="addon">${esc(A.cfg.currency)}</span><input class="input" type="number" min="1" step="0.01" name="guarantors[${i}][amount]" required></div></div>
        <div class="field"><button type="button" class="btn btn-ghost btn-icon" data-remove-guarantor aria-label="Remove guarantor" title="Remove"><i class="bi bi-trash3"></i></button></div>`;
      gBox.appendChild(row);
      A.Picker.init(row.querySelector('.picker'));
      return row;
    }

    function productChanged() {
      const o = opt();
      const has = o && o.value;
      $('[data-product-desc]', applyForm).textContent = has ? o.dataset.desc : '';
      const amt = F('amount'), term = F('term_months');
      amt.min = has ? o.dataset.min : 1; amt.max = has ? o.dataset.max : '';
      term.min = has ? o.dataset.minTerm : 1; term.max = has ? o.dataset.maxTerm : '';
      $('[data-amount-hint]', applyForm).textContent = has ? `${money(o.dataset.min)} – ${money(o.dataset.max)}` : '';
      $('[data-term-hint]', applyForm).textContent = has ? `${o.dataset.minTerm} – ${o.dataset.maxTerm} months` : '';
      if (has && !term.value) term.value = Math.min(Number(o.dataset.maxTerm), Math.max(Number(o.dataset.minTerm), 12));
      const need = has ? Number(o.dataset.guarantors) : 0;
      const reqBadge = $('[data-guarantor-req]', applyForm);
      reqBadge.textContent = need ? `${need} required` : 'Optional';
      reqBadge.className = 'badge no-dot ' + (need ? 'warning' : '');
      while ($$('.g-row', gBox).length < need) addGuarantor();
      calc();
    }

    F('product_id').addEventListener('change', productChanged);
    applyForm.addEventListener('input', e => { if (['amount', 'term_months'].includes(e.target.name)) calc(); });
    applyForm.addEventListener('picker:change', e => { if (e.target === memberPicker) calc(); });
    applyForm.addEventListener('click', e => {
      if (e.target.closest('[data-add-guarantor]')) { const r = addGuarantor(); setTimeout(() => r.querySelector('input[type=text]').focus(), 40); }
      const rm = e.target.closest('[data-remove-guarantor]');
      if (rm) rm.closest('.g-row').remove();
    });
    applyForm.addEventListener('form:reset', () => {
      gBox.innerHTML = ''; gIndex = 0; strip.hidden = true;
      $('[data-product-desc]', applyForm).textContent = '';
      $('[data-amount-hint]', applyForm).textContent = '';
      $('[data-term-hint]', applyForm).textContent = '';
      $('[data-guarantor-req]', applyForm).textContent = 'Optional';
      resetQuote();
    });
    applyForm.addEventListener('form:success', e => { if (e.detail.id) setTimeout(() => A.loans.view(e.detail.id), 350); });
  }

  /* ------------------------------------------------------------------
   | Loan details modal & lifecycle actions
   * ---------------------------------------------------------------- */
  const loanModal = $('#loanViewModal');

  function summaryRows(rows) {
    return rows.filter(Boolean).map(([k, v, cls]) => `<div class="summary-row ${cls || ''}"><span>${esc(k)}</span><strong>${v}</strong></div>`).join('');
  }
  function loanSummary(l) {
    return summaryRows([
      ['Loan', `<span class="mono">${esc(l.loan_no)}</span>`],
      ['Member', esc(l.member_name || [l.first_name, l.last_name].join(' '))],
      ['Product', esc(l.product_name)],
      ['Principal', money(l.principal)],
      ['Term', `${l.term_months} months`],
      ['Est. installment', money(l.installment_amount)],
      Number(l.processing_fee) ? ['Processing fee', money(l.processing_fee)] : null,
      ['Net disbursement', money(l.principal - l.processing_fee), 'total'],
    ]);
  }

  async function viewLoan(id) {
    if (!loanModal) return;
    loanModal.dataset.loanId = id;
    const body = $('[data-loan-body]', loanModal);
    if (!loanModal.classList.contains('open')) {
      body.innerHTML = `<div class="grid-kpi">${'<div class="stat-card"><span class="skeleton" style="width:50%"></span><span class="skeleton" style="height:22px;width:80%"></span></div>'.repeat(4)}</div>`;
      $('[data-loan-actions]', loanModal).innerHTML = '';
      $('[data-loan-title]', loanModal).textContent = 'Loading loan…';
      $('[data-loan-sub]', loanModal).textContent = '';
      A.Modal.open(loanModal);
    }
    try {
      renderLoan((await A.get('loans/get', { id })).data);
    } catch (e) {
      body.innerHTML = `<div class="empty"><div class="empty-icon"><i class="bi bi-exclamation-octagon"></i></div><h4>${esc(e.message)}</h4></div>`;
    }
  }

  function renderLoan(d) {
    const l = d.loan;
    loanModal._loan = Object.assign({}, l, { due_now: d.due_now });
    const active = $('[data-tabs="loanview"] .tab.active', loanModal)?.dataset.tab || 'lv-overview';
    $('[data-loan-title]', loanModal).innerHTML = `${esc(l.loan_no)} &nbsp;${badge(l.status)}`;
    $('[data-loan-sub]', loanModal).innerHTML = `${esc(l.product_name)} · <a href="index.php?page=member&id=${l.member_id}">${esc(l.member_name)}</a> (${esc(l.member_no)})`;

    const arrears = Number(d.arrears) > 0
      ? `<div class="info-strip danger"><i class="bi bi-exclamation-octagon"></i><span><strong>${money(d.arrears)}</strong> in arrears · ${d.days_overdue} day(s) past due${Number(l.penalty_accrued) ? ` · Penalties charged ${money(l.penalty_accrued)}` : ''}</span></div>` : '';

    const stat = (lbl, val, icon, tone) => `<div class="stat-card" data-tone="${tone}"><div class="stat-top"><span class="stat-label">${lbl}</span><span class="stat-icon"><i class="bi bi-${icon}"></i></span></div><div class="stat-value" style="font-size:20px">${val}</div></div>`;
    const schedule = d.schedule.length ? d.schedule.map(s => `<tr class="${s.status === 'overdue' ? 'text-red' : ''}">
        <td>${s.installment_no}</td><td class="nowrap">${date(s.due_date)}</td>
        <td class="num">${money(s.principal_due, false)}</td><td class="num">${money(s.interest_due, false)}</td>
        <td class="num">${Number(s.penalty_due) ? money(s.penalty_due, false) : '—'}</td>
        <td class="num strong">${money(s.total_due, false)}</td><td class="num">${money(s.total_paid, false)}</td><td>${badge(s.status)}</td></tr>`).join('')
      : `<tr><td colspan="8"><div class="empty"><div class="empty-icon"><i class="bi bi-calendar2-week"></i></div><h4>No schedule yet</h4><p>The schedule is generated when the loan is disbursed.</p></div></td></tr>`;
    const repayments = d.repayments.length ? d.repayments.map(r => `<tr style="${r.status === 'reversed' ? 'text-decoration:line-through;opacity:.55' : ''}"><td class="nowrap">${dateTime(r.created_at)}</td><td class="mono">${esc(r.txn_ref || '')}</td>
        <td class="num strong">${money(r.amount, false)}</td><td class="num">${money(r.principal_paid, false)}</td><td class="num">${money(r.interest_paid, false)}</td>
        <td class="num">${money(r.penalty_paid, false)}</td><td>${esc(A.METHODS[r.payment_method] || r.payment_method)}${r.payment_ref ? `<div class="text-xs muted">${esc(r.payment_ref)}</div>` : ''}</td><td>${esc(r.received_by || '—')}</td>
        <td class="num">${r.status === 'reversed' ? badge('reversed') : d.reversible_repayment && +r.transaction_id === +d.reversible_repayment ? `<button type="button" class="act danger" data-reverse-repayment="${r.transaction_id}" title="Reverse this repayment"><i class="bi bi-arrow-counterclockwise"></i></button>` : ''}</td></tr>`).join('')
      : `<tr><td colspan="9"><div class="empty"><div class="empty-icon"><i class="bi bi-cash-coin"></i></div><h4>No repayments yet</h4></div></td></tr>`;
    const guarantors = d.guarantors.length ? `<div class="list">${d.guarantors.map(g => `<a class="list-item" href="index.php?page=member&id=${g.member_id}">${avatar(g.first_name + ' ' + g.last_name, 'sm')}<div class="meta"><strong>${esc(g.first_name + ' ' + g.last_name)}</strong><span>${esc(g.member_no)} · ${esc(g.phone)}</span></div><div class="end"><strong>${money(g.amount)}</strong>${badge(g.status)}</div></a>`).join('')}</div>`
      : `<div class="empty"><div class="empty-icon"><i class="bi bi-people"></i></div><h4>No guarantors</h4><p>This loan is secured by the member's own savings.</p></div>`;
    const kv = (k, v) => `<div><span>${esc(k)}</span><strong>${v || '—'}</strong></div>`;

    $('[data-loan-body]', loanModal).innerHTML = `
      ${arrears}
      <div class="grid-kpi" style="grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px">
        ${stat('Principal', money(l.principal), 'cash', 'navy')}
        ${stat('Total payable', money(Number(l.total_payable) + Number(l.penalty_accrued)), 'calculator', 'blue')}
        ${stat('Repaid', money(l.amount_paid), 'check2-circle', 'green')}
        ${stat('Outstanding', money(l.balance), 'hourglass-split', Number(d.arrears) > 0 ? 'rose' : 'amber')}
      </div>
      <div class="mt-2 mb-2"><div class="flex justify-between text-sm mb-1"><span class="muted">Repayment progress</span><strong>${d.progress}%</strong></div><div class="progress"><span style="width:${d.progress}%"></span></div></div>
      <div class="tabs mb-2" data-tabs="loanview">
        <button type="button" class="tab ${active === 'lv-overview' ? 'active' : ''}" data-tab="lv-overview"><i class="bi bi-info-circle"></i>Overview</button>
        <button type="button" class="tab ${active === 'lv-schedule' ? 'active' : ''}" data-tab="lv-schedule"><i class="bi bi-calendar3"></i>Schedule <span class="count">${d.schedule.length}</span></button>
        <button type="button" class="tab ${active === 'lv-repayments' ? 'active' : ''}" data-tab="lv-repayments"><i class="bi bi-receipt"></i>Repayments <span class="count">${d.repayments.length}</span></button>
        <button type="button" class="tab ${active === 'lv-guarantors' ? 'active' : ''}" data-tab="lv-guarantors"><i class="bi bi-people"></i>Guarantors <span class="count">${d.guarantors.length}</span></button>
      </div>
      <div class="tab-panel ${active === 'lv-overview' ? 'active' : ''}" id="lv-overview" data-group="loanview"><div class="kv">
        ${kv('Interest', `${Number(l.interest_rate)}% ${l.rate_period === 'monthly' ? 'per month' : 'p.a.'} · ${label(l.interest_method)}`)}
        ${kv('Term', `${l.term_months} months`)}
        ${kv('Installment', money(l.installment_amount))}
        ${kv('Total interest', money(l.total_interest))}
        ${kv('Processing fee', money(l.processing_fee))}
        ${kv('Due now', l.status === 'active' ? `<span class="${Number(d.arrears) > 0 ? 'text-red' : ''}">${money(d.due_now)}</span>` : '—')}
        ${kv('Applied', `${date(l.applied_at)}${l.created_by_name ? ' · ' + esc(l.created_by_name) : ''}`)}
        ${kv('Approved', l.approved_at ? `${date(l.approved_at)} · ${esc(l.approved_by_name || '')}` : '')}
        ${kv('Disbursed', l.disbursed_at ? `${date(l.disbursed_at)} · ${esc(A.METHODS[l.disbursement_method] || l.disbursement_method || '')}` : '')}
        ${kv('First due', date(l.first_due_date))}
        ${kv('Maturity', date(l.maturity_date))}
        ${kv('Branch', esc(l.branch_name))}
        ${kv('Purpose', esc(l.purpose))}
        ${l.approval_notes ? kv('Notes', esc(l.approval_notes)) : ''}
        ${l.rejection_reason ? kv('Rejection reason', `<span class="text-red">${esc(l.rejection_reason)}</span>`) : ''}
      </div></div>
      <div class="tab-panel ${active === 'lv-schedule' ? 'active' : ''}" id="lv-schedule" data-group="loanview"><div class="table-wrap" style="max-height:360px;overflow-y:auto"><table class="table compact"><thead><tr><th>#</th><th>Due date</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Penalty</th><th class="num">Total</th><th class="num">Paid</th><th>Status</th></tr></thead><tbody>${schedule}</tbody></table></div></div>
      <div class="tab-panel ${active === 'lv-repayments' ? 'active' : ''}" id="lv-repayments" data-group="loanview"><div class="table-wrap" style="max-height:360px;overflow-y:auto"><table class="table compact"><thead><tr><th>Date</th><th>Ref</th><th class="num">Amount</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Penalty</th><th>Method</th><th>Received by</th><th></th></tr></thead><tbody>${repayments}</tbody></table></div></div>
      <div class="tab-panel ${active === 'lv-guarantors' ? 'active' : ''}" id="lv-guarantors" data-group="loanview">${guarantors}</div>`;

    const c = d.can, btns = [];
    btns.push(`<a class="btn btn-ghost left" href="index.php?page=member&id=${l.member_id}"><i class="bi bi-person"></i><span>Member profile</span></a>`);
    if (['pending', 'approved'].includes(l.status) && c.approve) btns.push(`<button type="button" class="btn btn-ghost text-red" data-loan-act="reject"><i class="bi bi-x-octagon"></i><span>Reject</span></button>`);
    if (l.status === 'pending' && c.approve) btns.push(`<button type="button" class="btn btn-primary" data-loan-act="approve"><i class="bi bi-patch-check"></i><span>Approve</span></button>`);
    if (l.status === 'approved' && c.disburse) btns.push(`<button type="button" class="btn btn-primary" data-loan-act="disburse"><i class="bi bi-send-check"></i><span>Disburse</span></button>`);
    if (l.status === 'active' && c.writeoff) btns.push(`<button type="button" class="btn btn-ghost text-red" data-loan-act="writeoff"><i class="bi bi-journal-x"></i><span>Write off</span></button>`);
    if (l.status === 'active' && c.repay) btns.push(`<button type="button" class="btn btn-primary" data-loan-act="repay"><i class="bi bi-cash-coin"></i><span>Record repayment</span></button>`);
    $('[data-loan-actions]', loanModal).innerHTML = btns.join('');
  }

  if (loanModal) {
    loanModal.addEventListener('click', e => {
      const b = e.target.closest('[data-loan-act]');
      if (b && loanModal._loan) A.loans[b.dataset.loanAct](loanModal._loan);
      const rv = e.target.closest('[data-reverse-repayment]');
      if (rv) reverseRepayment(rv.dataset.reverseRepayment);
    });
    document.addEventListener('app:saved', () => {
      if (loanModal.classList.contains('open') && loanModal.dataset.loanId) viewLoan(loanModal.dataset.loanId);
    });
  }

  async function reverseRepayment(txnId) {
    const reason = await A.confirm({
      title: 'Reverse this repayment?',
      message: 'The installments it paid are reopened, the loan balance is restored and any money taken from savings is returned.',
      confirmText: 'Reverse repayment', input: { label: 'Reason', placeholder: 'e.g. Posted to the wrong loan' },
    });
    if (!reason) return;
    try {
      A.toast((await A.post('loans/reverse_repayment', { transaction_id: txnId, reason })).message);
      document.dispatchEvent(new CustomEvent('app:saved', { detail: { route: 'loans/reverse_repayment' } }));
    } catch (e) { A.toast(e.message, 'error'); }
  }

  function openLoanForm(modalId, l) {
    const m = A.openForm(modalId, { loan_id: l.id });
    if (m) { const box = $('[data-loan-summary]', m); if (box) box.innerHTML = loanSummary(l); }
  }

  /* Repayment modal: either pick member → loan, or opened for one loan. */
  const repayForm = $('#repayModal form');
  if (repayForm) {
    const box = $('[data-repay-summary]', repayForm);
    const memberBlock = $('[data-repay-member]', repayForm);
    const amount = repayForm.elements.namedItem('amount');
    const showLoan = l => {
      if (!l) { box.hidden = true; $('[data-repay-hint]', repayForm).textContent = ''; return; }
      box.hidden = false;
      box.innerHTML = summaryRows([
        ['Loan', `<span class="mono">${esc(l.loan_no)}</span> · ${esc(l.product_name)}`],
        ['Outstanding balance', money(l.balance)],
        ['Regular installment', money(l.installment_amount)],
        ['Due now (incl. arrears)', `<span class="${Number(l.due_now) > Number(l.installment_amount) ? 'text-red' : ''}">${money(l.due_now)}</span>`, 'total'],
      ]);
      const suggested = Math.min(Number(l.balance), Number(l.due_now) > 0 ? Number(l.due_now) : Number(l.installment_amount));
      amount.value = suggested > 0 ? suggested.toFixed(2) : '';
      amount.max = l.balance;
      $('[data-repay-hint]', repayForm).innerHTML = `Maximum ${money(l.balance)} — pays off the loan in full.`;
    };
    repayForm.addEventListener('change', e => {
      if (e.target.name !== 'loan_id') return;
      showLoan((repayForm._accounts?.loans || []).find(x => String(x.id) === e.target.value) || null);
    });
    repayForm.addEventListener('form:reset', () => { memberBlock.hidden = false; showLoan(null); });
  }

  /* ------------------------------------------------------------------
   | Public helpers used by pages
   * ---------------------------------------------------------------- */
  A.loans = {
    view: viewLoan,
    approve: l => openLoanForm('loanApproveModal', l),
    reject: l => openLoanForm('loanRejectModal', l),
    disburse: l => openLoanForm('loanDisburseModal', l),
    repay: l => {
      if (!repayForm) return;
      A.openForm('repayModal');
      $('[data-repay-member]', repayForm).hidden = true;
      const sel = repayForm.elements.namedItem('loan_id');
      sel.dataset.pending = l.id;
      loadMemberAccounts(repayForm, l.member_id);
    },
    writeoff: async l => {
      const reason = await A.confirm({ title: `Write off ${l.loan_no}?`, message: `Outstanding balance of ${money(l.balance)} will be removed from the active portfolio.`, confirmText: 'Write off loan', input: { label: 'Reason', placeholder: 'e.g. Member deceased, recovery exhausted' } });
      if (!reason) return;
      try {
        const r = await A.post('loans/write_off', { loan_id: l.id, reason });
        A.toast(r.message);
        document.dispatchEvent(new CustomEvent('app:saved', { detail: { route: 'loans/write_off' } }));
      } catch (e) { A.toast(e.message, 'error'); }
    },
    apply: member => A.openForm('loanApplyModal', member ? { member_id: member } : null),
  };

  /* ------------------------------------------------------------------
   | Two-step login (TOTP) setup
   * ---------------------------------------------------------------- */
  const sec = $('#securityModal');
  function loadQrLib() {
    if (window.QRCode) return Promise.resolve();
    return new Promise((ok, fail) => {
      const s = document.createElement('script');
      s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
      s.onload = ok; s.onerror = fail;
      document.head.appendChild(s);
    });
  }
  async function securityView() {
    const body = $('[data-sec-body]', sec), act = $('[data-sec-actions]', sec);
    body.innerHTML = '<span class="skeleton"></span>';
    act.innerHTML = '';
    const st = (await A.get('security/status')).data;
    if (st.enabled) {
      body.innerHTML = `<div class="info-strip"><i class="bi bi-shield-check"></i><span><strong>Two-step login is on.</strong> ${st.recovery} unused recovery code(s) left.</span></div>
        <div class="field"><label>Confirm your password to turn it off</label><input class="input" type="password" data-sec-password autocomplete="current-password"></div>`;
      act.innerHTML = '<button class="btn btn-ghost" data-close>Close</button><button class="btn btn-danger" data-sec="disable"><span>Turn off</span></button>';
    } else {
      body.innerHTML = `<p class="mb-2">Protect your account even if your password leaks. You need an authenticator app such as Google Authenticator, Microsoft Authenticator or Authy.</p>
        <div class="info-strip warning"><i class="bi bi-shield-exclamation"></i><span>Two-step login is <strong>off</strong> for your account.</span></div>`;
      act.innerHTML = '<button class="btn btn-ghost" data-close>Not now</button><button class="btn btn-primary" data-sec="setup"><i class="bi bi-qr-code"></i><span>Set up</span></button>';
    }
  }
  async function securitySetup() {
    const body = $('[data-sec-body]', sec), act = $('[data-sec-actions]', sec);
    const d = (await A.post('security/setup')).data;
    body.innerHTML = `<p class="text-sm mb-2"><strong>1.</strong> Scan this code with your authenticator app.</p><div class="qr-box" data-qr></div>
      <p class="text-xs muted text-right mb-2" style="text-align:center">Can't scan? Enter this key: <code style="word-break:break-all">${esc(d.secret.replace(/(.{4})/g, '$1 ').trim())}</code></p>
      <p class="text-sm mb-1"><strong>2.</strong> Enter the 6-digit code the app shows.</p>
      <div class="field"><input class="input input-amount" data-sec-code inputmode="numeric" maxlength="7" placeholder="123456" autocomplete="one-time-code"><div class="field-error" data-sec-err></div></div>`;
    act.innerHTML = '<button class="btn btn-ghost" data-close>Cancel</button><button class="btn btn-primary" data-sec="enable"><i class="bi bi-check2-circle"></i><span>Verify &amp; turn on</span></button>';
    try { await loadQrLib(); new window.QRCode($('[data-qr]', body), { text: d.uri, width: 180, height: 180, correctLevel: window.QRCode.CorrectLevel.M }); }
    catch (e) { $('[data-qr]', body).outerHTML = '<p class="muted text-sm">QR code unavailable offline — use the key below.</p>'; }
    setTimeout(() => $('[data-sec-code]', body)?.focus(), 80);
  }
  if (sec) {
    document.addEventListener('click', e => {
      if (!e.target.closest('[data-open-security]')) return;
      A.Modal.open(sec);
      securityView().catch(err => A.toast(err.message, 'error'));
    });
    sec.addEventListener('click', async e => {
      const b = e.target.closest('[data-sec]');
      if (!b) return;
      b.disabled = true;
      try {
        if (b.dataset.sec === 'setup') await securitySetup();
        if (b.dataset.sec === 'enable') {
          const r = await A.post('security/enable', { code: $('[data-sec-code]', sec).value });
          $('[data-sec-body]', sec).innerHTML = `<div class="info-strip"><i class="bi bi-shield-check"></i><span>${esc(r.message)} Save these recovery codes somewhere safe. Each works once if you lose your phone. They will not be shown again.</span></div>
            <div class="recovery-codes">${r.data.recovery_codes.map(c => `<span>${esc(c)}</span>`).join('')}</div>`;
          $('[data-sec-actions]', sec).innerHTML = '<button class="btn btn-ghost" data-copy-codes><i class="bi bi-clipboard"></i><span>Copy</span></button><button class="btn btn-primary" data-close>I have saved them</button>';
          sec.dataset.codes = r.data.recovery_codes.join('\n');
        }
        if (b.dataset.sec === 'disable') {
          A.toast((await A.post('security/disable', { password: $('[data-sec-password]', sec).value })).message);
          securityView();
        }
      } catch (err) {
        const slot = $('[data-sec-err]', sec);
        if (slot && err.errors) slot.textContent = Object.values(err.errors)[0];
        else A.toast(err.message, 'error');
      } finally { b.disabled = false; }
    });
    sec.addEventListener('click', e => {
      if (e.target.closest('[data-copy-codes]')) navigator.clipboard?.writeText(sec.dataset.codes || '').then(() => A.toast('Recovery codes copied.'));
    });
  }

  A.members = {
    async edit(id) {
      try { A.openForm('memberModal', (await A.get('members/get', { id })).data); }
      catch (e) { A.toast(e.message, 'error'); }
    },
    async setStatus(m, status) {
      const ok = await A.confirm({
        title: `Mark ${m.name} as ${label(status)}?`,
        message: status === 'exited' ? 'Exited members can no longer transact. Clear all loans first.' : 'You can change the status again at any time.',
        confirmText: `Mark ${label(status)}`, tone: ['suspended', 'exited'].includes(status) ? 'danger' : 'warning', icon: 'person-gear',
      });
      if (!ok) return;
      try {
        const r = await A.post('members/set_status', { id: m.id, status });
        A.toast(r.message);
        document.dispatchEvent(new CustomEvent('app:saved', { detail: { route: 'members/set_status' } }));
      } catch (e) { A.toast(e.message, 'error'); }
    },
  };
})();
