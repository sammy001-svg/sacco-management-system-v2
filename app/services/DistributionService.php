<?php
defined('SACCO') or exit;

/**
 * Savings interest and share dividend runs.
 *
 * A run is computed in chunks (compute()) into reviewable draft lines, then
 * posted in chunks (post()) as credits to members' savings accounts. Both
 * steps are resumable, so even a very large SACCO is processed safely across
 * many short requests.
 *
 * Basis methods:
 *   average – average daily balance over the period
 *   closing – balance at the end of the period
 */
final class DistributionService
{
    public const KINDS = ['savings_interest' => 'Savings interest', 'dividend' => 'Dividend on shares'];
    public const CHUNK = 1000;

    public static function create(array $d): int
    {
        if ($d['period_from'] > $d['period_to']) {
            throw new ValidationError(['period_to' => 'The period end must be after the start.']);
        }
        if ($d['period_to'] > today()) {
            throw new ValidationError(['period_to' => 'The period cannot end in the future.']);
        }
        if ($d['kind'] === 'dividend' && !((float) ($d['rate'] ?? 0) > 0)) {
            throw new ValidationError(['rate' => 'Enter the dividend rate.']);
        }
        Accounting::assertOpen($d['posting_date']);
        if (DB::value("SELECT 1 FROM distribution_runs WHERE status IN ('computing','posting') LIMIT 1")) {
            throw new AppError('Another run is still being processed. Finish it first.');
        }
        return DB::insert('distribution_runs', [
            'kind'         => $d['kind'],
            'title'        => $d['title'],
            'period_from'  => $d['period_from'],
            'period_to'    => $d['period_to'],
            'method'       => $d['method'],
            'rate'         => $d['kind'] === 'dividend' ? $d['rate'] : null,
            'product_id'   => $d['kind'] === 'savings_interest' ? ($d['product_id'] ?: null) : null,
            'wht_rate'     => $d['wht_rate'] ?? 0,
            'posting_date' => $d['posting_date'],
            'status'       => 'computing',
            'created_by'   => Auth::id(),
        ]);
    }

    public static function find(int $id): array
    {
        $r = DB::row('SELECT * FROM distribution_runs WHERE id = ?', [$id]);
        if (!$r) {
            throw new AppError('Run not found.', 404);
        }
        return $r;
    }

    /** Compute the next chunk of accounts. Returns the updated run. */
    public static function compute(int $runId): array
    {
        return DB::transaction(function () use ($runId) {
            $run = DB::row('SELECT * FROM distribution_runs WHERE id = ? FOR UPDATE', [$runId]);
            if (!$run || $run['status'] !== 'computing') {
                return $run ?? self::find($runId);
            }
            $from = $run['period_from'];
            $to = $run['period_to'];
            $days = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;

            if ($run['kind'] === 'savings_interest') {
                $params = [(int) $run['cursor_id']];
                $extra = '';
                if ($run['product_id']) {
                    $extra = ' AND a.product_id = ?';
                    $params[] = (int) $run['product_id'];
                }
                $accounts = DB::all(
                    "SELECT a.id, a.member_id, a.id AS credit_id, p.interest_rate AS rate
                     FROM savings_accounts a JOIN savings_products p ON p.id = a.product_id JOIN members m ON m.id = a.member_id
                     WHERE a.id > ? AND a.status <> 'closed' AND m.status <> 'exited' AND p.interest_rate > 0 $extra
                     ORDER BY a.id LIMIT " . self::CHUNK,
                    $params
                );
                $type = 'savings';
            } else {
                $accounts = DB::all(
                    "SELECT s.id, s.member_id FROM share_accounts s JOIN members m ON m.id = s.member_id
                     WHERE s.id > ? AND m.status <> 'exited' ORDER BY s.id LIMIT " . self::CHUNK,
                    [(int) $run['cursor_id']]
                );
                $credit = self::defaultSavingsAccounts(array_column($accounts, 'member_id'));
                foreach ($accounts as &$a) {
                    $a['rate'] = $run['rate'];
                    $a['credit_id'] = $credit[(int) $a['member_id']] ?? null;
                }
                unset($a);
                $type = 'shares';
            }

            $basis = self::balances($type, array_map('intval', array_column($accounts, 'id')), $from, $to, $run['method']);
            $wht = (float) $run['wht_rate'];
            $values = [];
            $params = [];
            $tot = ['lines' => 0, 'gross' => 0.0, 'tax' => 0.0, 'net' => 0.0];
            foreach ($accounts as $a) {
                $b = round($basis[(int) $a['id']] ?? 0, 2);
                if ($b <= 0) {
                    continue;
                }
                $rate = (float) $a['rate'];
                $gross = $run['kind'] === 'savings_interest'
                    ? round($b * $rate / 100 * $days / 365, 2)   // annual rate, pro-rated
                    : round($b * $rate / 100, 2);                // dividend rate for the period
                if ($gross < 0.01) {
                    continue;
                }
                $tax = round($gross * $wht / 100, 2);
                $net = round($gross - $tax, 2);
                $values[] = '(?,?,?,?,?,?,?,?,?)';
                array_push($params, $runId, $a['member_id'], $a['id'], $a['credit_id'], $b, $rate, $gross, $tax, $net);
                $tot['lines']++;
                $tot['gross'] += $gross;
                $tot['tax'] += $tax;
                $tot['net'] += $net;
            }
            if ($values) {
                DB::query('INSERT INTO distribution_lines (run_id, member_id, source_account_id, credit_account_id, basis, rate, gross, tax, net) VALUES ' . implode(',', $values), $params);
            }
            $done = count($accounts) < self::CHUNK;
            DB::query(
                'UPDATE distribution_runs SET cursor_id = ?, scanned = scanned + ?, line_count = line_count + ?, gross = gross + ?, tax = tax + ?, net = net + ?, status = ? WHERE id = ?',
                [$accounts ? (int) end($accounts)['id'] : (int) $run['cursor_id'], count($accounts), $tot['lines'], round($tot['gross'], 2), round($tot['tax'], 2), round($tot['net'], 2), $done ? 'draft' : 'computing', $runId]
            );
            return self::find($runId);
        });
    }

    /** Post the next chunk of pending lines. Returns the updated run. */
    public static function post(int $runId): array
    {
        return DB::transaction(function () use ($runId) {
            $run = DB::row('SELECT * FROM distribution_runs WHERE id = ? FOR UPDATE', [$runId]);
            if (!$run || !in_array($run['status'], ['draft', 'posting'], true)) {
                return $run ?? self::find($runId);
            }
            Accounting::assertOpen($run['posting_date']);
            $type = $run['kind'] === 'savings_interest' ? 'interest' : 'dividend';
            $at = $run['posting_date'] === today() ? now() : $run['posting_date'] . ' 23:00:00';
            $lines = DB::all(
                "SELECT l.*, a.status AS account_status FROM distribution_lines l LEFT JOIN savings_accounts a ON a.id = l.credit_account_id
                 WHERE l.run_id = ? AND l.status = 'pending' ORDER BY l.id LIMIT 500",
                [$runId]
            );
            $posted = $skipped = 0;
            foreach ($lines as $l) {
                if (!$l['credit_account_id'] || $l['account_status'] !== 'active') {
                    DB::query("UPDATE distribution_lines SET status = 'skipped' WHERE id = ?", [$l['id']]);
                    $skipped++;
                    continue;
                }
                $r = SavingsService::deposit((int) $l['credit_account_id'], (float) $l['net'], [
                    'type' => $type, 'flow' => 'none', 'payment_method' => 'internal', 'payment_ref' => 'RUN' . $runId,
                    'narration' => $run['title'] . ' (' . fmt_date($run['period_from']) . ' – ' . fmt_date($run['period_to']) . ')'
                        . ((float) $l['tax'] > 0 ? ' · WHT ' . money($l['tax']) : ''),
                    'at' => $at, 'gl' => ['gross' => (float) $l['gross'], 'tax' => (float) $l['tax']],
                ]);
                DB::query("UPDATE distribution_lines SET status = 'posted', transaction_id = ? WHERE id = ?", [$r['transaction']['id'], $l['id']]);
                $posted++;
            }
            $remaining = (int) DB::value("SELECT COUNT(*) FROM distribution_lines WHERE run_id = ? AND status = 'pending'", [$runId]);
            DB::update('distribution_runs', [
                'posted_count'  => (int) $run['posted_count'] + $posted,
                'skipped_count' => (int) $run['skipped_count'] + $skipped,
                'status'        => $remaining ? 'posting' : 'posted',
                'posted_by'     => Auth::id(),
                'posted_at'     => $remaining ? null : now(),
            ], 'id = ?', [$runId]);
            return self::find($runId);
        });
    }

    public static function delete(int $runId): void
    {
        $run = self::find($runId);
        if (!in_array($run['status'], ['computing', 'draft'], true)) {
            throw new AppError('Only draft runs can be deleted. Posted credits are corrected by reversing them.');
        }
        DB::query('DELETE FROM distribution_runs WHERE id = ?', [$runId]);
    }

    /** First active savings account per member (default product first). */
    private static function defaultSavingsAccounts(array $memberIds): array
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if (!$memberIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($memberIds), '?'));
        $out = [];
        foreach (DB::all(
            "SELECT a.member_id, a.id FROM savings_accounts a JOIN savings_products p ON p.id = a.product_id
             WHERE a.member_id IN ($in) AND a.status = 'active' ORDER BY p.is_default DESC, a.id",
            $memberIds
        ) as $r) {
            $out[(int) $r['member_id']] ??= (int) $r['id'];
        }
        return $out;
    }

    /**
     * Basis balance per account from the ledger's running balances.
     * @return array<int, float>
     */
    public static function balances(string $accountType, array $ids, string $from, string $to, string $method): array
    {
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $start = "$from 00:00:00";
        $end = "$to 23:59:59";

        // Balance going into the period (or at its end for the closing method).
        $cut = $method === 'closing' ? $end : $start;
        $op = $method === 'closing' ? '<=' : '<';
        $opening = DB::pairs(
            "SELECT t.account_id, t.balance_after FROM transactions t
             JOIN (SELECT account_id, MAX(id) AS mid FROM transactions
                   WHERE account_type = ? AND account_id IN ($in) AND created_at $op ? AND balance_after IS NOT NULL
                   GROUP BY account_id) x ON x.mid = t.id",
            [$accountType, ...$ids, $cut]
        );
        if ($method === 'closing') {
            return array_map('floatval', $opening);
        }

        $days = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
        $events = [];
        foreach (DB::all(
            "SELECT account_id, DATE(created_at) AS d, balance_after FROM transactions
             WHERE account_type = ? AND account_id IN ($in) AND created_at BETWEEN ? AND ? AND balance_after IS NOT NULL
             ORDER BY account_id, id",
            [$accountType, ...$ids, $start, $end]
        ) as $e) {
            $events[(int) $e['account_id']][] = $e;
        }

        $out = [];
        $fromTs = strtotime($from);
        $endTs = strtotime($to) + 86400;
        foreach ($ids as $id) {
            $bal = (float) ($opening[$id] ?? 0);
            $cursor = $fromTs;
            $sum = 0.0;
            foreach ($events[$id] ?? [] as $e) {
                $ts = strtotime($e['d']);
                $sum += $bal * (($ts - $cursor) / 86400);
                $bal = (float) $e['balance_after'];
                $cursor = $ts;
            }
            $sum += $bal * (($endTs - $cursor) / 86400);
            $out[$id] = $days > 0 ? $sum / $days : 0.0;
        }
        return $out;
    }
}
