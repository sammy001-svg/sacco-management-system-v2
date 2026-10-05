/* =====================================================================
   SaccoPro — core UI framework
   API client · modals · forms · wizard · member picker · data tables ·
   toasts · confirm dialogs · theme · charts helpers
   ===================================================================== */
(function () {
  'use strict';

  const CFG = window.APP || {};
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  /* ------------------------------------------------------------------
   | Formatting
   * ---------------------------------------------------------------- */
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const f2 = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const f0 = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 });
  const cur = () => CFG.currency || 'KES';
  const money = (n, symbol = true) => (symbol ? cur() + ' ' : '') + f2.format(Number(n) || 0);
  const num = n => f0.format(Number(n) || 0);
  const compact = n => {
    n = Number(n) || 0;
    const a = Math.abs(n);
    if (a >= 1e9) return (n / 1e9).toFixed(2) + 'B';
    if (a >= 1e6) return (n / 1e6).toFixed(2) + 'M';
    if (a >= 1e4) return (n / 1e3).toFixed(1) + 'K';
    return f0.format(n);
  };
  const parseDate = s => (s ? new Date(String(s).replace(' ', 'T')) : null);
  const date = s => (s ? parseDate(s).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) : '—');
  const dateTime = s => (s ? parseDate(s).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—');
  const timeAgo = s => {
    if (!s) return '—';
    const sec = Math.round((Date.now() - parseDate(s).getTime()) / 1000);
    if (sec < 60) return 'just now';
    const units = [[31536000, 'year'], [2592000, 'month'], [86400, 'day'], [3600, 'hour'], [60, 'minute']];
    for (const [u, name] of units) if (sec >= u) { const v = Math.floor(sec / u); return `${v} ${name}${v > 1 ? 's' : ''} ago`; }
    return 'just now';
  };
  const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  const label = s => String(s || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  const initials = name => {
    const p = String(name || '').trim().split(/\s+/);
    return ((p[0] || '')[0] || '').concat(p.length > 1 ? (p[p.length - 1][0] || '') : '').toUpperCase();
  };
  const hue = s => { let h = 0; for (const ch of String(s || '')) h = (h + ch.charCodeAt(0)) % 6; return h || ''; };
  const avatar = (name, cls = '') => `<span class="avatar ${cls}" data-hue="${hue(name)}">${esc(initials(name))}</span>`;
  const debounce = (fn, ms = 300) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

  const TONES = {
    active: 'success', paid: 'success', posted: 'success', in: 'success',
    approved: 'info', closed: 'info', released: '',
    pending: 'warning', partial: 'warning', dormant: 'warning', frozen: 'warning', approved_wait: 'warning',
    rejected: 'danger', overdue: 'danger', suspended: 'danger', reversed: 'danger', written_off: 'danger', out: 'danger',
    exited: '', inactive: '', none: '',
  };
  const badge = (status, text) => `<span class="badge ${TONES[status] ?? ''}">${esc(text || label(status))}</span>`;

  const TXN = {
    deposit: ['Deposit', 'arrow-down-left', 'tone-green'],
    withdrawal: ['Withdrawal', 'arrow-up-right', 'tone-red'],
    share_purchase: ['Share Purchase', 'pie-chart', 'tone-blue'],
    share_transfer_in: ['Shares In', 'box-arrow-in-down', 'tone-blue'],
    share_transfer_out: ['Shares Out', 'box-arrow-up', 'tone-amber'],
    loan_disbursement: ['Loan Disbursed', 'send', 'tone-amber'],
    loan_repayment: ['Loan Repayment', 'cash-coin', 'tone-green'],
    processing_fee: ['Processing Fee', 'receipt', 'tone-blue'],
    registration_fee: ['Registration Fee', 'person-badge', 'tone-blue'],
    withdrawal_fee: ['Withdrawal Fee', 'receipt-cutoff', 'tone-blue'],
    penalty: ['Penalty', 'exclamation-diamond', 'tone-red'],
    expense: ['Expense', 'wallet2', 'tone-red'],
    reversal: ['Reversal', 'arrow-counterclockwise', 'tone-amber'],
  };
  const txnInfo = t => TXN[t] || [label(t), 'circle', 'tone-blue'];
  const METHODS = { cash: 'Cash', mobile_money: 'Mobile Money', bank: 'Bank', cheque: 'Cheque', internal: 'Internal', savings: 'To Savings' };

  /* ------------------------------------------------------------------
   | API
   * ---------------------------------------------------------------- */
  class ApiError extends Error {
    constructor(message, status, errors) { super(message); this.status = status; this.errors = errors || null; }
  }

  function toFormData(obj, fd = new FormData(), prefix = '') {
    Object.entries(obj || {}).forEach(([k, v]) => {
      const key = prefix ? `${prefix}[${k}]` : k;
      if (v === null || v === undefined) return;
      if (typeof v === 'object' && !(v instanceof File)) toFormData(v, fd, key);
      else fd.append(key, v);
    });
    return fd;
  }

  async function api(route, data = null, method = 'POST') {
    let url = 'api.php?r=' + route;
    const init = { method, credentials: 'same-origin', headers: { 'X-CSRF-Token': CFG.csrf, Accept: 'application/json' } };
    if (method === 'GET') {
      if (data) {
        const qs = new URLSearchParams();
        Object.entries(data).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) qs.append(k, v); });
        const s = qs.toString();
        if (s) url += '&' + s;
      }
    } else {
      init.body = data instanceof FormData ? data : toFormData(data || {});
    }

    let res, json;
    try { res = await fetch(url, init); } catch (e) { throw new ApiError('Network error — please check your connection.', 0); }
    try { json = await res.json(); } catch (e) { throw new ApiError(`Unexpected server response (${res.status}).`, res.status); }
    if (res.status === 401) {
      toast('Your session has ended. Redirecting to sign in…', 'warning');
      setTimeout(() => (location.href = 'login.php'), 1400);
    }
    if (!json.ok) throw new ApiError(json.message || 'Request failed.', res.status, json.errors);
    return json;
  }
  const get = (route, params) => api(route, params, 'GET');
  const post = (route, data) => api(route, data, 'POST');

  /* ------------------------------------------------------------------
   | Toasts
   * ---------------------------------------------------------------- */
  function toast(message, type = 'success', title = null, duration = 4800) {
    let stack = $('.toast-stack');
    if (!stack) { stack = document.createElement('div'); stack.className = 'toast-stack'; document.body.appendChild(stack); }
    const icons = { success: 'check2-circle', error: 'x-octagon', warning: 'exclamation-triangle', info: 'info-circle' };
    const titles = { success: 'Success', error: 'Something went wrong', warning: 'Heads up', info: 'Notice' };
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.style.setProperty('--dur', duration + 'ms');
    el.innerHTML = `<div class="t-ico"><i class="bi bi-${icons[type] || icons.info}"></i></div>
      <div class="t-body"><strong>${esc(title || titles[type])}</strong>${esc(message)}</div>
      <button class="t-close" type="button" aria-label="Dismiss"><i class="bi bi-x-lg"></i></button>`;
    const remove = () => { el.classList.add('leaving'); setTimeout(() => el.remove(), 250); };
    el.querySelector('.t-close').addEventListener('click', remove);
    stack.appendChild(el);
    setTimeout(remove, duration);
  }

  /* ------------------------------------------------------------------
   | Modals
   * ---------------------------------------------------------------- */
  const Modal = {
    stack: [],
    open(ref, detail = {}) {
      const el = typeof ref === 'string' ? document.getElementById(ref) : ref;
      if (!el) return null;
      if (!el.classList.contains('open')) {
        el.classList.remove('closing');
        el.classList.add('open');
        el.setAttribute('aria-hidden', 'false');
        this.stack = this.stack.filter(m => m !== el);
        this.stack.push(el);
        el.style.zIndex = 1000 + this.stack.length * 10;
        document.body.classList.add('modal-open');
      }
      setTimeout(() => {
        const f = el.querySelector('[autofocus]') || el.querySelector('.wizard-pane.active input:not([type=hidden]):not([readonly]), .modal-body input:not([type=hidden]):not([readonly]):not([type=checkbox]):not([type=radio]), .modal-body select');
        if (f && f.offsetParent !== null) f.focus({ preventScroll: true });
      }, 90);
      el.dispatchEvent(new CustomEvent('modal:open', { detail }));
      return el;
    },
    close(ref) {
      const el = ref ? (typeof ref === 'string' ? document.getElementById(ref) : ref) : this.stack[this.stack.length - 1];
      if (!el || !el.classList.contains('open')) return;
      el.classList.add('closing');
      this.stack = this.stack.filter(m => m !== el);
      if (!this.stack.length) document.body.classList.remove('modal-open');
      setTimeout(() => {
        el.classList.remove('open', 'closing');
        el.setAttribute('aria-hidden', 'true');
        el.dispatchEvent(new Event('modal:closed'));
        if (el.hasAttribute('data-ephemeral')) el.remove();
      }, 170);
    },
    isOpen(id) { const el = document.getElementById(id); return !!el && el.classList.contains('open'); },
  };

  let downOnBackdrop = false;
  document.addEventListener('mousedown', e => { downOnBackdrop = e.target.classList && e.target.classList.contains('modal'); });
  document.addEventListener('click', e => {
    const c = e.target.closest('[data-close]');
    if (c) { Modal.close(c.closest('.modal')); return; }
    if (downOnBackdrop && e.target.classList.contains('modal') && !e.target.hasAttribute('data-static')) Modal.close(e.target);
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && Modal.stack.length) {
      const top = Modal.stack[Modal.stack.length - 1];
      if (!top.hasAttribute('data-static')) Modal.close(top);
    }
  });

  /** Promise-based confirmation, optionally collecting a reason. */
  function confirmDialog(o = {}) {
    const opt = Object.assign({ title: 'Are you sure?', message: '', confirmText: 'Confirm', tone: 'danger', icon: 'exclamation-triangle', input: null }, o);
    return new Promise(resolve => {
      const m = document.createElement('div');
      m.className = 'modal modal-sm';
      m.setAttribute('data-ephemeral', '');
      m.setAttribute('role', 'dialog');
      m.setAttribute('aria-modal', 'true');
      m.innerHTML = `<form class="modal-dialog" novalidate>
        <div class="modal-head"><div class="modal-icon ${opt.tone}"><i class="bi bi-${opt.icon}"></i></div>
          <div class="modal-title"><h3>${esc(opt.title)}</h3>${opt.message ? `<p>${esc(opt.message)}</p>` : ''}</div>
          <button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
        ${opt.input ? `<div class="modal-body"><div class="field"><label>${esc(opt.input.label || 'Reason')} <span class="req">*</span></label>
          <textarea class="textarea" name="value" rows="3" required minlength="3" placeholder="${esc(opt.input.placeholder || '')}"></textarea></div></div>` : ''}
        <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button>
          <button type="submit" class="btn ${opt.tone === 'danger' ? 'btn-danger' : opt.tone === 'warning' ? 'btn-warning' : 'btn-primary'}"><span>${esc(opt.confirmText)}</span></button></div>
      </form>`;
      document.body.appendChild(m);
      let result = false;
      m.querySelector('form').addEventListener('submit', e => {
        e.preventDefault();
        if (opt.input) {
          const t = m.querySelector('textarea');
          if (!t.checkValidity()) { t.reportValidity(); return; }
          result = t.value.trim();
        } else result = true;
        Modal.close(m);
      });
      m.addEventListener('modal:closed', () => resolve(result));
      Modal.open(m);
    });
  }

  /* ------------------------------------------------------------------
   | Forms
   * ---------------------------------------------------------------- */
  const Forms = {
    clearErrors(form) {
      $$('.is-invalid', form).forEach(el => el.classList.remove('is-invalid'));
      $$('.field-error', form).forEach(el => (el.textContent = ''));
    },
    errorSlot(form, name) {
      let slot = form.querySelector(`[data-error-for="${CSS.escape(name)}"]`);
      if (slot) return slot;
      const input = form.querySelector(`[name="${CSS.escape(name)}"]`) || form.querySelector(`.picker[data-name="${CSS.escape(name)}"]`);
      const field = input && input.closest('.field');
      if (!field) return null;
      slot = field.querySelector('.field-error');
      if (!slot) { slot = document.createElement('div'); slot.className = 'field-error'; field.appendChild(slot); }
      return slot;
    },
    errors(form, errors) {
      let first = null;
      Object.entries(errors || {}).forEach(([name, msg]) => {
        const slot = this.errorSlot(form, name);
        if (slot) slot.textContent = msg;
        const input = form.querySelector(`[name="${CSS.escape(name)}"]:not([type=hidden])`) || form.querySelector(`.picker[data-name="${CSS.escape(name)}"]`);
        if (input) { input.classList.add('is-invalid'); first = first || input; }
      });
      if (first) {
        const pane = first.closest('.wizard-pane');
        if (pane) Wizard.go(form, $$('.wizard-pane', form).indexOf(pane));
        const focusable = first.matches('.picker') ? first.querySelector('input[type=text]') : first;
        setTimeout(() => focusable && focusable.focus && focusable.focus(), 50);
      }
    },
    reset(form) {
      form.reset();
      $$('input[type=hidden][name]', form).forEach(i => { if (i.name !== '_token' && !i.hasAttribute('data-keep')) i.value = ''; });
      $$('[data-default]', form).forEach(i => (i.value = i.dataset.default === 'today' ? today() : i.dataset.default));
      this.clearErrors(form);
      $$('.picker', form).forEach(p => Picker.clear(p, true));
      $$('[data-accounts]', form).forEach(s => { s.innerHTML = '<option value="">Select a member first</option>'; delete s.dataset.pending; });
      form.dataset.mode = 'create';
      this.applyMode(form);
      if (form.classList.contains('wizard')) Wizard.go(form, 0);
      form.dispatchEvent(new CustomEvent('form:reset'));
    },
    applyMode(form) {
      $$('[data-mode-show]', form).forEach(el => (el.hidden = el.dataset.modeShow !== form.dataset.mode));
    },
    fill(form, data) {
      Object.entries(data || {}).forEach(([k, v]) => {
        const picker = form.querySelector(`.picker[data-name="${CSS.escape(k)}"]`);
        if (picker) { if (v && typeof v === 'object') Picker.set(picker, v); return; }
        $$(`[name="${CSS.escape(k)}"]`, form).forEach(f => {
          if (f.type === 'checkbox') f.checked = v === true || v === 'on' || Number(v) === 1;
          else if (f.type === 'radio') f.checked = String(f.value) === String(v);
          else if (f.tagName === 'SELECT' && v !== null && v !== '' && !$$('option', f).some(o => o.value === String(v))) f.dataset.pending = v;
          else f.value = v ?? '';
          f.dispatchEvent(new Event('change', { bubbles: true }));
        });
      });
    },
  };

  /** Open a modal form, resetting it and optionally pre-filling it. */
  function openForm(id, data = null, opts = {}) {
    const modal = document.getElementById(id);
    if (!modal) return null;
    const form = modal.querySelector('form');
    if (form) {
      Forms.reset(form);
      if (data) {
        form.dataset.mode = data.id ? 'edit' : 'create';
        Forms.applyMode(form);
        Forms.fill(form, data);
      }
    }
    const t = modal.querySelector('[data-title]');
    if (t) t.textContent = opts.title || (data && data.id ? t.dataset.edit || t.dataset.create : t.dataset.create) || t.textContent;
    modal.dispatchEvent(new CustomEvent('form:open', { detail: { data, opts } }));
    return Modal.open(modal, { data, opts });
  }

  function firstInvalid(form) {
    for (const el of $$('input, select, textarea', form)) {
      if (el.disabled || el.closest('[hidden]')) continue;
      if (!el.checkValidity()) return el;
    }
    for (const p of $$('.picker[data-required]', form)) {
      if (p.closest('[hidden]')) continue;
      if (!Picker.value(p)) return p;
    }
    return null;
  }

  function reportInvalid(form, el) {
    const pane = el.closest('.wizard-pane');
    if (pane && !pane.classList.contains('active')) Wizard.go(form, $$('.wizard-pane', form).indexOf(pane));
    if (el.classList.contains('picker')) {
      el.classList.add('is-invalid');
      const slot = Forms.errorSlot(form, el.dataset.name);
      if (slot) slot.textContent = el.dataset.requiredMessage || 'Please select a member.';
      el.querySelector('input[type=text]')?.focus();
    } else setTimeout(() => el.reportValidity(), 20);
  }

  document.addEventListener('submit', async e => {
    const form = e.target.closest('form[data-api]');
    if (!form) return;
    e.preventDefault();

    if (form.classList.contains('wizard') && !Wizard.isLast(form)) { Wizard.next(form); return; }

    Forms.clearErrors(form);
    const bad = firstInvalid(form);
    if (bad) { reportInvalid(form, bad); return; }

    const btn = form.querySelector('[type=submit]');
    btn && (btn.classList.add('loading'), (btn.disabled = true));
    try {
      const fd = new FormData(form);
      form.dispatchEvent(new CustomEvent('form:before', { detail: fd }));
      const res = await post(form.dataset.api, fd);
      const modal = form.closest('.modal');
      if (modal && !form.hasAttribute('data-keep-open')) Modal.close(modal);
      if (res.receipt) showReceipt(res.receipt, res.message);
      else toast(res.message || 'Saved successfully.', 'success');
      form.dispatchEvent(new CustomEvent('form:success', { detail: res, bubbles: true }));
      document.dispatchEvent(new CustomEvent('app:saved', { detail: { route: form.dataset.api, res } }));
      if (res.redirect && form.dataset.redirect === 'true') setTimeout(() => (location.href = res.redirect), 700);
    } catch (err) {
      if (err.errors) Forms.errors(form, err.errors);
      toast(err.message, 'error');
    } finally {
      btn && (btn.classList.remove('loading'), (btn.disabled = false));
    }
  });

  /* ------------------------------------------------------------------
   | Wizard (multi-step modal forms)
   * ---------------------------------------------------------------- */
  const Wizard = {
    init(form) {
      const panes = $$('.wizard-pane', form);
      const head = form.querySelector('.wizard-steps');
      if (head) head.innerHTML = panes.map((p, i) => `<div class="wizard-step" data-step="${i}"><span class="n">${i + 1}</span><span class="t">${esc(p.dataset.title || 'Step ' + (i + 1))}</span></div>`).join('');
      form.querySelector('[data-wizard-prev]')?.addEventListener('click', () => this.go(form, this.index(form) - 1));
      head?.addEventListener('click', e => {
        const s = e.target.closest('.wizard-step');
        if (s && s.classList.contains('done')) this.go(form, Number(s.dataset.step));
      });
      this.go(form, 0);
    },
    index(form) { return Number(form.dataset.step || 0); },
    isLast(form) { return this.index(form) >= $$('.wizard-pane', form).length - 1; },
    go(form, i) {
      const panes = $$('.wizard-pane', form);
      i = Math.max(0, Math.min(i, panes.length - 1));
      form.dataset.step = i;
      panes.forEach((p, k) => p.classList.toggle('active', k === i));
      $$('.wizard-step', form).forEach((s, k) => { s.classList.toggle('active', k === i); s.classList.toggle('done', k < i); });
      const prev = form.querySelector('[data-wizard-prev]');
      if (prev) prev.hidden = i === 0;
      const btn = form.querySelector('[type=submit]');
      if (btn) {
        const last = i === panes.length - 1;
        btn.querySelector('span').textContent = last ? (btn.dataset.submitLabel || 'Save') : 'Continue';
        const ic = btn.querySelector('i');
        if (ic) ic.className = 'bi ' + (last ? 'bi-check2-circle' : 'bi-arrow-right');
      }
      const body = form.querySelector('.modal-body');
      if (body) body.scrollTop = 0;
      setTimeout(() => panes[i]?.querySelector('input:not([type=hidden]), select')?.focus({ preventScroll: true }), 60);
    },
    next(form) {
      const pane = $$('.wizard-pane', form)[this.index(form)];
      const bad = firstInvalid(pane);
      if (bad) { reportInvalid(form, bad); return; }
      this.go(form, this.index(form) + 1);
    },
  };

  /* ------------------------------------------------------------------
   | Member picker (async search — never loads the whole member table)
   * ---------------------------------------------------------------- */
  const Picker = {
    init(el) {
      if (el.dataset.ready) return;
      el.dataset.ready = '1';
      const name = el.dataset.name || 'member_id';
      el.innerHTML = `<input type="hidden" name="${esc(name)}">
        <div class="picker-control"><i class="bi bi-search"></i>
          <input type="text" class="input" autocomplete="off" spellcheck="false" placeholder="${esc(el.dataset.placeholder || 'Search by name, member no, ID or phone…')}" aria-label="Search member"></div>
        <div class="picker-menu" hidden role="listbox"></div>
        <div class="picker-selected" hidden></div>`;
      const input = el.querySelector('input[type=text]');
      const menu = el.querySelector('.picker-menu');
      let items = [], focus = -1, req = 0;

      const render = () => {
        if (!items.length) { menu.innerHTML = `<div class="picker-empty"><i class="bi bi-person-x"></i> No matching members</div>`; return; }
        menu.innerHTML = items.map((m, i) => `<div class="picker-item ${i === focus ? 'focus' : ''}" data-i="${i}" role="option">
            ${avatar(m.name, 'sm')}<div class="meta"><strong>${esc(m.name)}</strong><span>${esc(m.member_no)} · ${esc(m.phone || '')}</span></div>
            <div class="amt">${money(m.savings_total)}<br>${m.status !== 'active' ? badge(m.status) : '<span class="muted">savings</span>'}</div></div>`).join('');
      };
      const search = debounce(async q => {
        const my = ++req;
        if (q.length < 1) { menu.hidden = true; return; }
        menu.hidden = false;
        menu.innerHTML = `<div class="picker-empty"><span class="skeleton" style="width:60%;margin:auto"></span></div>`;
        try {
          const params = { q };
          if (el.dataset.activeOnly !== undefined) params.active_only = 1;
          const ex = el.dataset.excludeFrom ? Picker.value($(el.dataset.excludeFrom)) : null;
          if (ex) params.exclude = ex;
          const res = await get('members/search', params);
          if (my !== req) return;
          items = res.data; focus = items.length ? 0 : -1; render();
        } catch (e) { menu.innerHTML = `<div class="picker-empty">${esc(e.message)}</div>`; }
      }, 250);

      input.addEventListener('input', () => search(input.value.trim()));
      input.addEventListener('focus', () => { if (input.value.trim() && items.length) menu.hidden = false; });
      input.addEventListener('keydown', e => {
        if (menu.hidden || !items.length) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); focus = (focus + 1) % items.length; render(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); focus = (focus - 1 + items.length) % items.length; render(); }
        else if (e.key === 'Enter') { e.preventDefault(); if (items[focus]) Picker.set(el, items[focus]); }
        else if (e.key === 'Escape') { e.stopPropagation(); menu.hidden = true; }
      });
      menu.addEventListener('mousedown', e => {
        const it = e.target.closest('.picker-item');
        if (it) { e.preventDefault(); Picker.set(el, items[Number(it.dataset.i)]); }
      });
      document.addEventListener('click', e => { if (!el.contains(e.target)) menu.hidden = true; });
      el.querySelector('.picker-selected').addEventListener('click', e => {
        if (e.target.closest('[data-picker-clear]')) { Picker.clear(el); setTimeout(() => input.focus(), 30); }
      });
    },
    set(el, m) {
      this.init(el);
      const name = m.name || [m.first_name, m.last_name].filter(Boolean).join(' ');
      el.querySelector('input[type=hidden]').value = m.id;
      el.querySelector('.picker-control').hidden = true;
      el.querySelector('.picker-menu').hidden = true;
      const sel = el.querySelector('.picker-selected');
      sel.hidden = false;
      sel.innerHTML = `${avatar(name, 'sm')}<div class="meta"><strong>${esc(name)}</strong><span>${esc(m.member_no || '')}${m.savings_total !== undefined ? ' · Savings ' + money(m.savings_total) : ''}</span></div>
        ${el.hasAttribute('data-locked') ? '' : '<button type="button" class="btn btn-ghost btn-xs" data-picker-clear><i class="bi bi-arrow-repeat"></i> Change</button>'}`;
      el.classList.remove('is-invalid');
      const form = el.closest('form');
      if (form) { const slot = form.querySelector(`[data-error-for="${CSS.escape(el.dataset.name)}"]`) || el.closest('.field')?.querySelector('.field-error'); if (slot) slot.textContent = ''; }
      el._member = Object.assign({}, m, { name });
      el.dispatchEvent(new CustomEvent('picker:change', { detail: el._member, bubbles: true }));
    },
    clear(el, silent = false) {
      this.init(el);
      el.querySelector('input[type=hidden]').value = '';
      const input = el.querySelector('input[type=text]');
      input.value = '';
      el.querySelector('.picker-control').hidden = false;
      el.querySelector('.picker-menu').hidden = true;
      el.querySelector('.picker-selected').hidden = true;
      el._member = null;
      el.classList.remove('is-invalid');
      if (!silent) el.dispatchEvent(new CustomEvent('picker:change', { detail: null, bubbles: true }));
    },
    value(el) { return el ? (el.querySelector('input[type=hidden]')?.value || '') : ''; },
    member(el) { return el ? el._member || null : null; },
  };

  /* ------------------------------------------------------------------
   | Data table (server-side pagination, sorting, filters)
   * ---------------------------------------------------------------- */
  class DataTable {
    constructor(el, opts) {
      this.el = typeof el === 'string' ? $(el) : el;
      this.o = Object.assign({ perPage: 15, params: {}, empty: 'No records found', emptyIcon: 'inbox', emptyHint: '' }, opts);
      this.state = { page: 1, per_page: this.o.perPage, sort: this.o.sort || '', dir: this.o.dir || 'desc' };
      this.filters = this.o.filters ? (typeof this.o.filters === 'string' ? $(this.o.filters) : this.o.filters) : null;
      this.rows = []; this.req = 0;
      this.build(); this.bind(); this.load();
      DataTable.all.push(this);
    }
    build() {
      const th = this.o.columns.map(c => `<th class="${c.align === 'right' ? 'num' : ''} ${c.sort ? 'sortable' : ''} ${c.hideSm ? 'hide-sm' : ''} ${c.hideMd ? 'hide-md' : ''}" ${c.sort ? `data-sort="${c.sort}"` : ''} style="${c.width ? 'width:' + c.width : ''}">${esc(c.label || '')}${c.sort ? '<i class="bi bi-arrow-down-up sort-ind"></i>' : ''}</th>`).join('');
      this.el.innerHTML = `<div class="table-wrap"><table class="table ${this.o.compact ? 'compact' : ''}"><thead><tr>${th}</tr></thead><tbody></tbody></table></div>
        <div class="table-foot"><div class="dt-info"></div>
        <div class="flex items-center gap-2 wrap"><div class="per-page"><span class="hide-sm">Rows</span><select class="select" aria-label="Rows per page">${[10, 15, 25, 50, 100].map(n => `<option ${n === this.state.per_page ? 'selected' : ''}>${n}</option>`).join('')}</select></div><div class="pager"></div></div></div>`;
      this.tbody = this.el.querySelector('tbody');
    }
    bind() {
      this.el.querySelector('thead').addEventListener('click', e => {
        const th = e.target.closest('th[data-sort]');
        if (!th) return;
        if (this.state.sort === th.dataset.sort) this.state.dir = this.state.dir === 'asc' ? 'desc' : 'asc';
        else { this.state.sort = th.dataset.sort; this.state.dir = 'desc'; }
        this.state.page = 1; this.load();
      });
      this.el.querySelector('.pager').addEventListener('click', e => {
        const b = e.target.closest('button[data-page]');
        if (b && !b.disabled) { this.state.page = Number(b.dataset.page); this.load(); this.el.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
      });
      this.el.querySelector('.per-page select').addEventListener('change', e => { this.state.per_page = Number(e.target.value); this.state.page = 1; this.load(); });
      this.tbody.addEventListener('click', e => {
        const a = e.target.closest('[data-action]');
        const tr = e.target.closest('tr[data-i]');
        if (!tr) return;
        const row = this.rows[Number(tr.dataset.i)];
        if (a) { e.preventDefault(); this.o.onAction && this.o.onAction(a.dataset.action, row, a, e); return; }
        if (this.o.onRowClick && !e.target.closest('a, button, input, select')) this.o.onRowClick(row, e);
      });
      if (this.filters) {
        const go = () => { this.state.page = 1; this.load(); };
        const slow = debounce(go, 350);
        this.filters.addEventListener('input', e => { if (e.target.matches('input[type=search], input[type=text]')) slow(); });
        this.filters.addEventListener('change', e => { if (!e.target.matches('input[type=search], input[type=text]')) go(); });
        this.filters.addEventListener('submit', e => { e.preventDefault(); go(); });
      }
    }
    params(withPaging = true) {
      const p = Object.assign({}, typeof this.o.params === 'function' ? this.o.params() : this.o.params);
      if (this.filters) new FormData(this.filters).forEach((v, k) => { if (v !== '') p[k] = v; });
      if (this.state.sort) { p.sort = this.state.sort; p.dir = this.state.dir; }
      if (withPaging) { p.page = this.state.page; p.per_page = this.state.per_page; }
      return p;
    }
    skeleton() {
      const n = Math.min(this.state.per_page, 6);
      this.tbody.innerHTML = Array.from({ length: n }, () => `<tr>${this.o.columns.map(c => `<td class="${c.hideSm ? 'hide-sm' : ''} ${c.hideMd ? 'hide-md' : ''}"><span class="skeleton" style="width:${40 + Math.random() * 50}%"></span></td>`).join('')}</tr>`).join('');
    }
    async load() {
      const my = ++this.req;
      if (!this.rows.length) this.skeleton(); else this.tbody.style.opacity = '.55';
      try {
        const res = await get(this.o.endpoint, this.params());
        if (my !== this.req) return;
        this.rows = res.data || [];
        this.meta = res.meta || { page: 1, pages: 1, total: this.rows.length, per_page: this.rows.length };
        this.state.page = this.meta.page;
        this.render();
        this.o.onLoad && this.o.onLoad(res, this);
      } catch (e) {
        if (my !== this.req) return;
        this.tbody.innerHTML = `<tr><td colspan="${this.o.columns.length}"><div class="empty"><div class="empty-icon"><i class="bi bi-wifi-off"></i></div><h4>Could not load data</h4><p>${esc(e.message)}</p></div></td></tr>`;
      } finally { this.tbody.style.opacity = ''; }
    }
    render() {
      $$('th[data-sort]', this.el).forEach(th => {
        const on = th.dataset.sort === this.state.sort;
        th.classList.toggle('sorted', on);
        th.querySelector('.sort-ind').className = 'bi sort-ind ' + (on ? (this.state.dir === 'asc' ? 'bi-sort-up' : 'bi-sort-down') : 'bi-arrow-down-up');
      });
      if (!this.rows.length) {
        this.tbody.innerHTML = `<tr><td colspan="${this.o.columns.length}"><div class="empty"><div class="empty-icon"><i class="bi bi-${this.o.emptyIcon}"></i></div><h4>${esc(this.o.empty)}</h4><p>${esc(this.o.emptyHint || 'Try adjusting your search or filters.')}</p></div></td></tr>`;
      } else {
        this.tbody.innerHTML = this.rows.map((r, i) => `<tr data-i="${i}" class="${this.o.onRowClick ? 'clickable' : ''} ${this.o.rowClass ? this.o.rowClass(r) : ''}">${this.o.columns.map(c => {
          const v = c.render ? c.render(r) : esc(r[c.key] ?? '—');
          return `<td class="${c.align === 'right' ? 'num' : ''} ${c.cls || ''} ${c.hideSm ? 'hide-sm' : ''} ${c.hideMd ? 'hide-md' : ''}">${v}</td>`;
        }).join('')}</tr>`).join('');
      }
      const m = this.meta;
      const from = m.total ? (m.page - 1) * m.per_page + 1 : 0;
      const to = Math.min(m.total, m.page * m.per_page);
      this.el.querySelector('.dt-info').innerHTML = `Showing <strong>${num(from)}–${num(to)}</strong> of <strong>${num(m.total)}</strong>`;
      this.el.querySelector('.pager').innerHTML = this.pagerHtml(m.page, m.pages);
    }
    pagerHtml(page, pages) {
      const btn = (p, label, dis = false, act = false) => `<button type="button" data-page="${p}" ${dis ? 'disabled' : ''} class="${act ? 'active' : ''}" aria-label="Page ${p}">${label}</button>`;
      let out = btn(page - 1, '<i class="bi bi-chevron-left"></i>', page <= 1);
      const set = new Set([1, pages, page, page - 1, page + 1]);
      if (page <= 3) [2, 3, 4].forEach(n => set.add(n));
      if (page >= pages - 2) [pages - 1, pages - 2, pages - 3].forEach(n => set.add(n));
      const list = [...set].filter(n => n >= 1 && n <= pages).sort((a, b) => a - b);
      let last = 0;
      list.forEach(n => { if (n - last > 1) out += '<span class="gap">…</span>'; out += btn(n, num(n), false, n === page); last = n; });
      return out + btn(page + 1, '<i class="bi bi-chevron-right"></i>', page >= pages);
    }
    reload(resetPage = false) { if (resetPage) this.state.page = 1; return this.load(); }
    exportUrl(route) {
      const qs = new URLSearchParams(this.params(false));
      return `api.php?r=${route || this.o.exportEndpoint}&${qs.toString()}`;
    }
  }
  DataTable.all = [];
  document.addEventListener('app:saved', () => DataTable.all.forEach(t => document.body.contains(t.el) && t.reload()));

  /* ------------------------------------------------------------------
   | Receipt (after deposits, withdrawals, repayments, share purchases)
   * ---------------------------------------------------------------- */
  function showReceipt(r, message) {
    const m = document.createElement('div');
    m.className = 'modal modal-sm';
    m.setAttribute('data-ephemeral', '');
    m.innerHTML = `<div class="modal-dialog"><div class="modal-body receipt" style="padding-top:30px">
        <div class="check-anim"><i class="bi bi-check-lg"></i></div>
        <div class="muted text-sm fw-bold">${esc(r.type)} successful</div>
        <div class="amt">${money(r.amount)}</div>
        <p class="muted text-sm mb-2">${esc(message || '')}</p>
        <div class="summary-box" style="text-align:left">
          <div class="summary-row"><span>Reference</span><strong class="mono">${esc(r.ref)}</strong></div>
          <div class="summary-row"><span>Member</span><strong>${esc(r.member)}</strong></div>
          <div class="summary-row"><span>Account</span><strong>${esc(r.account)}</strong></div>
          <div class="summary-row"><span>Date</span><strong>${dateTime(new Date().toISOString())}</strong></div>
          <div class="summary-row total"><span>Balance</span><strong>${money(r.balance)}</strong></div>
        </div></div>
      <div class="modal-foot"><button type="button" class="btn btn-ghost" data-print><i class="bi bi-printer"></i><span>Print</span></button>
        <button type="button" class="btn btn-primary" data-close><span>Done</span></button></div></div>`;
    document.body.appendChild(m);
    m.querySelector('[data-print]').addEventListener('click', () => printReceipt(r));
    Modal.open(m);
  }

  function printReceipt(r) {
    const w = window.open('', '_blank', 'width=420,height=640');
    if (!w) return;
    const rows = [['Reference', r.ref], ['Transaction', r.type], ['Member', r.member], ['Account', r.account], ['Amount', money(r.amount)], ['Balance', money(r.balance)], ['Date', new Date().toLocaleString('en-GB')], ['Served by', CFG.user?.name || '']];
    w.document.write(`<!doctype html><html><head><title>Receipt ${esc(r.ref)}</title><style>body{font:13px/1.5 system-ui,sans-serif;padding:24px;color:#0b1b3f}h2{margin:0}table{width:100%;border-collapse:collapse;margin:16px 0}td{padding:6px 0;border-bottom:1px dashed #ccd}td:last-child{text-align:right;font-weight:700}.c{text-align:center}.muted{color:#64748f}</style></head>
      <body><div class="c"><h2>${esc(CFG.sacco || 'SACCO')}</h2><div class="muted">Official transaction receipt</div></div><table>${rows.map(([k, v]) => `<tr><td>${esc(k)}</td><td>${esc(v)}</td></tr>`).join('')}</table><p class="c muted">Thank you for saving with us.</p><script>window.onload=()=>{window.print();}<\/script></body></html>`);
    w.document.close();
  }

  /* ------------------------------------------------------------------
   | Theme, sidebar, dropdowns, tabs
   * ---------------------------------------------------------------- */
  const store = {
    get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* storage unavailable */ } },
  };

  function setTheme(t) {
    document.documentElement.dataset.theme = t;
    store.set('sacco-theme', t);
    document.dispatchEvent(new CustomEvent('app:theme', { detail: t }));
  }

  document.addEventListener('click', e => {
    if (e.target.closest('[data-theme-toggle]')) setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');

    if (e.target.closest('[data-sidebar-toggle]')) {
      if (window.innerWidth <= 1024) document.body.classList.toggle('sidebar-open');
      else { document.body.classList.toggle('sidebar-mini'); store.set('sacco-sidebar', document.body.classList.contains('sidebar-mini') ? 'mini' : 'full'); }
    }
    if (e.target.closest('.sidebar-overlay')) document.body.classList.remove('sidebar-open');

    const dt = e.target.closest('[data-dropdown-toggle]');
    $$('.dropdown.open').forEach(d => { if (!d.contains(e.target) || (dt && d !== dt.closest('.dropdown'))) d.classList.remove('open'); });
    if (dt) { const d = dt.closest('.dropdown'); d.classList.toggle('open'); d.dispatchEvent(new CustomEvent(d.classList.contains('open') ? 'dropdown:open' : 'dropdown:close')); }

    const tab = e.target.closest('[data-tab]');
    if (tab) {
      const group = tab.closest('[data-tabs]');
      $$('[data-tab]', group).forEach(t => t.classList.toggle('active', t === tab));
      const name = group.dataset.tabs;
      $$(`.tab-panel[data-group="${name}"]`).forEach(p => p.classList.toggle('active', p.id === tab.dataset.tab));
      group.dispatchEvent(new CustomEvent('tab:change', { detail: tab.dataset.tab }));
    }

    const opener = e.target.closest('[data-open]');
    if (opener) {
      e.preventDefault();
      let data = null;
      try { data = opener.dataset.prefill ? JSON.parse(opener.dataset.prefill) : null; } catch (err) { data = null; }
      openForm(opener.dataset.open, data);
    }

    const pw = e.target.closest('[data-toggle-password]');
    if (pw) {
      const input = pw.parentElement.querySelector('input');
      input.type = input.type === 'password' ? 'text' : 'password';
      pw.innerHTML = `<i class="bi bi-eye${input.type === 'password' ? '' : '-slash'}"></i>`;
    }
  });

  /* ------------------------------------------------------------------
   | KPI helpers, count-up, sparkline, charts
   * ---------------------------------------------------------------- */
  function countUp(el, value, fmt) {
    const target = Number(value) || 0;
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce || target === 0) { el.innerHTML = fmt(target); return; }
    const start = performance.now(), dur = 900;
    const step = t => {
      const p = Math.min(1, (t - start) / dur);
      const eased = 1 - Math.pow(1 - p, 3);
      el.innerHTML = fmt(target * eased);
      if (p < 1) requestAnimationFrame(step); else el.innerHTML = fmt(target);
    };
    requestAnimationFrame(step);
  }

  const kpiFormats = {
    money: v => `<small>${esc(cur())}</small>${Math.abs(v) >= 1e9 ? compact(v) : num(v)}`,
    short: v => `<small>${esc(cur())}</small>${compact(v)}`,
    num: v => num(v),
    pct: v => `${(Number(v) || 0).toFixed(1)}<small style="margin-left:2px">%</small>`,
  };

  function fillKpis(root, data) {
    $$('[data-kpi]', root || document).forEach(el => {
      const key = el.dataset.kpi;
      if (!(key in data)) return;
      const fmt = kpiFormats[el.dataset.format || 'num'] || kpiFormats.num;
      if (el.dataset.format === 'money' || el.dataset.format === 'short') el.title = money(data[key]);
      countUp(el, data[key], fmt);
    });
  }

  function sparkline(values, w = 200, h = 38) {
    const v = (values || []).map(Number);
    if (v.length < 2) return '';
    const max = Math.max(...v), min = Math.min(...v), span = max - min || 1;
    const pts = v.map((y, i) => [(i / (v.length - 1)) * w, h - 3 - ((y - min) / span) * (h - 6)]);
    const d = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
    return `<svg class="sparkline" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" aria-hidden="true"><path d="${d}"/></svg>`;
  }

  /** Chart palette, validated for both themes (green · blue · amber). */
  function chartTheme() {
    const cs = getComputedStyle(document.documentElement);
    const v = n => cs.getPropertyValue(n).trim();
    return { c1: v('--chart-1'), c2: v('--chart-2'), c3: v('--chart-3'), text: v('--muted'), ink: v('--text'), grid: v('--grid-line'), surface: v('--surface'), dark: document.documentElement.dataset.theme === 'dark' };
  }

  const charts = [];
  /** Register a chart builder; it is rebuilt automatically when the theme changes. */
  function chart(canvas, build) {
    if (!window.Chart) return null;
    const el = typeof canvas === 'string' ? document.getElementById(canvas) : canvas;
    if (!el) return null;
    const existing = charts.find(c => c.el === el);
    if (existing) { existing.instance.destroy(); charts.splice(charts.indexOf(existing), 1); }
    const t = chartTheme();
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = t.text;
    const instance = new Chart(el, build(t));
    charts.push({ el, build, instance });
    return instance;
  }
  document.addEventListener('app:theme', () => {
    charts.slice().forEach(c => chart(c.el, c.build));
  });
  const tooltipStyle = t => ({
    backgroundColor: t.dark ? '#0b1a3a' : '#0a1f4d', titleColor: '#fff', bodyColor: '#dbe6fb', borderColor: t.dark ? 'rgba(0,255,163,.35)' : 'transparent',
    borderWidth: t.dark ? 1 : 0, padding: 12, cornerRadius: 12, boxPadding: 6, usePointStyle: true,
    callbacks: { label: ctx => ` ${ctx.dataset.label ? ctx.dataset.label + ': ' : ''}${ctx.dataset.isCount ? num(ctx.parsed.y ?? ctx.parsed.x) : money(ctx.parsed.y ?? ctx.parsed.x)}` },
  });

  /* ------------------------------------------------------------------
   | Topbar: global member search & notifications
   * ---------------------------------------------------------------- */
  function initGlobalSearch() {
    const box = $('.global-search');
    if (!box) return;
    const input = box.querySelector('input');
    const menu = box.querySelector('.dropdown-menu');
    let items = [], focus = 0;
    const render = () => {
      menu.innerHTML = items.length
        ? `<div class="dropdown-head">Members</div>` + items.map((m, i) => `<a class="dropdown-item ${i === focus ? 'focus' : ''}" style="${i === focus ? 'background:var(--surface-3)' : ''}" href="index.php?page=member&id=${m.id}">${avatar(m.name, 'sm')}<span style="flex:1;min-width:0"><strong style="display:block;color:var(--text)">${esc(m.name)}</strong><span class="text-xs muted">${esc(m.member_no)} · ${esc(m.phone)} · ${esc(m.branch_name)}</span></span>${badge(m.status)}</a>`).join('')
        : `<div class="picker-empty">No members match “${esc(input.value)}”</div>`;
    };
    const search = debounce(async q => {
      if (!q) { box.classList.remove('open'); return; }
      try { items = (await get('members/search', { q })).data; focus = 0; render(); box.classList.add('open'); } catch (e) { /* ignore */ }
    }, 220);
    input.addEventListener('input', () => search(input.value.trim()));
    input.addEventListener('keydown', e => {
      if (!box.classList.contains('open') || !items.length) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); focus = (focus + 1) % items.length; render(); }
      if (e.key === 'ArrowUp') { e.preventDefault(); focus = (focus - 1 + items.length) % items.length; render(); }
      if (e.key === 'Enter') { e.preventDefault(); location.href = `index.php?page=member&id=${items[focus].id}`; }
      if (e.key === 'Escape') { box.classList.remove('open'); input.blur(); }
    });
    document.addEventListener('keydown', e => {
      const typing = /INPUT|TEXTAREA|SELECT/.test(document.activeElement?.tagName || '');
      if ((e.key === '/' && !typing) || ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k')) { e.preventDefault(); input.focus(); input.select(); }
    });
  }

  async function loadAlerts() {
    const wrap = $('#notifications');
    if (!wrap) return;
    try {
      const items = (await get('dashboard/alerts')).data;
      const dot = wrap.querySelector('.dot');
      dot.hidden = !items.length;
      dot.textContent = items.length;
      wrap.querySelector('.notif-list').innerHTML = items.length
        ? items.map(i => `<a class="notif-item" href="${esc(i.url)}"><span class="notif-ico tone-${esc(i.tone)}"><i class="bi bi-${esc(i.icon)}"></i></span><span>${esc(i.title)}</span></a>`).join('')
        : `<div class="picker-empty"><i class="bi bi-check2-all text-green"></i> You're all caught up</div>`;
    } catch (e) { /* non-critical */ }
  }

  /* ------------------------------------------------------------------
   | Boot
   * ---------------------------------------------------------------- */
  function boot() {
    if (store.get('sacco-sidebar') === 'mini' && window.innerWidth > 1024) document.body.classList.add('sidebar-mini');
    $$('.picker').forEach(p => Picker.init(p));
    $$('form.wizard').forEach(f => Wizard.init(f));
    $$('[data-default="today"]').forEach(i => { if (!i.value) i.value = today(); });
    initGlobalSearch();
    loadAlerts();
    document.addEventListener('app:saved', debounce(loadAlerts, 600));
  }
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', boot) : boot();

  window.App = {
    $, $$, esc, money, num, compact, date, dateTime, timeAgo, today, label, initials, avatar, badge, debounce, txnInfo, METHODS,
    api, get, post, toast, Modal, confirm: confirmDialog, Forms, openForm, Wizard, Picker, DataTable,
    fillKpis, countUp, sparkline, chart, chartTheme, tooltipStyle, showReceipt, setTheme, cfg: CFG,
    can: p => (CFG.perms || []).includes(p) || CFG.user?.role === 'admin',
  };
})();
