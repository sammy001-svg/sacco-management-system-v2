<?php
defined('SACCO') or exit;
$canEdit = Auth::can('accounting.journal');
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Accounting</span><i class="bi bi-chevron-right"></i><span>Chart of accounts</span></div>
    <h1>Chart of accounts</h1>
    <p>Every ledger account and its balance. Click an account to see its statement.</p>
  </div>
  <div class="page-actions">
    <input class="input" type="date" id="coaAsAt" value="<?= today() ?>" aria-label="Balances as at" style="width:auto">
    <?php if ($canEdit): ?><button class="btn btn-primary" data-open="accountModal"><i class="bi bi-plus-lg"></i><span>New account</span></button><?php endif; ?>
  </div>
</div>

<div class="grid-kpi mb-3">
  <?= kpi_card('Assets', 'asset', 'bank', 'navy', 'money') ?>
  <?= kpi_card('Liabilities', 'liability', 'journal-arrow-down', 'amber', 'money') ?>
  <?= kpi_card('Equity', 'equity', 'shield-check', 'teal', 'money') ?>
  <?= kpi_card('Income − expenses', 'net', 'graph-up-arrow', 'green', 'money', 'Unclosed surplus to date') ?>
</div>

<div class="stack" data-coa></div>

<?php if ($canEdit): ?>
<div class="modal" id="accountModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="accounting/account_save" novalidate>
    <div class="modal-head"><div class="modal-icon"><i class="bi bi-journal-bookmark"></i></div><div class="modal-title"><h3 data-title data-create="New ledger account" data-edit="Edit ledger account">New ledger account</h3><p>System accounts keep their type because automatic postings depend on them.</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <input type="hidden" name="id">
      <div class="form-grid">
        <div class="field"><label>Code <span class="req">*</span></label><input class="input" name="code" required maxlength="20" placeholder="e.g. 5240"></div>
        <div class="field"><label>Type <span class="req">*</span></label><select class="select" name="type" required><?php foreach (['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expense'] as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
        <div class="field span-2"><label>Name <span class="req">*</span></label><input class="input" name="name" required maxlength="120"></div>
        <div class="field span-2"><label>Description</label><input class="input" name="description" maxlength="255"></div>
        <div class="field"><label>Status</label><select class="select" name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><span>Save account</span></button></div>
  </form>
</div>
<?php endif; ?>

<div class="modal modal-xl" id="ledgerModal" role="dialog" aria-modal="true" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-head"><div class="modal-icon navy"><i class="bi bi-journal-text"></i></div><div class="modal-title"><h3 data-l-title>Account statement</h3><p data-l-sub></p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body">
      <form class="toolbar" id="ledgerFilters" style="padding:0 0 14px" onsubmit="return false">
        <input class="input" type="date" name="date_from" value="<?= date('Y-01-01') ?>" aria-label="From">
        <input class="input" type="date" name="date_to" value="<?= today() ?>" aria-label="To">
        <?= branch_filter() ?>
      </form>
      <div class="grid-kpi mb-2" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px" data-l-summary></div>
      <div id="ledgerTable"></div>
    </div>
  </div>
</div>

<?php ob_start(); ?>
<script>
(function () {
  const A = App, canEdit = <?= $canEdit ? 'true' : 'false' ?>;
  const typeNames = <?= json_encode(Accounting::TYPES) ?>;
  let accounts = [], ledger = null, current = null;

  async function load() {
    let res;
    try { res = await A.get('accounting/accounts', { as_at: A.$('#coaAsAt').value }); } catch (e) { return A.toast(e.message, 'error'); }
    accounts = res.data;
    const t = res.totals;
    A.fillKpis(document, { asset: t.asset, liability: t.liability, equity: t.equity, net: t.income - t.expense });
    A.$('[data-coa]').innerHTML = Object.entries(typeNames).map(([type, name]) => {
      const rows = accounts.filter(a => a.type === type);
      return `<section class="card"><div class="card-head"><h3><span class="h-icon"><i class="bi bi-folder2-open"></i></span>${A.esc(name)}</h3><strong>${A.money(t[type])}</strong></div>
        <div class="card-body flush"><div class="table-wrap"><table class="table compact">
        <thead><tr><th style="width:90px">Code</th><th>Account</th><th class="hide-sm">Role</th><th class="num">Balance</th><th style="width:80px"></th></tr></thead><tbody>
        ${rows.map(a => `<tr class="clickable" data-acc="${a.id}"><td class="mono strong">${A.esc(a.code)}</td>
          <td><span class="strong">${A.esc(a.name)}</span>${a.status === 'inactive' ? ' ' + A.badge('inactive') : ''}</td>
          <td class="hide-sm">${a.control ? '<span class="tag"><i class="bi bi-lock"></i>Control account</span>' : a.system_key ? '<span class="tag"><i class="bi bi-gear"></i>Automatic</span>' : '<span class="muted text-sm">Manual</span>'}</td>
          <td class="num strong ${a.balance < 0 ? 'text-red' : ''}">${A.money(a.balance, false)}</td>
          <td class="num">${canEdit ? `<button class="act" data-edit="${a.id}" title="Edit"><i class="bi bi-pencil-square"></i></button>` : ''}</td></tr>`).join('')}
        </tbody></table></div></div></section>`;
    }).join('');
  }

  function openLedger(id) {
    current = accounts.find(a => String(a.id) === String(id));
    if (!current) return;
    A.$('[data-l-title]').textContent = `${current.code} · ${current.name}`;
    A.$('[data-l-sub]').textContent = `${A.label(current.type)} account${current.control ? ' — control account, reconciled to member records' : ''}`;
    A.Modal.open('ledgerModal');
    if (ledger) { ledger.reload(true); return; }
    ledger = new A.DataTable('#ledgerTable', {
      endpoint: 'accounting/account_ledger', filters: '#ledgerFilters', perPage: 25, compact: true,
      params: () => ({ id: current.id }), empty: 'No postings in this period', emptyIcon: 'journal',
      columns: [
        { label: 'Date', render: r => `<span class="nowrap">${A.date(r.entry_date)}</span>` },
        { label: 'Entry', render: r => `<a href="index.php?page=journals&open=${r.entry_id}" class="mono strong">${A.esc(r.entry_no)}</a><div class="text-xs muted">${A.esc(r.reference || '')}</div>` },
        { label: 'Description', render: r => `<span class="text-sm">${A.esc(r.memo || r.narration || '')}</span>` },
        { label: 'Debit', align: 'right', render: r => Number(r.debit) ? A.money(r.debit, false) : '' },
        { label: 'Credit', align: 'right', render: r => Number(r.credit) ? A.money(r.credit, false) : '' },
        { label: 'Balance', align: 'right', render: r => `<span class="strong">${A.money(r.balance, false)}</span>` },
      ],
      onLoad: res => {
        const s = res.summary;
        const card = (l, v, tone) => `<div class="stat-card" data-tone="${tone}" style="padding:14px 16px"><span class="stat-label">${l}</span><div class="stat-value" style="font-size:18px">${A.money(v)}</div></div>`;
        A.$('[data-l-summary]').innerHTML = card('Opening balance', s.opening, 'navy') + card('Debits', s.debits, 'teal') + card('Credits', s.credits, 'amber') + card('Closing balance', s.closing, 'green');
      },
    });
  }

  document.addEventListener('click', e => {
    const ed = e.target.closest('[data-edit]');
    if (ed) { e.stopPropagation(); A.openForm('accountModal', accounts.find(a => String(a.id) === ed.dataset.edit)); return; }
    const row = e.target.closest('[data-acc]');
    if (row) openLedger(row.dataset.acc);
  });
  A.$('#coaAsAt').addEventListener('change', load);
  document.addEventListener('app:saved', load);
  load().then(() => { const o = new URLSearchParams(location.search).get('open'); if (o) openLedger(o); });
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
