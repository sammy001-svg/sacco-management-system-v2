<?php
defined('SACCO') or exit;
$canPost = Auth::can('accounting.journal');
$ownBranch = Auth::user()['branch_id'] ?? null;
$accounts = DB::all("SELECT id, code, name, type, system_key FROM gl_accounts WHERE status = 'active' ORDER BY code");
$options = '<option value="">Choose account…</option>';
foreach (Accounting::TYPES as $type => $label) {
    $options .= '<optgroup label="' . e($label) . '">';
    foreach ($accounts as $a) {
        if ($a['type'] !== $type) {
            continue;
        }
        $control = in_array($a['system_key'], Accounting::CONTROL, true);
        $options .= '<option value="' . (int) $a['id'] . '"' . ($control ? ' disabled' : '') . '>' . e($a['code'] . ' · ' . $a['name']) . ($control ? ' (control)' : '') . '</option>';
    }
    $options .= '</optgroup>';
}
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Accounting</span><i class="bi bi-chevron-right"></i><span>Journal entries</span></div>
    <h1>Journal entries</h1>
    <p>Every posting to the general ledger. Member transactions are journaled automatically.</p>
  </div>
  <div class="page-actions">
    <?php if ($canPost): ?><button class="btn btn-primary" data-open="journalModal"><i class="bi bi-plus-lg"></i><span>Manual journal</span></button><?php endif; ?>
  </div>
</div>

<section class="card">
  <form class="toolbar" id="jFilters" autocomplete="off">
    <div class="search"><i class="bi bi-search"></i><input class="input" type="search" name="search" placeholder="Entry no (JE…) or reference (TX…)"></div>
    <select class="select" name="source" aria-label="Source"><option value="">All sources</option><?php foreach (Accounting::SOURCES as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select>
    <?= branch_filter() ?>
    <input class="input" type="date" name="date_from" value="<?= date('Y-m-d', strtotime('-30 days')) ?>" aria-label="From">
    <input class="input" type="date" name="date_to" value="<?= today() ?>" aria-label="To">
  </form>
  <div id="jTable"></div>
</section>

<div class="modal modal-lg" id="journalView" role="dialog" aria-modal="true" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-head"><div class="modal-icon navy"><i class="bi bi-journal-text"></i></div><div class="modal-title"><h3 data-v-title>Journal entry</h3><p data-v-sub></p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body" data-v-body></div>
    <div class="modal-foot" data-v-actions></div>
  </div>
</div>

<?php if ($canPost): ?>
<div class="modal modal-xl" id="journalModal" role="dialog" aria-modal="true" aria-hidden="true" data-static>
  <form class="modal-dialog" data-api="accounting/journal_save" novalidate>
    <div class="modal-head"><div class="modal-icon"><i class="bi bi-journal-plus"></i></div><div class="modal-title"><h3>Manual journal entry</h3><p>Adjustments, depreciation, bank charges, opening balances. Debits must equal credits.</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <div class="form-grid cols-3">
        <div class="field"><label>Date <span class="req">*</span></label><input class="input" type="date" name="entry_date" required data-default="today" max="<?= today() ?>"></div>
        <div class="field"><label>Reference</label><input class="input" name="reference" maxlength="80" placeholder="Voucher / invoice no."></div>
        <div class="field"><label>Type</label><select class="select" name="source"><option value="manual">Adjustment</option><option value="opening">Opening balance</option></select></div>
        <div class="field span-2"><label>Narration <span class="req">*</span></label><input class="input" name="narration" required maxlength="255" placeholder="e.g. Depreciation of office equipment, October"></div>
        <?php if ($ownBranch): ?><input type="hidden" name="branch_id" value="<?= (int) $ownBranch ?>" data-keep>
        <?php else: ?><div class="field"><label>Branch</label><select class="select" name="branch_id"><option value="">Head office / all</option><?php foreach (DB::all('SELECT id, name FROM branches ORDER BY id') as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      </div>
      <div class="table-wrap"><table class="table compact">
        <thead><tr><th style="min-width:260px">Account</th><th>Memo</th><th class="num" style="width:150px">Debit</th><th class="num" style="width:150px">Credit</th><th style="width:44px"></th></tr></thead>
        <tbody data-jlines></tbody>
        <tfoot><tr><td><button type="button" class="btn btn-soft btn-sm" data-add-line><i class="bi bi-plus-lg"></i><span>Add line</span></button></td><td class="text-right">Totals</td><td class="num" data-tdr>0.00</td><td class="num" data-tcr>0.00</td><td></td></tr></tfoot>
      </table></div>
      <div class="field-error mt-1" data-error-for="lines"></div>
      <div class="info-strip mt-2" data-jbal><i class="bi bi-scale"></i><span>Enter amounts to balance the entry.</span></div>
      <p class="muted text-xs">Control accounts (member savings, share capital, loans, clearing) are disabled: they change only through member transactions.</p>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary" data-jsubmit><i class="bi bi-check2-circle"></i><span>Post journal</span></button></div>
  </form>
</div>
<template id="jLineTpl"><tr>
  <td><select class="select input-sm" data-n="account_id" required><?= $options ?></select></td>
  <td><input class="input input-sm" data-n="memo" maxlength="255"></td>
  <td><input class="input input-sm text-right" type="number" min="0" step="0.01" data-n="debit"></td>
  <td><input class="input input-sm text-right" type="number" min="0" step="0.01" data-n="credit"></td>
  <td><button type="button" class="act danger" data-rm-line title="Remove line"><i class="bi bi-x-lg"></i></button></td>
</tr></template>
<?php endif; ?>

<?php ob_start(); ?>
<script>
(function () {
  const A = App;
  const sources = <?= json_encode(Accounting::SOURCES) ?>;
  const srcTone = { transaction: '', manual: 'info', opening: 'info', interest: 'success', dividend: 'success', closing: 'warning' };

  new A.DataTable('#jTable', {
    endpoint: 'accounting/journals', filters: '#jFilters', perPage: 20, empty: 'No journal entries in this period', emptyIcon: 'journal-text',
    onRowClick: r => view(r.id),
    columns: [
      { label: 'Date', render: r => `<span class="nowrap">${A.date(r.entry_date)}</span>` },
      { label: 'Entry', render: r => `<span class="mono strong">${A.esc(r.entry_no)}</span>` },
      { label: 'Source', render: r => `<span class="badge no-dot ${srcTone[r.source] || ''}">${A.esc(sources[r.source] || r.source)}</span>` },
      { label: 'Narration', render: r => `<span class="text-sm truncate" style="display:inline-block;max-width:340px">${A.esc(r.narration || '')}</span>` },
      { label: 'Reference', hideSm: true, render: r => `<span class="mono text-xs">${A.esc(r.reference || '')}</span>` },
      { label: 'Amount', align: 'right', render: r => `<span class="strong">${A.money(r.total, false)}</span>` },
      { label: 'Status', render: r => A.badge(r.status) },
    ],
  });

  async function view(id) {
    const m = A.$('#journalView');
    A.$('[data-v-body]', m).innerHTML = '<span class="skeleton"></span>';
    A.$('[data-v-actions]', m).innerHTML = '';
    A.Modal.open(m);
    let j;
    try { j = (await A.get('accounting/journal', { id })).data; } catch (e) { A.$('[data-v-body]', m).innerHTML = `<div class="empty">${A.esc(e.message)}</div>`; return; }
    A.$('[data-v-title]', m).innerHTML = `${A.esc(j.entry_no)} &nbsp;${A.badge(j.status)}`;
    A.$('[data-v-sub]', m).textContent = `${sources[j.source] || j.source} · ${A.date(j.entry_date)} · posted by ${j.user_name || 'system'}`;
    let dr = 0, cr = 0;
    A.$('[data-v-body]', m).innerHTML = `<p class="mb-2">${A.esc(j.narration || '')}</p>
      <div class="kv mb-2">
        <div><span>Reference</span><strong class="mono">${A.esc(j.reference || '—')}</strong></div>
        ${j.txn_ref ? `<div><span>Ledger transaction</span><strong class="mono">${A.esc(j.txn_ref)}</strong></div>` : ''}
        ${j.member_id ? `<div><span>Member</span><strong><a href="index.php?page=member&id=${j.member_id}">View profile</a></strong></div>` : ''}
        ${j.reversed_by ? `<div><span>Reversed by</span><strong class="mono">${A.esc(j.reversed_by)}</strong></div>` : ''}
      </div>
      <div class="table-wrap"><table class="table compact"><thead><tr><th>Account</th><th>Memo</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>
      ${j.lines.map(l => { dr += +l.debit; cr += +l.credit; return `<tr><td><a href="index.php?page=accounts&open=${l.account_id}"><span class="mono">${A.esc(l.code)}</span> ${A.esc(l.name)}</a></td><td class="text-sm">${A.esc(l.memo || '')}</td><td class="num">${+l.debit ? A.money(l.debit, false) : ''}</td><td class="num">${+l.credit ? A.money(l.credit, false) : ''}</td></tr>`; }).join('')}
      </tbody><tfoot><tr><td colspan="2">Total</td><td class="num">${A.money(dr, false)}</td><td class="num">${A.money(cr, false)}</td></tr></tfoot></table></div>`;
    A.$('[data-v-actions]', m).innerHTML = (j.can_reverse ? `<button class="btn btn-ghost text-red left" data-reverse="${j.id}"><i class="bi bi-arrow-counterclockwise"></i><span>Reverse</span></button>` : '') + '<button class="btn btn-primary" data-close>Done</button>';
  }

  document.addEventListener('click', async e => {
    const r = e.target.closest('[data-reverse]');
    if (!r) return;
    const reason = await A.confirm({ title: 'Reverse this journal?', message: 'An equal and opposite entry dated today will be posted.', confirmText: 'Reverse journal', input: { label: 'Reason' } });
    if (!reason) return;
    try { A.toast((await A.post('accounting/journal_reverse', { id: r.dataset.reverse, reason })).message); A.Modal.close('journalView'); document.dispatchEvent(new CustomEvent('app:saved', { detail: {} })); }
    catch (err) { A.toast(err.message, 'error'); }
  });

  // Manual journal line editor
  const form = A.$('#journalModal form');
  if (form) {
    const body = A.$('[data-jlines]', form), tpl = A.$('#jLineTpl');
    let n = 0;
    const addLine = () => {
      const row = tpl.content.firstElementChild.cloneNode(true);
      A.$$('[data-n]', row).forEach(el => (el.name = `lines[${n}][${el.dataset.n}]`));
      n++;
      body.appendChild(row);
    };
    const totals = () => {
      let dr = 0, cr = 0;
      A.$$('tr', body).forEach(tr => { dr += +(A.$('[data-n="debit"]', tr).value || 0); cr += +(A.$('[data-n="credit"]', tr).value || 0); });
      A.$('[data-tdr]', form).textContent = A.money(dr, false);
      A.$('[data-tcr]', form).textContent = A.money(cr, false);
      const diff = Math.round((dr - cr) * 100) / 100, box = A.$('[data-jbal]', form);
      const balanced = dr > 0 && diff === 0;
      box.className = 'info-strip mt-2' + (balanced ? '' : (dr || cr ? ' warning' : ''));
      box.innerHTML = balanced ? '<i class="bi bi-check2-circle"></i><span>Balanced — ready to post.</span>'
        : `<i class="bi bi-scale"></i><span>${dr || cr ? `Out of balance by <strong>${A.money(Math.abs(diff))}</strong> (${diff > 0 ? 'more debits' : 'more credits'}).` : 'Enter amounts to balance the entry.'}</span>`;
      A.$('[data-jsubmit]', form).disabled = !balanced;
    };
    form.addEventListener('form:reset', () => { body.innerHTML = ''; n = 0; addLine(); addLine(); totals(); });
    form.addEventListener('input', e => {
      if (e.target.dataset.n === 'debit' && e.target.value) A.$('[data-n="credit"]', e.target.closest('tr')).value = '';
      if (e.target.dataset.n === 'credit' && e.target.value) A.$('[data-n="debit"]', e.target.closest('tr')).value = '';
      totals();
    });
    form.addEventListener('click', e => {
      if (e.target.closest('[data-add-line]')) addLine();
      const rm = e.target.closest('[data-rm-line]');
      if (rm && A.$$('tr', body).length > 2) { rm.closest('tr').remove(); totals(); }
    });
  }

  const open = new URLSearchParams(location.search).get('open');
  if (open) view(open);
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
