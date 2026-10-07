<?php
defined('SACCO') or exit;

function txn_filters(): array
{
    $where = 'WHERE 1=1';
    $params = [];
    [$bs, $bp] = branch_scope('t.branch_id');
    $where .= $bs;
    $params = [...$params, ...$bp];

    $q = trim((string) input('search', ''));
    if ($q !== '') {
        if (preg_match('/^TX\d+$/i', $q)) {
            $where .= ' AND t.txn_ref LIKE ?';
            $params[] = strtoupper($q) . '%';
        } else {
            // Resolve matching members first so the ledger is searched through
            // its (member_id, created_at) and payment_ref indexes only.
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
            $ids = array_map('intval', array_column(DB::all(
                'SELECT id FROM members WHERE member_no LIKE ? OR national_id LIKE ? OR phone LIKE ? LIMIT 200',
                [$like, $like, $like]
            ), 'id'));
            if ($ids) {
                $where .= ' AND (t.payment_ref LIKE ? OR t.member_id IN (' . implode(',', $ids) . '))';
            } else {
                $where .= ' AND t.payment_ref LIKE ?';
            }
            $params[] = $like;
        }
    }
    if (array_key_exists($type = (string) input('type', ''), Ledger::TYPES)) {
        $where .= ' AND t.type = ?';
        $params[] = $type;
    }
    if (array_key_exists($method = (string) input('payment_method', ''), Ledger::METHODS)) {
        $where .= ' AND t.payment_method = ?';
        $params[] = $method;
    }
    if (in_array($flow = (string) input('flow', ''), ['in', 'out', 'none'], true)) {
        $where .= ' AND t.flow = ?';
        $params[] = $flow;
    }
    if (in_array($acct = (string) input('account_type', ''), ['savings', 'shares', 'loan', 'income', 'expense'], true)) {
        $where .= ' AND t.account_type = ?';
        $params[] = $acct;
        if ($aid = input_int('account_id')) {
            $where .= ' AND t.account_id = ?';
            $params[] = $aid;
        }
    }
    if ($mid = input_int('member_id')) {
        $where .= ' AND t.member_id = ?';
        $params[] = $mid;
    }
    if ($from = input_date('date_from')) {
        $where .= ' AND t.created_at >= ?';
        $params[] = "$from 00:00:00";
    }
    if ($to = input_date('date_to')) {
        $where .= ' AND t.created_at <= ?';
        $params[] = "$to 23:59:59";
    }
    return [$where, $params];
}

return [

    'list' => function () {
        Auth::authorize(input_int('member_id') ? 'members.view' : 'transactions.view');
        [$where, $params] = txn_filters();
        // Ordering by (created_at, id) walks the date index, so LIMIT stops early.
        $order = sort_clause(['created_at' => 't.created_at', 'amount' => 't.amount'], 't.created_at DESC, t.id DESC');
        $res = paginate(
            't.id, t.txn_ref, t.member_id, t.account_type, t.account_id, t.type, t.amount, t.units, t.balance_after, t.flow,
             t.payment_method, t.payment_ref, t.narration, t.status, t.created_at, t.reversal_of,
             m.member_no, m.first_name, m.last_name, u.name AS user_name',
            "FROM transactions t LEFT JOIN members m ON m.id = t.member_id LEFT JOIN users u ON u.id = t.created_by $where",
            $params, $order, 20, "FROM transactions t $where"
        );
        $canReverse = Auth::can('transactions.reverse');
        foreach ($res['data'] as &$r) {
            $r['reversible'] = $canReverse && Ledger::isReversible($r)
                && ($r['type'] !== 'loan_repayment' || Auth::can('loans.reverse'));
        }
        return $res;
    },

    'summary' => function () {
        Auth::authorize('transactions.view');
        [$where, $params] = txn_filters();
        $rows = DB::all("SELECT t.flow, COUNT(*) AS n, COALESCE(SUM(t.amount),0) AS total FROM transactions t $where AND t.status = 'posted' GROUP BY t.flow", $params);
        $out = ['in' => 0, 'out' => 0, 'none' => 0, 'count' => 0];
        foreach ($rows as $r) {
            $out[$r['flow']] = (float) $r['total'];
            $out['count'] += (int) $r['n'];
        }
        $out['net'] = $out['in'] - $out['out'];
        return ['data' => $out];
    },

    'export' => function () {
        Auth::authorize('transactions.view');
        [$where, $params] = txn_filters();
        export_csv('transactions', [
            'created_at' => 'Date', 'txn_ref' => 'Reference', 'member_no' => 'Member No', 'member' => 'Member',
            'type' => 'Type', 'account_type' => 'Account', 'amount' => 'Amount', 'flow' => 'Cash Flow',
            'balance_after' => 'Balance After', 'payment_method' => 'Method', 'payment_ref' => 'Payment Ref',
            'narration' => 'Narration', 'status' => 'Status', 'user_name' => 'Posted By',
        ], "SELECT t.*, m.member_no, CONCAT(m.first_name, ' ', m.last_name) AS member, u.name AS user_name
            FROM transactions t LEFT JOIN members m ON m.id = t.member_id LEFT JOIN users u ON u.id = t.created_by
            $where ORDER BY t.id", $params);
    },

    'reverse' => function () {
        post_only();
        Auth::authorize('transactions.reverse');
        $d = Validator::make($_POST, ['id' => 'required|integer', 'reason' => 'required|string|min:3|max:200']);
        $t = DB::row('SELECT * FROM transactions WHERE id = ?', [$d['id']]);
        $own = Auth::user()['branch_id'] ?? null;
        if (!$t || ($own && (int) $own !== (int) $t['branch_id'])) {
            throw new AppError('Transaction not found.', 404);
        }
        if ($t['status'] !== 'posted' || !Ledger::isReversible($t)) {
            throw new AppError('This transaction cannot be reversed.');
        }
        if (ApprovalService::needed('reversal')) {
            return ApprovalService::request('reversal', "Reverse {$t['txn_ref']} — " . (Ledger::TYPES[$t['type']] ?? $t['type']) . ' of ' . money($t['amount']) . ": {$d['reason']}",
                ['transaction_id' => (int) $t['id'], 'reason' => $d['reason']], (float) $t['amount'], $t['branch_id'] ? (int) $t['branch_id'] : null, $t['member_id'] ? (int) $t['member_id'] : null);
        }
        $rev = Ledger::reverse($d['id'], $d['reason']);
        audit('reverse', 'transaction', $d['id'], "Reversed {$t['txn_ref']} with {$rev['ref']}: {$d['reason']}");
        return ['message' => "Transaction {$t['txn_ref']} reversed ({$rev['ref']})."];
    },
];
