<?php
defined('SACCO') or exit;
$send = Auth::can('sms.send');
$branches = DB::all("SELECT id, name FROM branches WHERE status = 'active' ORDER BY id");
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Operations</span><i class="bi bi-chevron-right"></i><span>SMS</span></div>
    <h1>SMS</h1>
    <p>Bulk messages to members and every automatic text (receipts, reminders, arrears alerts), sent through Shanfix SMS.</p>
  </div>
</div>

<div data-sms-alert></div>
<div class="grid-kpi mb-3" data-kpis></div>

<div class="underline-tabs mb-3" data-tabs="sms">
  <?php if ($send): ?><button class="tab active" data-tab="tab-compose"><i class="bi bi-pencil-square"></i>Compose</button><?php endif; ?>
  <button class="tab <?= $send ? '' : 'active' ?>" data-tab="tab-batches"><i class="bi bi-collection"></i>Campaigns</button>
  <button class="tab" data-tab="tab-log"><i class="bi bi-list-ul"></i>Message log</button>
</div>

<?php if ($send): ?>
<div class="tab-panel active" id="tab-compose" data-group="sms">
  <section class="card">
    <div class="card-body">
      <form id="smsForm" data-api="sms/send" data-keep-open novalidate autocomplete="off">
        <div class="sms-compose">
          <div>
            <div class="form-grid">
              <div class="field"><label>Campaign name <span class="req">*</span></label><input class="input" name="title" required maxlength="120" placeholder="e.g. AGM notice March"></div>
              <div class="field"><label>Send to <span class="req">*</span></label>
                <select class="select" name="audience" required><?php foreach (SmsService::AUDIENCES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="field" data-show-when="audience:branch"><label>Branch <span class="req">*</span></label><select class="select" name="branch_id" required><option value="">Choose…</option><?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
            <div class="field" data-show-when="audience:members"><label>Members <span class="req">*</span></label>
              <div class="picker" data-name="pick_member"></div><div class="chip-row" data-picked></div><div data-picked-inputs></div></div>
            <div class="field" data-show-when="audience:numbers"><label>Phone numbers <span class="req">*</span></label><textarea class="textarea" name="numbers" rows="4" required placeholder="0712345678, 0722000111 — one per line or separated by commas"></textarea></div>
            <div class="field"><label>Message <span class="req">*</span></label>
              <textarea class="textarea" name="message" rows="6" required maxlength="918" placeholder="Dear {first_name}, …"></textarea>
              <div class="chip-row"><?php foreach (SmsService::PLACEHOLDERS as $p): ?><button type="button" data-insert="{<?= e($p) ?>}">{<?= e($p) ?>}</button><?php endforeach; ?></div>
              <div class="sms-meter" data-meter></div>
            </div>
            <div class="alert alert-info" data-estimate hidden></div>
            <div class="btn-row"><button type="submit" class="btn btn-primary"><i class="bi bi-send"></i><span>Queue messages</span></button><span class="text-xs muted">Messages go out in the background — you can leave this page.</span></div>
          </div>
          <div>
            <div class="form-section">Preview</div>
            <div class="sms-phone"><div class="from"><?= e(config('sms.sender_id') ?: 'Your sender ID') ?></div><div class="bubble" data-preview>Your message appears here.</div></div>
            <p class="text-xs muted mt-1">Placeholders are filled in per member; <code>{sacco}</code> becomes “<?= e(SmsService::signature()) ?>”. Personalised messages are sent one by one (about 50 a minute); identical messages go out in bulk.</p>
          </div>
        </div>
      </form>
    </div>
  </section>
</div>
<?php endif; ?>

<div class="tab-panel <?= $send ? '' : 'active' ?>" id="tab-batches" data-group="sms">
  <section class="card"><div id="batchTable"></div></section>
</div>

<div class="tab-panel" id="tab-log" data-group="sms">
  <section class="card">
    <form class="toolbar" id="logFilters" autocomplete="off">
      <div class="search"><i class="bi bi-search"></i><input class="input" type="search" name="search" placeholder="Phone number"></div>
      <select class="select" name="status"><option value="">All statuses</option><?php foreach (['queued', 'sent', 'delivered', 'failed', 'cancelled'] as $st): ?><option value="<?= $st ?>"><?= ucfirst($st) ?></option><?php endforeach; ?></select>
      <select class="select" name="category"><option value="">All types</option><?php foreach (SmsService::CATEGORIES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select>
      <input type="hidden" name="batch_id">
      <input class="input" type="date" name="date_from" value="<?= date('Y-m-d', strtotime('-7 days')) ?>" aria-label="From">
      <input class="input" type="date" name="date_to" value="<?= today() ?>" aria-label="To">
    </form>
    <div id="logTable"></div>
  </section>
</div>

<?php ob_start(); ?>
<script>
(function () {
  const A = App, canSend = <?= $send ? 'true' : 'false' ?>;
  const tones = { queued: 'pending', sending: 'pending', sent: 'approved', delivered: 'active', failed: 'rejected', cancelled: 'closed', done: 'active' };

  async function kpis() {
    let d;
    try { d = (await A.get('sms/summary')).data; } catch (e) { return; }
    A.$('[data-sms-alert]').innerHTML = !d.configured
      ? '<div class="alert alert-danger mb-3"><i class="bi bi-plug"></i> SMS is not connected. Add <code>SMS_CLIENT_ID</code> and <code>SMS_API_KEY</code> from your Shanfix SMS account (Client → API) to <code>.env</code>.</div>'
      : !d.enabled ? '<div class="alert alert-warning mb-3"><i class="bi bi-pause-circle"></i> SMS sending is switched off in Settings → Notifications. Messages stay queued.</div>'
      : d.balance_error ? `<div class="alert alert-warning mb-3"><i class="bi bi-exclamation-triangle"></i> ${A.esc(d.balance_error)}</div>` : '';
    const t = d.today || {};
    A.$('[data-kpis]').innerHTML = [
      ['SMS balance', d.balance ? `${A.num(d.balance.units)} units` : '—', d.balance && d.balance.units < 100 ? 'rose' : 'navy', 'wallet2', d.balance ? A.esc(d.balance.account) : 'Shanfix SMS'],
      ['Sent today', A.num(t.sent || 0), 'green', 'send-check', `${A.num(t.delivered || 0)} delivered`],
      ['In the queue', A.num(d.queued || 0), 'amber', 'hourglass-split', 'sent every minute'],
      ['Failed today', A.num(t.failed || 0), (t.failed || 0) > 0 ? 'rose' : 'teal', 'x-octagon', `${A.num(t.units || 0)} units used today`],
    ].map(([l, v, tn, i, m]) => `<div class="stat-card" data-tone="${tn}"><div class="stat-top"><span class="stat-label">${l}</span><span class="stat-icon"><i class="bi bi-${i}"></i></span></div><div class="stat-value" style="font-size:22px">${v}</div><div class="stat-meta text-xs muted">${m}</div></div>`).join('');
  }

  /* ---------- Compose ---------- */
  const form = A.$('#smsForm');
  if (form) {
    const msg = form.elements.namedItem('message');
    const picked = new Map();
    const sample = { first_name: 'Wanjiku', name: 'Wanjiku Kamau', member_no: 'MBR000123', savings: A.money(45200), shares: A.money(12000), loan_balance: A.money(80000), sacco: <?= json_encode(SmsService::signature()) ?> };
    const fill = t => t.replace(/\{([a-z_]+)\}/g, (m, k) => sample[k] ?? m);
    const gsm = /^[\n\r\x20-\x7E£¥èéùìòÇØøÅåÄÖÑÜ§äöñüà€]*$/;
    function meter() {
      const text = fill(msg.value), uni = !gsm.test(text);
      const [one, many] = uni ? [70, 67] : [160, 153];
      const parts = !text.length ? 0 : text.length <= one ? 1 : Math.ceil(text.length / many);
      A.$('[data-meter]').innerHTML = `<span><strong>${text.length}</strong> characters</span><span><strong>${parts}</strong> SMS part${parts === 1 ? '' : 's'} each</span><span>${uni ? 'Unicode (emoji/special characters: 70 per part)' : 'Standard text: 160 per part'}</span>`;
      A.$('[data-preview]').textContent = text || 'Your message appears here.';
    }
    const estimate = A.debounce(async () => {
      const fd = new FormData(form);
      const p = { audience: fd.get('audience'), branch_id: fd.get('branch_id') || '', message: fd.get('message') || '', numbers: fd.get('numbers') || '' };
      [...picked.keys()].forEach((id, i) => (p[`member_ids[${i}]`] = id));
      try {
        const d = (await A.get('sms/preview', p)).data;
        const box = A.$('[data-estimate]');
        box.hidden = false;
        box.innerHTML = `<i class="bi bi-people"></i> <strong>${A.num(d.recipients)}</strong> recipient(s) · about <strong>${A.num(d.parts)}</strong> SMS part(s)${d.recipients ? ` · roughly ${d.minutes <= 1 ? 'a minute' : d.minutes + ' minutes'} to send${d.personal ? ' (personalised)' : ''}` : ''}.`;
      } catch (e) { /* estimate is advisory */ }
    }, 400);
    msg.addEventListener('input', () => { meter(); estimate(); });
    form.addEventListener('change', estimate);
    form.addEventListener('click', e => {
      const b = e.target.closest('[data-insert]');
      if (b) { const s = msg.selectionStart ?? msg.value.length; msg.setRangeText(b.dataset.insert, s, msg.selectionEnd ?? s, 'end'); msg.focus(); meter(); estimate(); }
      const x = e.target.closest('[data-unpick]');
      if (x) { picked.delete(x.dataset.unpick); renderPicked(); }
    });
    function renderPicked() {
      A.$('[data-picked]').innerHTML = [...picked].map(([id, n]) => `<button type="button" data-unpick="${id}">${A.esc(n)} ✕</button>`).join('');
      A.$('[data-picked-inputs]').innerHTML = [...picked.keys()].map(id => `<input type="hidden" name="member_ids[]" value="${id}">`).join('');
      estimate();
    }
    form.addEventListener('picker:change', e => {
      if (!e.detail) return;
      picked.set(String(e.detail.id), e.detail.name);
      A.Picker.clear(e.target, true);
      renderPicked();
    });
    form.addEventListener('form:success', () => {
      form.reset(); picked.clear(); renderPicked(); meter();
      A.$('[data-estimate]').hidden = true;
      document.querySelector('[data-tab="tab-batches"]').click();
      kpis();
    });
    meter();
  }

  /* ---------- Campaigns ---------- */
  const batches = new A.DataTable('#batchTable', {
    endpoint: 'sms/batches', empty: 'No campaigns yet', emptyIcon: 'collection',
    columns: [
      { label: 'Campaign', render: r => `<strong>${A.esc(r.title)}</strong><div class="text-xs muted" style="max-width:360px" title="${A.esc(r.message)}">${A.esc(r.message.slice(0, 90))}${r.message.length > 90 ? '…' : ''}</div>` },
      { label: 'Audience', hideSm: true, render: r => `<span class="text-sm">${A.esc(r.audience || '')}</span><div class="text-xs muted">${A.esc(r.created_by_name || '')} · ${A.dateTime(r.created_at)}</div>` },
      { label: 'Progress', render: r => { const done = +r.sent + +r.failed, pct = r.total ? Math.round(done / r.total * 100) : 0; return `<div class="progress"><span style="width:${pct}%"></span></div><div class="text-xs muted mt-1">${A.num(r.sent)} sent · ${A.num(r.failed)} failed · ${A.num(r.total)} total</div>`; } },
      { label: 'Units', align: 'right', hideSm: true, render: r => A.num(r.units) },
      { label: 'Status', render: r => A.badge(tones[r.status] || r.status, A.label(r.status)) },
      { label: '', align: 'right', render: r => `<span class="nowrap"><button class="act" data-action="log" title="View messages"><i class="bi bi-list-ul"></i></button>${canSend && ['queued', 'sending'].includes(r.status) ? '<button class="act" data-action="cancel" title="Cancel unsent"><i class="bi bi-x-circle"></i></button>' : ''}</span>` },
    ],
    onAction: async (a, r) => {
      if (a === 'log') { A.$('#logFilters [name=batch_id]').value = r.id; document.querySelector('[data-tab="tab-log"]').click(); log.reload(); return; }
      if (a === 'cancel' && await A.confirm({ title: 'Cancel this campaign?', message: 'Messages already sent cannot be recalled; the rest will not go out.', confirmText: 'Cancel unsent' })) {
        try { A.toast((await A.post('sms/cancel_batch', { id: r.id })).message); batches.reload(); kpis(); } catch (e) { A.toast(e.message, 'error'); }
      }
    },
  });

  /* ---------- Log ---------- */
  const log = new A.DataTable('#logTable', {
    endpoint: 'sms/messages', filters: '#logFilters', empty: 'No messages', emptyIcon: 'chat-dots',
    columns: [
      { label: 'When', render: r => `<span class="nowrap">${A.dateTime(r.created_at)}</span>` },
      { label: 'To', render: r => `${r.member_id ? `<a class="strong nowrap" href="index.php?page=member&id=${r.member_id}">${A.esc(r.first_name + ' ' + r.last_name)}</a>` : ''}<div class="text-xs muted mono">${A.esc(r.phone)}</div>` },
      { label: 'Message', render: r => `<span class="text-sm" style="display:inline-block;max-width:420px">${A.esc(r.message)}</span>` },
      { label: 'Type', hideSm: true, render: r => `<span class="tag">${A.esc(r.category_label)}</span>` },
      { label: 'Status', render: r => `${A.badge(tones[r.status] || r.status, A.label(r.status))}${r.error ? `<div class="text-xs text-red" style="max-width:200px">${A.esc(r.error)}</div>` : ''}` },
      { label: '', align: 'right', render: r => canSend && r.status === 'failed' && r.error !== 'Invalid phone number' ? '<button class="act" data-action="retry" title="Send again"><i class="bi bi-arrow-clockwise"></i></button>' : '' },
    ],
    onAction: async (a, r) => {
      if (a !== 'retry') return;
      try { A.toast((await A.post('sms/retry', { id: r.id })).message); log.reload(); } catch (e) { A.toast(e.message, 'error'); }
    },
  });

  kpis();
  setInterval(() => { if (!document.hidden) { kpis(); batches.reload(); } }, 30000);
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
