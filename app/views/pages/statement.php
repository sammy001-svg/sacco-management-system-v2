<?php
defined('SACCO') or exit;
$m = MemberService::findOrFail(input_int('id'));
$from = input_date('from', date('Y-01-01'));
$to = input_date('to', today());
$name = member_name($m);

$rows = DB::all(
    "SELECT t.created_at, t.txn_ref, t.type, t.account_type, t.amount, t.balance_after, t.narration, t.payment_method, t.status
     FROM transactions t WHERE t.member_id = ? AND t.created_at BETWEEN ? AND ? ORDER BY t.id LIMIT 5000",
    [$m['id'], "$from 00:00:00", "$to 23:59:59"]
);
$savings = (float) DB::value('SELECT COALESCE(SUM(balance),0) FROM savings_accounts WHERE member_id = ?', [$m['id']]);
$shares = DB::row('SELECT shares, balance FROM share_accounts WHERE member_id = ?', [$m['id']]) ?: ['shares' => 0, 'balance' => 0];
$loans = (float) DB::value("SELECT COALESCE(SUM(balance),0) FROM loans WHERE member_id = ? AND status = 'active'", [$m['id']]);
$credit = ['deposit', 'share_purchase', 'share_transfer_in', 'loan_repayment'];
audit('statement', 'member', $m['id'], "Generated statement for {$m['member_no']} ($from to $to)");
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Statement · <?= e($name) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/css/app.css?v=<?= @filemtime(APP_ROOT . '/assets/css/app.css') ?>">
  <style>body{background:var(--bg)} .statement{margin:24px auto;border-radius:20px;box-shadow:var(--shadow)} @media print{.statement{margin:0;box-shadow:none}}</style>
</head>
<body>
  <form class="toolbar no-print" style="max-width:960px;margin:16px auto 0;padding:0 0 0" method="get">
    <input type="hidden" name="page" value="statement"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
    <input class="input" type="date" name="from" value="<?= e($from) ?>" aria-label="From"><input class="input" type="date" name="to" value="<?= e($to) ?>" aria-label="To">
    <button class="btn" type="submit"><i class="bi bi-funnel"></i><span>Apply</span></button>
    <button class="btn btn-primary ml-auto" type="button" onclick="window.print()"><i class="bi bi-printer"></i><span>Print / Save PDF</span></button>
  </form>

  <div class="statement">
    <div class="statement-head">
      <div class="brand"><div class="brand-logo"><i class="bi bi-bank2"></i></div><div><h2 style="font-size:20px"><?= e(setting('sacco_name')) ?></h2><div class="muted text-sm"><?= e(setting('sacco_address', '')) ?> · <?= e(setting('sacco_phone', '')) ?></div></div></div>
      <div class="text-right"><h3>Member statement</h3><div class="muted text-sm"><?= fmt_date($from) ?> — <?= fmt_date($to) ?></div><div class="muted text-xs">Generated <?= date('d M Y, H:i') ?></div></div>
    </div>

    <div class="grid-2 mb-3" style="gap:12px">
      <div class="kv">
        <div><span>Member</span><strong><?= e($name) ?></strong></div>
        <div><span>Member no.</span><strong><?= e($m['member_no']) ?></strong></div>
        <div><span>National ID</span><strong><?= e($m['national_id']) ?></strong></div>
        <div><span>Phone</span><strong><?= e($m['phone']) ?></strong></div>
      </div>
      <div class="kv">
        <div><span>Total savings</span><strong><?= e(money($savings)) ?></strong></div>
        <div><span>Share capital</span><strong><?= e(money($shares['balance'])) ?> (<?= number_format((int) $shares['shares']) ?>)</strong></div>
        <div><span>Loan balance</span><strong><?= e(money($loans)) ?></strong></div>
        <div><span>Branch</span><strong><?= e($m['branch_name']) ?></strong></div>
      </div>
    </div>

    <div class="table-wrap"><table class="table compact">
      <thead><tr><th>Date</th><th>Reference</th><th>Description</th><th>Account</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7"><div class="empty">No transactions in this period.</div></td></tr><?php endif; ?>
      <?php foreach ($rows as $r): $isCredit = in_array($r['type'], $credit, true); ?>
        <tr style="<?= $r['status'] === 'reversed' ? 'text-decoration:line-through;opacity:.6' : '' ?>">
          <td class="nowrap"><?= fmt_date($r['created_at'], 'd M Y') ?></td>
          <td class="mono"><?= e($r['txn_ref']) ?></td>
          <td><?= e(Ledger::TYPES[$r['type']] ?? label($r['type'])) ?><div class="text-xs muted"><?= e($r['narration']) ?></div></td>
          <td><?= e(label($r['account_type'])) ?></td>
          <td class="num"><?= $isCredit ? '' : e(money($r['amount'], false)) ?></td>
          <td class="num"><?= $isCredit ? e(money($r['amount'], false)) : '' ?></td>
          <td class="num strong"><?= $r['balance_after'] !== null ? e(money($r['balance_after'], false)) : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="muted text-xs mt-2">Balances shown are the running balance of the account affected by each entry. Struck-through entries were reversed. This statement is computer generated.</p>
  </div>
</body>
</html>
