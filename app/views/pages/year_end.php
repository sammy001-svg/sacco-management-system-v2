<?php
defined('SACCO') or exit;
$canClose = Auth::can('accounting.close');
?>
<div class="page-head">
  <div>
    <div class="breadcrumb"><a href="index.php">Dashboard</a><i class="bi bi-chevron-right"></i><span>Accounting</span><i class="bi bi-chevron-right"></i><span>Year-end close</span></div>
    <h1>Year-end close</h1>
    <p>Closing a year moves its surplus to retained earnings and locks it against further postings.</p>
  </div>
</div>

<div class="info-strip mb-3" data-lock><i class="bi bi-unlock"></i><span>Loading…</span></div>

<section class="card">
  <div class="card-body flush"><div class="table-wrap"><table class="table">
    <thead><tr><th>Financial year</th><th>Status</th><th class="num">Income</th><th class="num">Expenses</th><th class="num">Surplus / (deficit)</th><th class="hide-sm">Closed</th><th></th></tr></thead>
    <tbody data-years><tr><td colspan="7"><span class="skeleton"></span></td></tr></tbody>
  </table></div></div>
</section>

<section class="card mt-3">
  <div class="card-head"><h3><span class="h-icon"><i class="bi bi-list-check"></i></span>Before you close</h3></div>
  <div class="card-body"><ol class="text-sm" style="margin:0;padding-left:18px;line-height:2">
    <li>Post every transaction, expense and adjusting journal dated in the year.</li>
    <li>Run and post <a href="index.php?page=distributions">savings interest</a> for the year if it is paid.</li>
    <li>Check that every line on <a href="index.php?page=statements">Financial statements → Reconciliation</a> agrees.</li>
    <li>Print the trial balance, income statement and balance sheet as at 31 December for the auditors.</li>
    <li>Close the year. Dividends approved at the AGM are posted afterwards against retained earnings.</li>
  </ol></div>
</section>

<?php ob_start(); ?>
<script>
(function () {
  const A = App, canClose = <?= $canClose ? 'true' : 'false' ?>;
  let years = [];
  async function load() {
    let res;
    try { res = await A.get('accounting/years'); } catch (e) { return A.toast(e.message, 'error'); }
    years = res.data;
    const lock = A.$('[data-lock]');
    lock.className = 'info-strip mb-3' + (res.locked_through ? '' : ' warning');
    lock.innerHTML = res.locked_through
      ? `<i class="bi bi-lock"></i><span>Books are closed through <strong>${A.date(res.locked_through)}</strong>. Nothing can be posted on or before that date.</span>`
      : '<i class="bi bi-unlock"></i><span>No financial year has been closed yet. Every date is open for posting.</span>';
    const latestClosed = Math.max(0, ...years.filter(y => y.status === 'closed').map(y => +y.year));
    const oldestOpenEnded = Math.min(...years.filter(y => y.status !== 'closed' && y.ended).map(y => +y.year), 9999);
    A.$('[data-years]').innerHTML = years.map(y => {
      const s = +y.surplus;
      let act = '';
      if (canClose && y.status !== 'closed' && y.ended && +y.year === oldestOpenEnded) act = `<button class="btn btn-primary btn-sm" data-close-year="${y.year}"><i class="bi bi-lock"></i><span>Close ${y.year}</span></button>`;
      if (canClose && y.status === 'closed' && +y.year === latestClosed) act = `<button class="btn btn-sm" data-reopen-year="${y.year}"><i class="bi bi-unlock"></i><span>Reopen</span></button>`;
      return `<tr><td class="strong">FY ${y.year}<div class="text-xs muted">1 Jan – 31 Dec ${y.year}</div></td>
        <td>${y.status === 'closed' ? '<span class="badge info">Closed</span>' : y.ended ? '<span class="badge warning">Ended — open</span>' : '<span class="badge success">Current</span>'}</td>
        <td class="num">${A.money(y.income, false)}</td><td class="num">${A.money(y.expenses, false)}</td>
        <td class="num strong ${s >= 0 ? 'text-green' : 'text-red'}">${A.money(s, false)}</td>
        <td class="hide-sm text-sm">${y.closed_at ? A.date(y.closed_at) : '—'}</td><td class="num">${act}</td></tr>`;
    }).join('');
  }
  document.addEventListener('click', async e => {
    const c = e.target.closest('[data-close-year]'), r = e.target.closest('[data-reopen-year]');
    if (!c && !r) return;
    const year = (c || r).dataset[c ? 'closeYear' : 'reopenYear'];
    const y = years.find(x => String(x.year) === year);
    const ok = await A.confirm(c
      ? { title: `Close financial year ${year}?`, message: `${+y.surplus >= 0 ? 'Surplus' : 'Deficit'} of ${A.money(Math.abs(y.surplus))} moves to retained earnings and postings dated in ${year} are locked.`, confirmText: `Close ${year}`, tone: 'warning', icon: 'lock' }
      : { title: `Reopen ${year}?`, message: 'The closing entry is reversed and postings into the year are allowed again.', confirmText: `Reopen ${year}`, tone: 'warning', icon: 'unlock' });
    if (!ok) return;
    try { A.toast((await A.post(c ? 'accounting/year_close' : 'accounting/year_reopen', { year })).message); load(); }
    catch (err) { A.toast(err.message, 'error'); }
  });
  load();
})();
</script>
<?php $pageScripts = ob_get_clean(); ?>
