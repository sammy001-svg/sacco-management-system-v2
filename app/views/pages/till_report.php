<?php
defined('SACCO') or exit;
/** Teller cash report: interim while the till is open, final once it is closed. */
$s = TillService::summary(input_int('id'));
$own = Auth::user()['branch_id'] ?? null;
if ((int) $s['user_id'] !== Auth::id() && (!Auth::can('tills.supervise') || ($own && (int) $own !== (int) $s['branch_id']))) {
    throw new AppError('Till session not found.', 404);
}
$closed = $s['status'] === 'closed';
$until = $s['closed_at'] ?? now();

$byType = DB::all(
    "SELECT type, flow, COUNT(*) AS n, SUM(amount) AS total FROM transactions
     WHERE created_by = ? AND payment_method = 'cash' AND flow IN ('in','out') AND created_at >= ? AND created_at <= ?
     GROUP BY type, flow ORDER BY flow, type",
    [$s['user_id'], $s['opened_at'], $until]
);
$txns = DB::all(
    "SELECT t.created_at, t.txn_ref, t.type, t.flow, t.amount, t.status, m.member_no, m.first_name, m.last_name
     FROM transactions t LEFT JOIN members m ON m.id = t.member_id
     WHERE t.created_by = ? AND t.payment_method = 'cash' AND t.flow IN ('in','out') AND t.created_at >= ? AND t.created_at <= ?
     ORDER BY t.id LIMIT 2000",
    [$s['user_id'], $s['opened_at'], $until]
);
$other = DB::row(
    "SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS total FROM transactions
     WHERE created_by = ? AND (payment_method <> 'cash' OR flow = 'none') AND created_at >= ? AND created_at <= ?",
    [$s['user_id'], $s['opened_at'], $until]
);
$moves = DB::all(
    "SELECT m.*, a.name AS approved_by_name FROM cash_movements m LEFT JOIN users a ON a.id = m.approved_by
     WHERE m.till_session_id = ? AND m.status = 'completed' ORDER BY m.id",
    [$s['id']]
);
$denoms = $s['denominations'] ?: [];
krsort($denoms);
$variance = $closed ? (float) $s['variance'] : null;
audit('till_report', 'till', $s['id'], "Cash report for till #{$s['id']} ({$s['user_name']})");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Till report · <?= e($s['user_name']) ?></title>
  <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
  <style>
    :root { --ink: #0b1b3f; --muted: #5b6a86; --line: #d5dde9; --green: #047857; --red: #e11d48; --amber: #c2410c; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #e9eef5; color: var(--ink); font: 13px/1.5 "Segoe UI", system-ui, -apple-system, sans-serif; }
    .bar { display: flex; gap: 8px; justify-content: center; padding: 12px 16px; flex-wrap: wrap; }
    .bar button { font: 600 13px system-ui, sans-serif; padding: 8px 14px; border-radius: 10px; border: 1px solid var(--line); background: #fff; color: var(--ink); cursor: pointer; }
    .bar .primary { background: #059669; border-color: #059669; color: #fff; }
    .paper { background: #fff; width: 210mm; max-width: 100%; margin: 0 auto 24px; padding: 14mm 14mm 12mm; box-shadow: 0 10px 30px -12px rgba(10, 31, 77, .3); }
    header { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid var(--ink); padding-bottom: 10px; margin-bottom: 14px; flex-wrap: wrap; }
    header h1 { margin: 0; font-size: 20px; }
    header h2 { margin: 2px 0 0; font-size: 14px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; }
    .status { display: inline-block; font-weight: 800; font-size: 11px; letter-spacing: .1em; padding: 3px 10px; border-radius: 4px; border: 1.5px solid; }
    .status.final { color: var(--green); } .status.interim { color: var(--amber); }
    .meta { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px 16px; margin-bottom: 16px; }
    .meta span { display: block; font-size: 10.5px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); font-weight: 700; }
    .meta strong { font-size: 13px; }
    h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; margin: 18px 0 6px; color: var(--muted); }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 5px 6px; border-bottom: 1px solid var(--line); text-align: left; }
    th { font-size: 10.5px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
    .r { text-align: right; font-variant-numeric: tabular-nums; }
    .mono { font-family: ui-monospace, Consolas, monospace; font-size: 12px; }
    .cols { display: grid; grid-template-columns: 1.1fr .9fr; gap: 22px; }
    .recon td { border-bottom: 1px dashed var(--line); }
    .recon tr.total td { border-top: 2px solid var(--ink); border-bottom: 2px solid var(--ink); font-weight: 800; font-size: 14px; }
    .neg { color: var(--red); } .pos { color: var(--green); } .warn { color: var(--amber); }
    .struck td { text-decoration: line-through; color: var(--muted); }
    .sig { display: grid; grid-template-columns: repeat(3, 1fr); gap: 26px; margin-top: 46px; }
    .sig div { border-top: 1px solid var(--ink); padding-top: 4px; font-size: 11.5px; color: var(--muted); }
    .sig strong { display: block; color: var(--ink); font-size: 12px; }
    footer { margin-top: 18px; color: var(--muted); font-size: 11px; text-align: center; }
    @media (max-width: 760px) { .meta { grid-template-columns: repeat(2, minmax(0, 1fr)); } .cols { grid-template-columns: minmax(0, 1fr); } .paper { padding: 16px; } }
    @media print { body { background: #fff; } .bar { display: none; } .paper { box-shadow: none; margin: 0; width: auto; padding: 0; } @page { size: A4; margin: 12mm; } tr { break-inside: avoid; } }
  </style>
</head>
<body>
  <div class="bar"><button class="primary" onclick="window.print()">Print / Save PDF</button><button onclick="window.close()">Close</button></div>
  <div class="paper">
    <header>
      <div><h1><?= e(setting('sacco_name', config('app_name'))) ?></h1><h2>Teller cash report</h2></div>
      <div style="text-align:right"><span class="status <?= $closed ? 'final' : 'interim' ?>"><?= $closed ? 'FINAL — TILL CLOSED' : 'INTERIM — TILL OPEN' ?></span><div class="mono" style="margin-top:6px">Session #<?= (int) $s['id'] ?></div></div>
    </header>

    <div class="meta">
      <div><span>Teller</span><strong><?= e($s['user_name']) ?></strong></div>
      <div><span>Branch</span><strong><?= e($s['branch_name'] ?? 'Head office') ?></strong></div>
      <div><span>Opened</span><strong><?= e(date('d M Y H:i', strtotime($s['opened_at']))) ?></strong></div>
      <div><span><?= $closed ? 'Closed' : 'As at' ?></span><strong><?= e(date('d M Y H:i', strtotime($until))) ?></strong></div>
    </div>

    <div class="cols">
      <div>
        <h3>Cash reconciliation</h3>
        <table class="recon">
          <tr><td>Opening float (from vault)</td><td class="r"><?= e(money($s['opening_float'])) ?></td></tr>
          <tr><td>Add: top-ups from vault</td><td class="r"><?= e(money($s['top_ups'])) ?></td></tr>
          <tr><td>Add: cash received (<?= (int) $s['count_in'] ?>)</td><td class="r"><?= e(money($s['cash_in'])) ?></td></tr>
          <tr><td>Less: cash paid out (<?= (int) $s['count_out'] ?>)</td><td class="r">(<?= e(money($s['cash_out'])) ?>)</td></tr>
          <tr><td>Less: cash drops to vault</td><td class="r">(<?= e(money($s['drops'])) ?>)</td></tr>
          <tr class="total"><td>Expected cash in drawer</td><td class="r"><?= e(money($closed ? $s['expected_cash'] : $s['expected_now'])) ?></td></tr>
          <?php if ($closed): ?>
          <tr><td>Physical count (surrendered to vault)</td><td class="r"><?= e(money($s['counted_cash'])) ?></td></tr>
          <tr><td><strong><?= $variance == 0.0 ? 'Balanced' : ($variance < 0 ? 'Shortage' : 'Overage') ?></strong></td><td class="r <?= $variance == 0.0 ? 'pos' : ($variance < 0 ? 'neg' : 'warn') ?>"><strong><?= e(money(abs($variance))) ?></strong></td></tr>
          <?php endif; ?>
        </table>
        <?php if ($s['notes']): ?><p style="margin:8px 0 0"><strong>Notes:</strong> <?= e($s['notes']) ?></p><?php endif; ?>
      </div>
      <div>
        <h3>By transaction type</h3>
        <table>
          <tr><th>Type</th><th class="r">No.</th><th class="r">Amount</th></tr>
          <?php foreach ($byType as $r): ?><tr><td><?= e(Ledger::TYPES[$r['type']] ?? $r['type']) ?> <span class="<?= $r['flow'] === 'in' ? 'pos' : 'neg' ?>"><?= $r['flow'] === 'in' ? 'in' : 'out' ?></span></td><td class="r"><?= (int) $r['n'] ?></td><td class="r"><?= e(money($r['total'], false)) ?></td></tr><?php endforeach; ?>
          <?php if (!$byType): ?><tr><td colspan="3" style="color:var(--muted)">No cash transactions.</td></tr><?php endif; ?>
          <?php if ((int) $other['n']): ?><tr><td colspan="3" style="color:var(--muted);font-size:11.5px">Plus <?= (int) $other['n'] ?> non-cash transaction(s) of <?= e(money($other['total'])) ?> — not part of the drawer.</td></tr><?php endif; ?>
        </table>
        <?php if ($denoms): ?>
        <h3>Closing count by denomination</h3>
        <table>
          <tr><th>Note / coin</th><th class="r">Qty</th><th class="r">Value</th></tr>
          <?php foreach ($denoms as $d => $q): ?><tr><td><?= number_format((int) $d) ?></td><td class="r"><?= (int) $q ?></td><td class="r"><?= e(money($d * $q, false)) ?></td></tr><?php endforeach; ?>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <h3>Vault movements</h3>
    <table>
      <tr><th>Time</th><th>Movement</th><th>Released / received by</th><th class="r">Amount</th></tr>
      <tr><td class="mono"><?= e(date('H:i', strtotime($s['opened_at']))) ?></td><td>Opening float</td><td>—</td><td class="r"><?= e(money($s['opening_float'], false)) ?></td></tr>
      <?php foreach ($moves as $m): if ($m['type'] === 'float') { continue; } ?>
        <tr><td class="mono"><?= e(date('H:i', strtotime($m['completed_at'] ?? $m['created_at']))) ?></td><td><?= e(VaultService::TYPES[$m['type']] ?? $m['type']) ?></td><td><?= e($m['approved_by_name'] ?? '—') ?></td><td class="r"><?= e(money($m['amount'], false)) ?></td></tr>
      <?php endforeach; ?>
    </table>

    <h3>Cash transactions (<?= count($txns) ?>)</h3>
    <table>
      <tr><th>Time</th><th>Receipt</th><th>Member</th><th>Type</th><th class="r">In</th><th class="r">Out</th></tr>
      <?php foreach ($txns as $t): ?>
        <tr class="<?= $t['status'] === 'reversed' ? 'struck' : '' ?>"><td class="mono"><?= e(date('H:i', strtotime($t['created_at']))) ?></td><td class="mono"><?= e($t['txn_ref']) ?></td><td><?= e($t['member_no'] ? "{$t['member_no']} · {$t['first_name']} {$t['last_name']}" : '—') ?></td><td><?= e(Ledger::TYPES[$t['type']] ?? $t['type']) ?></td>
          <td class="r"><?= $t['flow'] === 'in' ? e(money($t['amount'], false)) : '' ?></td><td class="r"><?= $t['flow'] === 'out' ? e(money($t['amount'], false)) : '' ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$txns): ?><tr><td colspan="6" style="color:var(--muted)">No cash transactions in this session.</td></tr><?php endif; ?>
      <tr><td colspan="4"><strong>Totals</strong></td><td class="r"><strong><?= e(money($s['cash_in'], false)) ?></strong></td><td class="r"><strong><?= e(money($s['cash_out'], false)) ?></strong></td></tr>
    </table>

    <div class="sig">
      <div><strong><?= e($s['user_name']) ?></strong>Teller — counted and surrendered</div>
      <div><strong>&nbsp;</strong>Vault custodian — received</div>
      <div><strong>&nbsp;</strong>Supervisor — verified</div>
    </div>
    <footer>Generated <?= e(date('d M Y, H:i')) ?> by <?= e(Auth::user()['name'] ?? '') ?> · <?= e(config('app_name')) ?></footer>
  </div>
</body>
</html>
