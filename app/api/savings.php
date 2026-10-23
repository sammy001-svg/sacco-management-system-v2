<?php
defined('SACCO') or exit;

/** Ensure a savings account belongs to a member within the user's branch scope. */
function savings_account_scoped(int $id): array
{
    $a = DB::row(
        'SELECT a.*, m.branch_id, m.first_name, m.last_name, m.member_no FROM savings_accounts a JOIN members m ON m.id = a.member_id WHERE a.id = ?',
        [$id]
    );
    if (!$a || !MemberService::inScope($a)) {
        throw new ValidationError(['account_id' => 'Select a valid savings account.']);
    }
    return $a;
}

function savings_filters(): array
{
    $where = 'WHERE 1=1';
    $params = [];
    [$bs, $bp] = branch_scope('m.branch_id');
    $where .= $bs;
    $params = [...$params, ...$bp];

    $q = trim((string) input('search', ''));
    if ($q !== '') {
        if (preg_match('/^SV\d+$/i', $q)) {
            $where .= ' AND a.account_no LIKE ?';
            $params[] = strtoupper($q) . '%';
        } else {
            [$ss, $sp] = member_search_clause($q);
            $where .= $ss;
            $params = [...$params, ...$sp];
        }
    }
    if ($pid = input_int('product_id')) {
        $where .= ' AND a.product_id = ?';
        $params[] = $pid;
    }
    $status = (string) input('status', '');
    if (in_array($status, ['active', 'frozen', 'closed'], true)) {
        $where .= ' AND a.status = ?';
        $params[] = $status;
    }
    if ($mid = input_int('member_id')) {
        $where .= ' AND a.member_id = ?';
        $params[] = $mid;
    }
    return [$where, $params];
}

function payment_rules(): array
{
    return [
        'payment_method' => 'required|in:cash,mobile_money,bank,cheque',
        'payment_ref'    => 'nullable|string|max:80',
        'narration'      => 'nullable|string|max:255',
    ];
}

return [

    'list' => function () {
        Auth::authorize('savings.view');
        [$where, $params] = savings_filters();
        $from = "FROM savings_accounts a
                 JOIN members m ON m.id = a.member_id
                 JOIN savings_products p ON p.id = a.product_id $where";
        $order = sort_clause(['balance' => 'a.balance', 'account_no' => 'a.id', 'opened_at' => 'a.opened_at', 'member' => 'm.first_name'], 'a.id DESC');
        return paginate(
            'a.id, a.account_no, a.balance, a.status, a.opened_at, a.member_id, p.name AS product_name, p.allow_withdrawal,
             m.member_no, m.first_name, m.last_name, m.phone',
            $from, $params, $order
        );
    },

    'export' => function () {
        Auth::authorize('savings.view');
        [$where, $params] = savings_filters();
        export_csv('savings-accounts', [
            'account_no' => 'Account No', 'member_no' => 'Member No', 'member' => 'Member', 'product_name' => 'Product',
            'balance' => 'Balance', 'status' => 'Status', 'opened_at' => 'Opened',
        ], "SELECT a.*, m.member_no, CONCAT(m.first_name, ' ', m.last_name) AS member, p.name AS product_name
            FROM savings_accounts a JOIN members m ON m.id = a.member_id JOIN savings_products p ON p.id = a.product_id
            $where ORDER BY a.id", $params);
    },

    'stats' => function () {
        Auth::authorize('savings.view');
        [$bs, $bp] = branch_scope('m.branch_id');
        $join = $bs ? 'JOIN members m ON m.id = a.member_id' : '';
        $totals = DB::row("SELECT COALESCE(SUM(a.balance),0) AS total, COUNT(*) AS accounts FROM savings_accounts a $join WHERE a.status <> 'closed' $bs", $bp);

        [$ts, $tp] = branch_scope('t.branch_id');
        $today = DB::pairs(
            "SELECT t.type, SUM(t.amount) FROM transactions t
             WHERE t.created_at >= ? AND t.status = 'posted' AND t.type IN ('deposit','withdrawal') AND t.payment_method <> 'internal' $ts
             GROUP BY t.type",
            [today() . ' 00:00:00', ...$tp]
        );
        $month = DB::pairs(
            "SELECT t.type, SUM(t.amount) FROM transactions t
             WHERE t.created_at >= ? AND t.status = 'posted' AND t.type IN ('deposit','withdrawal') AND t.payment_method <> 'internal' $ts
             GROUP BY t.type",
            [date('Y-m-01') . ' 00:00:00', ...$tp]
        );
        return ['data' => [
            'total'             => (float) $totals['total'],
            'accounts'          => (int) $totals['accounts'],
            'deposits_today'    => (float) ($today['deposit'] ?? 0),
            'withdrawals_today' => (float) ($today['withdrawal'] ?? 0),
            'deposits_month'    => (float) ($month['deposit'] ?? 0),
            'withdrawals_month' => (float) ($month['withdrawal'] ?? 0),
        ]];
    },

    'deposit' => function () {
        post_only();
        Auth::authorize('savings.transact');
        $d = Validator::make($_POST, ['account_id' => 'required|integer', 'amount' => 'required|numeric|min:1'] + payment_rules(), ['account_id' => 'Account']);
        $acc = savings_account_scoped($d['account_id']);
        guard_duplicate('deposit', 'savings', $d['account_id'], $d['amount']);
        $r = SavingsService::deposit($d['account_id'], $d['amount'], $d);
        audit('deposit', 'savings_account', $d['account_id'], "Deposit {$r['transaction']['ref']} of " . money($d['amount']) . " to {$acc['account_no']}");
        return [
            'message' => 'Deposit of ' . money($d['amount']) . ' received. New balance ' . money($r['balance']) . '.',
            'ref'     => $r['transaction']['ref'],
            'receipt' => ['ref' => $r['transaction']['ref'], 'amount' => $d['amount'], 'balance' => $r['balance'], 'member' => "{$acc['first_name']} {$acc['last_name']}", 'account' => $acc['account_no'], 'type' => 'Deposit', 'id' => $r['transaction']['id']],
        ];
    },

    'withdraw' => function () {
        post_only();
        Auth::authorize('savings.transact');
        $d = Validator::make($_POST, ['account_id' => 'required|integer', 'amount' => 'required|numeric|min:1'] + payment_rules(), ['account_id' => 'Account']);
        $acc = savings_account_scoped($d['account_id']);
        // Counter control: the teller confirms they checked the member's ID and signature.
        if ((string) setting('require_id_check', '1') === '1' && !input('id_verified')) {
            throw new ValidationError(['id_verified' => 'Confirm that you have checked the member\'s ID and signature.']);
        }
        guard_duplicate('withdrawal', 'savings', $d['account_id'], $d['amount']);
        if (ApprovalService::needed('withdrawal', $d['amount'])) {
            SavingsService::checkWithdrawable($d['account_id'], $d['amount']);
            return ApprovalService::request('withdrawal', 'Withdraw ' . money($d['amount']) . " from {$acc['account_no']} ({$acc['first_name']} {$acc['last_name']})",
                $d, (float) $d['amount'], (int) $acc['branch_id'], (int) $acc['member_id']);
        }
        $r = SavingsService::withdraw($d['account_id'], $d['amount'], $d);
        audit('withdraw', 'savings_account', $d['account_id'], "Withdrawal {$r['transaction']['ref']} of " . money($d['amount']) . " from {$acc['account_no']}");
        $msg = 'Withdrawal of ' . money($d['amount']) . ' processed.';
        if ($r['fee'] > 0) {
            $msg .= ' Fee: ' . money($r['fee']) . '.';
        }
        return [
            'message' => $msg . ' New balance ' . money($r['balance']) . '.',
            'ref'     => $r['transaction']['ref'],
            'receipt' => ['ref' => $r['transaction']['ref'], 'amount' => $d['amount'], 'balance' => $r['balance'], 'member' => "{$acc['first_name']} {$acc['last_name']}", 'account' => $acc['account_no'], 'type' => 'Withdrawal', 'id' => $r['transaction']['id']],
        ];
    },

    'open' => function () {
        post_only();
        Auth::authorize('savings.transact');
        $d = Validator::make($_POST, ['member_id' => 'required|integer', 'product_id' => 'required|integer|exists:savings_products,id'], ['member_id' => 'Member', 'product_id' => 'Product']);
        $m = MemberService::findOrFail($d['member_id']);
        $p = DB::row('SELECT * FROM savings_products WHERE id = ?', [$d['product_id']]);
        if ($p['status'] !== 'active') {
            throw new ValidationError(['product_id' => 'This product is not active.']);
        }
        if (DB::value("SELECT 1 FROM savings_accounts WHERE member_id = ? AND product_id = ? AND status <> 'closed'", [$m['id'], $p['id']])) {
            throw new ValidationError(['product_id' => "{$m['first_name']} already has a {$p['name']} account."]);
        }
        $id = SavingsService::openAccount((int) $m['id'], (int) $p['id']);
        $no = DB::value('SELECT account_no FROM savings_accounts WHERE id = ?', [$id]);
        audit('create', 'savings_account', $id, "Opened {$p['name']} account $no for {$m['member_no']}");
        return ['message' => "{$p['name']} account $no opened.", 'id' => $id];
    },

    'set_status' => function () {
        post_only();
        Auth::authorize('savings.transact');
        $acc = savings_account_scoped(input_int('id'));
        $status = (string) input('status');
        if (!in_array($status, ['active', 'frozen', 'closed'], true)) {
            throw new AppError('Invalid status.');
        }
        if ($status === 'closed' && (float) $acc['balance'] != 0.0) {
            throw new AppError('Only accounts with a zero balance can be closed. Withdraw or transfer the funds first.');
        }
        DB::update('savings_accounts', ['status' => $status], 'id = ?', [$acc['id']]);
        audit('status', 'savings_account', $acc['id'], "Account {$acc['account_no']} set to $status");
        return ['message' => "Account {$acc['account_no']} is now $status."];
    },

    'products' => function () {
        Auth::authorize('savings.view');
        return ['data' => DB::all(
            "SELECT p.*, COUNT(a.id) AS accounts, COALESCE(SUM(a.balance), 0) AS total_balance
             FROM savings_products p LEFT JOIN savings_accounts a ON a.product_id = p.id AND a.status <> 'closed'
             GROUP BY p.id ORDER BY p.is_default DESC, p.name"
        )];
    },

    'product_save' => function () {
        post_only();
        Auth::authorize('savings.products');
        $id = input_int('id');
        $d = Validator::make($_POST, [
            'code'             => 'required|string|max:20',
            'name'             => 'required|string|max:120',
            'description'      => 'nullable|string|max:255',
            'interest_rate'    => 'required|numeric|min:0|max:100',
            'min_balance'      => 'required|numeric|min:0',
            'withdrawal_fee'   => 'required|numeric|min:0',
            'allow_withdrawal' => 'bool',
            'is_default'       => 'bool',
            'status'           => 'required|in:active,inactive',
        ]);
        $d['code'] = strtoupper($d['code']);
        if (DB::value('SELECT id FROM savings_products WHERE code = ? AND id <> ?', [$d['code'], $id])) {
            throw new ValidationError(['code' => 'This code is already used by another product.']);
        }
        DB::transaction(function () use (&$id, $d) {
            if ($d['is_default']) {
                DB::query('UPDATE savings_products SET is_default = 0');
            }
            if ($id) {
                DB::update('savings_products', $d, 'id = ?', [$id]);
            } else {
                $id = DB::insert('savings_products', $d);
            }
        });
        audit($id ? 'update' : 'create', 'savings_product', $id, "Saved savings product {$d['name']}");
        return ['message' => "Savings product “{$d['name']}” saved."];
    },
];
