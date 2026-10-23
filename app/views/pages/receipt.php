<?php
defined('SACCO') or exit;
/**
 * Official counter receipt — 80 mm thermal roll or A5. The first print is the
 * original; every later print is stamped REPRINT so copies can be told apart.
 */
$ref = strtoupper(trim((string) input('ref', '')));
$t = DB::row(
    "SELECT t.*, m.member_no, m.first_name, m.last_name, m.other_names, m.phone, u.name AS user_name, b.name AS branch_name
     FROM transactions t LEFT JOIN members m ON m.id = t.member_id LEFT JOIN users u ON u.id = t.created_by LEFT JOIN branches b ON b.id = t.branch_id
     WHERE t.txn_ref = ?",
    [$ref]
);
$own = Auth::user()['branch_id'] ?? null;
if (!$t || (!Auth::can('transactions.view') && (int) $t['created_by'] !== Auth::id()) || ($own && $t['branch_id'] && (int) $own !== (int) $t['branch_id'])) {
    throw new AppError('Receipt not found.', 404);
}

$account = match ($t['account_type']) {
    'savings' => ($a = DB::row('SELECT a.account_no, p.name FROM savings_accounts a JOIN savings_products p ON p.id = a.product_id WHERE a.id = ?', [$t['account_id']])) ? "{$a['account_no']} · {$a['name']}" : 'Savings',
    'shares'  => 'Share capital' . ($t['units'] ? ' · ' . number_format((int) $t['units']) . ' shares' : ''),
    'loan'    => ($l = DB::row('SELECT loan_no FROM loans WHERE id = ?', [$t['account_id']])) ? "Loan {$l['loan_no']}" : 'Loan',
    default   => ucfirst($t['account_type']),
};
$split = $t['type'] === 'loan_repayment'
    ? DB::row('SELECT principal_paid, interest_paid, penalty_paid FROM loan_repayments WHERE transaction_id = ?', [$t['id']])
    : null;
$fee = $t['type'] === 'withdrawal'
    ? (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type = 'withdrawal_fee' AND account_type = ? AND account_id = ? AND created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 5 SECOND) AND created_by <=> ?",
        [$t['account_type'], $t['account_id'], $t['created_at'], $t['created_at'], $t['created_by']])
    : 0.0;

$printed = (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE entity = 'transaction' AND entity_id = ? AND action = 'receipt_print'", [$t['id']]);
$reprint = $printed > 0;
if (input('print')) {
    audit('receipt_print', 'transaction', $t['id'], ($reprint ? 'Reprinted' : 'Printed') . " receipt {$t['txn_ref']}");
}

$size = input('size') === 'a5' ? 'a5' : 'thermal';
$name = $t['member_id'] ? member_name($t) : '—';
$typeLabel = Ledger::TYPES[$t['type']] ?? ucfirst($t['type']);
$memberSigns = in_array($t['type'], ['withdrawal', 'loan_disbursement'], true);
$balanceLabel = $t['account_type'] === 'loan' ? 'Loan balance' : ($t['account_type'] === 'shares' ? 'Share capital' : 'Account balance');
$rows = array_filter([
    ['Receipt no.', $t['txn_ref'], 'mono'],
    ['Date', date('d M Y, H:i', strtotime($t['created_at']))],
    ['Member', $name],
    ['Member no.', $t['member_no'] ?? '—', 'mono'],
    ['Account', $account],
    ['Transaction', $typeLabel],
    ['Paid by', Ledger::METHODS[$t['payment_method']] ?? $t['payment_method']],
    $t['payment_ref'] ? ['Reference', $t['payment_ref'], 'mono'] : null,
    $t['narration'] ? ['Narration', $t['narration']] : null,
]);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Receipt <?= e($t['txn_ref']) ?></title>
  <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
  <style>
    :root { --ink: #0b1b3f; --muted: #5b6a86; --line: #c9d3e2; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #e9eef5; color: var(--ink); font: 13px/1.45 "Segoe UI", system-ui, -apple-system, sans-serif; }
    .bar { display: flex; gap: 8px; justify-content: center; align-items: center; padding: 12px 16px; flex-wrap: wrap; }
    .bar a, .bar button { font: 600 13px system-ui, sans-serif; padding: 8px 14px; border-radius: 10px; border: 1px solid var(--line); background: #fff; color: var(--ink); text-decoration: none; cursor: pointer; }
    .bar .on { background: #059669; border-color: #059669; color: #fff; }
    .paper { background: #fff; margin: 0 auto 24px; padding: 18px 16px; box-shadow: 0 10px 30px -12px rgba(10, 31, 77, .3); position: relative; overflow: hidden; }
    .thermal .paper { width: 80mm; font-size: 12px; }
    .a5 .paper { width: 148mm; min-height: 200mm; padding: 14mm 12mm; font-size: 13.5px; }
    .head { text-align: center; border-bottom: 1px dashed var(--line); padding-bottom: 10px; margin-bottom: 10px; }
    .head h1 { margin: 0; font-size: 1.35em; letter-spacing: -.01em; }
    .head .sub { color: var(--muted); font-size: .9em; }
    .head .title { margin-top: 8px; display: inline-block; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; font-size: .82em; border: 1.5px solid var(--ink); padding: 3px 10px; border-radius: 4px; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 4px 0; vertical-align: top; }
    td:first-child { color: var(--muted); width: 38%; padding-right: 8px; }
    td:last-child { text-align: right; font-weight: 600; word-break: break-word; }
    .mono { font-family: ui-monospace, Consolas, monospace; }
    .amount { margin: 12px 0 6px; padding: 10px 0; border-top: 2px solid var(--ink); border-bottom: 2px solid var(--ink); display: flex; justify-content: space-between; align-items: baseline; }
    .amount span { font-weight: 700; text-transform: uppercase; font-size: .85em; letter-spacing: .06em; }
    .amount strong { font-size: 1.55em; font-variant-numeric: tabular-nums; }
    .words { font-style: italic; color: var(--muted); font-size: .92em; margin-bottom: 10px; }
    .split td { padding: 2px 0; font-size: .92em; }
    .sig { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-top: 30px; }
    .sig div { border-top: 1px solid var(--ink); padding-top: 4px; font-size: .85em; color: var(--muted); text-align: center; }
    .foot { text-align: center; color: var(--muted); font-size: .85em; margin-top: 16px; border-top: 1px dashed var(--line); padding-top: 10px; }
    .stamp { position: absolute; top: 46%; left: 50%; transform: translate(-50%, -50%) rotate(-24deg); font: 900 3.2em/1 system-ui, sans-serif; letter-spacing: .12em; color: rgba(225, 29, 72, .16); border: 5px solid rgba(225, 29, 72, .16); padding: 6px 16px; border-radius: 10px; pointer-events: none; white-space: nowrap; }
    .reprint-tag { color: #e11d48; font-weight: 800; letter-spacing: .1em; font-size: .8em; }
    .reversed { color: #e11d48; font-weight: 800; text-align: center; margin-bottom: 8px; }
    @media print {
      body { background: #fff; }
      .bar { display: none; }
      .paper { box-shadow: none; margin: 0; }
      .thermal .paper { width: 100%; padding: 2mm; }
    }
  </style>
  <style media="print"><?= $size === 'thermal' ? '@page { size: 80mm auto; margin: 2mm; }' : '@page { size: A5; margin: 8mm; }' ?></style>
</head>
<body class="<?= $size ?>">
  <div class="bar">
    <a href="?page=receipt&ref=<?= e(urlencode($t['txn_ref'])) ?>&size=thermal" class="<?= $size === 'thermal' ? 'on' : '' ?>">80 mm roll</a>
    <a href="?page=receipt&ref=<?= e(urlencode($t['txn_ref'])) ?>&size=a5" class="<?= $size === 'a5' ? 'on' : '' ?>">A5</a>
    <button type="button" onclick="location.href='?page=receipt&ref=<?= e(urlencode($t['txn_ref'])) ?>&size=<?= $size ?>&print=1'">Print</button>
    <button type="button" onclick="window.close()">Close</button>
  </div>

  <div class="paper">
    <?php if ($reprint): ?><div class="stamp">REPRINT</div><?php endif; ?>
    <div class="head">
      <h1><?= e(setting('sacco_name', config('app_name'))) ?></h1>
      <div class="sub"><?= e(implode(' · ', array_filter([setting('sacco_address', ''), setting('sacco_phone', '')]))) ?></div>
      <?php if ($t['branch_name']): ?><div class="sub"><?= e($t['branch_name']) ?> branch</div><?php endif; ?>
      <div class="title"><?= e($typeLabel) ?> receipt</div>
      <?php if ($reprint): ?><div class="reprint-tag">REPRINT · PRINTED <?= $printed ?>× BEFORE</div><?php endif; ?>
    </div>
    <?php if ($t['status'] === 'reversed'): ?><div class="reversed">THIS TRANSACTION WAS REVERSED</div><?php endif; ?>

    <table>
      <?php foreach ($rows as $r): ?><tr><td><?= e($r[0]) ?></td><td class="<?= e($r[2] ?? '') ?>"><?= e($r[1]) ?></td></tr><?php endforeach; ?>
    </table>

    <div class="amount"><span>Amount</span><strong><?= e(money($t['amount'])) ?></strong></div>
    <div class="words"><?= e(currency()) ?> <?= e(amount_in_words((float) $t['amount'])) ?></div>

    <table class="split">
      <?php if ($split): ?>
        <?php if ((float) $split['penalty_paid'] > 0): ?><tr><td>Penalty</td><td><?= e(money($split['penalty_paid'])) ?></td></tr><?php endif; ?>
        <tr><td>Interest</td><td><?= e(money($split['interest_paid'])) ?></td></tr>
        <tr><td>Principal</td><td><?= e(money($split['principal_paid'])) ?></td></tr>
      <?php endif; ?>
      <?php if ($fee > 0): ?><tr><td>Withdrawal fee</td><td><?= e(money($fee)) ?></td></tr><?php endif; ?>
      <?php if ($t['balance_after'] !== null): ?><tr><td><?= e($balanceLabel) ?></td><td><?= e(money($t['balance_after'])) ?></td></tr><?php endif; ?>
      <tr><td>Served by</td><td><?= e($t['user_name'] ?? 'System') ?></td></tr>
    </table>

    <div class="sig">
      <div><?= $memberSigns ? 'Member signature' : 'Depositor signature' ?></div>
      <div>Teller signature &amp; stamp</div>
    </div>

    <div class="foot">
      Keep this receipt for your records.<br>
      Printed <?= e(date('d M Y, H:i')) ?> by <?= e(Auth::user()['name'] ?? '') ?>
    </div>
  </div>

  <?php if (input('print')): ?>
  <script>window.addEventListener('load', () => setTimeout(() => window.print(), 250));</script>
  <?php endif; ?>
</body>
</html>
