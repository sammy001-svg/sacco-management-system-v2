<?php
defined('SACCO') or exit;

/** Head-office users may pick a branch; branch staff are pinned to theirs. */
function gl_branch(): ?int
{
    $own = Auth::user()['branch_id'] ?? null;
    if ($own) {
        return (int) $own;
    }
    return input_int('branch_id') ?: null;
}

function gl_account_or_fail(int $id): array
{
    $a = DB::row('SELECT * FROM gl_accounts WHERE id = ?', [$id]);
    if (!$a) {
        throw new AppError('Account not found.', 404);
    }
    return $a;
}

return [

    /* ---------------- Chart of accounts ---------------- */

    'accounts' => function () {
        Auth::authorize('accounting.view');
        $asAt = input_date('as_at', today());
        $rows = array_values(Accounting::totals(null, $asAt, gl_branch()));
        $totals = array_fill_keys(array_keys(Accounting::TYPES), 0.0);
        foreach ($rows as &$r) {
            $r['control'] = in_array($r['system_key'], Accounting::CONTROL, true);
            $totals[$r['type']] += $r['balance'];
        }
        return ['data' => $rows, 'totals' => array_map(fn($v) => round($v, 2), $totals), 'as_at' => $asAt];
    },

    'account_save' => function () {
        post_only();
        Auth::authorize('accounting.journal');
        $id = input_int('id');
        $d = Validator::make($_POST, [
            'code'        => 'required|string|max:20',
            'name'        => 'required|string|max:120',
            'type'        => 'required|in:' . implode(',', array_keys(Accounting::TYPES)),
            'description' => 'nullable|string|max:255',
            'status'      => 'required|in:active,inactive',
        ]);
        if (DB::value('SELECT id FROM gl_accounts WHERE code = ? AND id <> ?', [$d['code'], $id])) {
            throw new ValidationError(['code' => 'This account code is already used.']);
        }
        if ($id) {
            $a = gl_account_or_fail($id);
            if ($a['system_key']) {
                // System accounts drive automatic posting: keep their type and keep them active.
                $d['type'] = $a['type'];
                $d['status'] = 'active';
            } elseif ($d['type'] !== $a['type'] && DB::value('SELECT 1 FROM journal_lines WHERE account_id = ? LIMIT 1', [$id])) {
                throw new ValidationError(['type' => 'This account already has postings, so its type cannot change.']);
            }
            DB::update('gl_accounts', $d, 'id = ?', [$id]);
        } else {
            $id = DB::insert('gl_accounts', $d);
        }
        audit('save', 'gl_account', $id, "Saved ledger account {$d['code']} {$d['name']}");
        return ['message' => "Account {$d['code']} · {$d['name']} saved."];
    },

    /** Statement of one account with opening balance and running balance. */
    'account_ledger' => function () {
        Auth::authorize('accounting.view');
        $a = gl_account_or_fail(input_int('id'));
        $from = input_date('date_from', date('Y-01-01'));
        $to = input_date('date_to', today());
        $branch = gl_branch();
        $bw = $branch ? ' AND l.branch_id = ?' : '';
        $bp = $branch ? [$branch] : [];
        $sign = in_array($a['type'], ['asset', 'expense'], true) ? 1 : -1;

        $open = DB::row("SELECT COALESCE(SUM(l.debit),0) dr, COALESCE(SUM(l.credit),0) cr FROM journal_lines l WHERE l.account_id = ? AND l.entry_date < ? $bw", [$a['id'], $from, ...$bp]);
        $opening = round($sign * ((float) $open['dr'] - (float) $open['cr']), 2);
        $where = "FROM journal_lines l JOIN journal_entries j ON j.id = l.entry_id WHERE l.account_id = ? AND l.entry_date BETWEEN ? AND ? $bw";
        $params = [$a['id'], $from, $to, ...$bp];

        $res = paginate(
            'l.id, l.entry_date, l.debit, l.credit, l.memo, j.id AS entry_id, j.entry_no, j.source, j.narration, j.reference, j.status',
            $where, $params, 'l.entry_date, l.id', 25,
            "FROM journal_lines l WHERE l.account_id = ? AND l.entry_date BETWEEN ? AND ? $bw"
        );
        // Running balance: opening + everything before this page.
        $offset = ($res['meta']['page'] - 1) * $res['meta']['per_page'];
        $before = $offset ? (float) DB::value(
            "SELECT COALESCE(SUM(x.debit - x.credit),0) FROM (SELECT l.debit, l.credit FROM journal_lines l WHERE l.account_id = ? AND l.entry_date BETWEEN ? AND ? $bw ORDER BY l.entry_date, l.id LIMIT $offset) x",
            $params
        ) : 0.0;
        $running = $opening + $sign * $before;
        foreach ($res['data'] as &$r) {
            $running = round($running + $sign * ((float) $r['debit'] - (float) $r['credit']), 2);
            $r['balance'] = $running;
        }
        unset($r);
        $period = DB::row("SELECT COALESCE(SUM(l.debit),0) dr, COALESCE(SUM(l.credit),0) cr FROM journal_lines l WHERE l.account_id = ? AND l.entry_date BETWEEN ? AND ? $bw", $params);
        $res['account'] = $a;
        $res['summary'] = [
            'opening' => $opening,
            'debits'  => (float) $period['dr'],
            'credits' => (float) $period['cr'],
            'closing' => round($opening + $sign * ((float) $period['dr'] - (float) $period['cr']), 2),
        ];
        return $res;
    },

    /* ---------------- Journals ---------------- */

    'journals' => function () {
        Auth::authorize('accounting.view');
        $where = 'WHERE 1=1';
        $params = [];
        if (array_key_exists($src = (string) input('source', ''), Accounting::SOURCES)) {
            $where .= ' AND j.source = ?';
            $params[] = $src;
        }
        if ($from = input_date('date_from')) {
            $where .= ' AND j.entry_date >= ?';
            $params[] = $from;
        }
        if ($to = input_date('date_to')) {
            $where .= ' AND j.entry_date <= ?';
            $params[] = $to;
        }
        if (($q = trim((string) input('search', ''))) !== '') {
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
            $where .= ' AND (j.entry_no LIKE ? OR j.reference LIKE ?)';
            array_push($params, $like, $like);
        }
        if ($b = gl_branch()) {
            $where .= ' AND j.branch_id = ?';
            $params[] = $b;
        }
        return paginate(
            'j.*, u.name AS user_name',
            "FROM journal_entries j LEFT JOIN users u ON u.id = j.created_by $where",
            $params, 'j.entry_date DESC, j.id DESC', 20, "FROM journal_entries j $where"
        );
    },

    'journal' => function () {
        Auth::authorize('accounting.view');
        $j = DB::row('SELECT j.*, u.name AS user_name, t.txn_ref, t.member_id FROM journal_entries j LEFT JOIN users u ON u.id = j.created_by LEFT JOIN transactions t ON t.id = j.transaction_id WHERE j.id = ?', [input_int('id')]);
        if (!$j) {
            throw new AppError('Journal entry not found.', 404);
        }
        $j['lines'] = DB::all('SELECT l.*, a.code, a.name, a.type FROM journal_lines l JOIN gl_accounts a ON a.id = l.account_id WHERE l.entry_id = ? ORDER BY l.debit DESC, l.id', [$j['id']]);
        $j['reversed_by'] = DB::value('SELECT entry_no FROM journal_entries WHERE reversal_of = ?', [$j['id']]);
        $j['can_reverse'] = Auth::can('accounting.journal') && $j['status'] === 'posted' && in_array($j['source'], ['manual', 'opening'], true) && !$j['reversal_of'];
        return ['data' => $j];
    },

    'journal_save' => function () {
        post_only();
        Auth::authorize('accounting.journal');
        $d = Validator::make($_POST, [
            'entry_date' => 'required|date',
            'narration'  => 'required|string|max:255',
            'reference'  => 'nullable|string|max:80',
            'source'     => 'required|in:manual,opening',
            'branch_id'  => 'nullable|integer|exists:branches,id',
        ], ['entry_date' => 'Date']);
        if ($d['entry_date'] > today()) {
            throw new ValidationError(['entry_date' => 'Journal date cannot be in the future.']);
        }
        if ($own = Auth::user()['branch_id']) {
            $d['branch_id'] = (int) $own;
        }
        $control = DB::pairs('SELECT id, name FROM gl_accounts WHERE system_key IN (' . implode(',', array_fill(0, count(Accounting::CONTROL), '?')) . ')', Accounting::CONTROL);
        $lines = [];
        foreach ((array) ($_POST['lines'] ?? []) as $l) {
            $acc = (int) ($l['account_id'] ?? 0);
            $dr = round((float) ($l['debit'] ?? 0), 2);
            $cr = round((float) ($l['credit'] ?? 0), 2);
            if (!$acc && !$dr && !$cr) {
                continue;
            }
            if (!$acc) {
                throw new ValidationError(['lines' => 'Choose an account on every line.']);
            }
            if (isset($control[$acc])) {
                throw new ValidationError(['lines' => "“{$control[$acc]}” is a control account. It changes only through member transactions."]);
            }
            if (($dr > 0) === ($cr > 0) || $dr < 0 || $cr < 0) {
                throw new ValidationError(['lines' => 'Each line needs either a debit or a credit amount.']);
            }
            if (!DB::value("SELECT 1 FROM gl_accounts WHERE id = ? AND status = 'active'", [$acc])) {
                throw new ValidationError(['lines' => 'One of the accounts is inactive.']);
            }
            $lines[] = [$acc, $dr, $cr, trim((string) ($l['memo'] ?? '')) ?: null];
        }
        if (count($lines) < 2) {
            throw new ValidationError(['lines' => 'Add at least two lines.']);
        }
        $id = DB::transaction(fn() => Accounting::post([
            'date' => $d['entry_date'], 'source' => $d['source'], 'branch_id' => $d['branch_id'],
            'reference' => $d['reference'], 'narration' => $d['narration'],
        ], $lines));
        $no = DB::value('SELECT entry_no FROM journal_entries WHERE id = ?', [$id]);
        audit('journal', 'journal_entry', $id, "Posted manual journal $no: {$d['narration']}");
        return ['message' => "Journal $no posted.", 'id' => $id];
    },

    'journal_reverse' => function () {
        post_only();
        Auth::authorize('accounting.journal');
        $d = Validator::make($_POST, ['id' => 'required|integer', 'reason' => 'required|string|min:3|max:200']);
        $j = DB::row('SELECT * FROM journal_entries WHERE id = ?', [$d['id']]);
        if (!$j || !in_array($j['source'], ['manual', 'opening'], true)) {
            throw new AppError('Only manual journals can be reversed here. Transaction journals are reversed through the transaction.');
        }
        $id = DB::transaction(fn() => Accounting::reverseEntry((int) $j['id'], today(), "Reversal of {$j['entry_no']}: {$d['reason']}"));
        audit('reverse', 'journal_entry', $j['id'], "Reversed journal {$j['entry_no']}: {$d['reason']}");
        return ['message' => "Journal {$j['entry_no']} reversed.", 'id' => $id];
    },

    /* ---------------- Statements ---------------- */

    'trial_balance' => function () {
        Auth::authorize('accounting.view');
        $asAt = input_date('as_at', today());
        if (input('export')) {
            $tb = Accounting::trialBalance($asAt, gl_branch());
            audit('export', 'trial_balance', null, "Exported trial balance as at $asAt");
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="trial-balance-' . $asAt . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Code', 'Account', 'Type', 'Debit', 'Credit']);
            foreach ($tb['rows'] as $r) {
                fputcsv($out, [$r['code'], csv_safe($r['name']), $r['type'], $r['debit'] ?: '', $r['credit'] ?: '']);
            }
            fputcsv($out, ['', 'TOTAL', '', $tb['debit'], $tb['credit']]);
            exit;
        }
        return ['data' => Accounting::trialBalance($asAt, gl_branch()) + ['as_at' => $asAt]];
    },

    'income_statement' => function () {
        Auth::authorize('accounting.view');
        $from = input_date('date_from', date('Y-01-01'));
        $to = input_date('date_to', today());
        return ['data' => Accounting::incomeStatement($from, $to, gl_branch()) + ['from' => $from, 'to' => $to]];
    },

    'balance_sheet' => function () {
        Auth::authorize('accounting.view');
        $asAt = input_date('as_at', today());
        return ['data' => Accounting::balanceSheet($asAt, gl_branch()) + ['as_at' => $asAt]];
    },

    'reconciliation' => function () {
        Auth::authorize('accounting.view');
        return ['data' => Accounting::reconciliation()];
    },

    /* ---------------- Financial years ---------------- */

    'years' => function () {
        Auth::authorize('accounting.view');
        return ['data' => Accounting::years(), 'locked_through' => Accounting::lockedThrough()];
    },

    'year_close' => function () {
        post_only();
        Auth::authorize('accounting.close');
        $year = input_int('year');
        $r = Accounting::closeYear($year);
        audit('year_close', 'financial_year', $year, "Closed financial year $year; surplus " . money($r['surplus']));
        return ['message' => "$year closed. " . ($r['surplus'] >= 0 ? 'Surplus of ' : 'Deficit of ') . money(abs($r['surplus'])) . ' moved to retained earnings.'];
    },

    'year_reopen' => function () {
        post_only();
        Auth::authorize('accounting.close');
        $year = input_int('year');
        Accounting::reopenYear($year);
        audit('year_reopen', 'financial_year', $year, "Reopened financial year $year");
        return ['message' => "$year reopened. Postings into $year are allowed again."];
    },
];
