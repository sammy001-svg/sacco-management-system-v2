<?php
defined('SACCO') or exit;

function loans_filters(): array
{
    $where = 'WHERE 1=1';
    $params = [];
    [$bs, $bp] = branch_scope('l.branch_id');
    $where .= $bs;
    $params = [...$params, ...$bp];

    $status = (string) input('status', '');
    if ($status === 'arrears') {
        $where .= " AND l.status = 'active' AND EXISTS (SELECT 1 FROM loan_schedules s WHERE s.loan_id = l.id AND s.status <> 'paid' AND s.due_date < ?)";
        $params[] = today();
    } elseif (in_array($status, LoanService::STATUSES, true)) {
        $where .= ' AND l.status = ?';
        $params[] = $status;
    }
    if ($pid = input_int('product_id')) {
        $where .= ' AND l.product_id = ?';
        $params[] = $pid;
    }
    if ($mid = input_int('member_id')) {
        $where .= ' AND l.member_id = ?';
        $params[] = $mid;
    }
    $q = trim((string) input('search', ''));
    if ($q !== '') {
        if (preg_match('/^LN\d+$/i', $q)) {
            $where .= ' AND l.loan_no LIKE ?';
            $params[] = strtoupper($q) . '%';
        } else {
            [$ss, $sp] = member_search_clause($q);
            $where .= $ss;
            $params = [...$params, ...$sp];
        }
    }
    if ($from = input_date('date_from')) {
        $where .= ' AND l.applied_at >= ?';
        $params[] = "$from 00:00:00";
    }
    if ($to = input_date('date_to')) {
        $where .= ' AND l.applied_at <= ?';
        $params[] = "$to 23:59:59";
    }
    return [$where, $params];
}

/** Arrears amount and days past due for a set of loans. */
function loans_arrears(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = DB::all(
        "SELECT loan_id,
                SUM(principal_due + interest_due + penalty_due - principal_paid - interest_paid - penalty_paid) AS arrears,
                DATEDIFF(?, MIN(due_date)) AS days
         FROM loan_schedules WHERE loan_id IN ($in) AND status <> 'paid' AND due_date < ? GROUP BY loan_id",
        [today(), ...$ids, today()]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['loan_id']] = ['arrears' => (float) $r['arrears'], 'days' => (int) $r['days']];
    }
    return $out;
}

function loan_scoped(int $id): array
{
    $l = DB::row(
        'SELECT l.*, p.name AS product_name, p.code AS product_code, p.penalty_rate, p.savings_multiplier,
                m.member_no, m.first_name, m.last_name, m.other_names, m.phone, m.email, b.name AS branch_name,
                ua.name AS approved_by_name, ud.name AS disbursed_by_name, uc.name AS created_by_name, ur.name AS rejected_by_name
         FROM loans l
         JOIN loan_products p ON p.id = l.product_id
         JOIN members m ON m.id = l.member_id
         JOIN branches b ON b.id = l.branch_id
         LEFT JOIN users ua ON ua.id = l.approved_by
         LEFT JOIN users ud ON ud.id = l.disbursed_by
         LEFT JOIN users uc ON uc.id = l.created_by
         LEFT JOIN users ur ON ur.id = l.rejected_by
         WHERE l.id = ?',
        [$id]
    );
    $own = Auth::user()['branch_id'] ?? null;
    if (!$l || ($own && (int) $own !== (int) $l['branch_id'])) {
        throw new AppError('Loan not found.', 404);
    }
    return $l;
}

$productRules = [
    'code'                => 'required|string|max:20',
    'name'                => 'required|string|max:120',
    'description'         => 'nullable|string|max:255',
    'interest_rate'       => 'required|numeric|min:0|max:100',
    'rate_period'         => 'required|in:monthly,annual',
    'interest_method'     => 'required|in:flat,reducing',
    'min_amount'          => 'required|numeric|min:0',
    'max_amount'          => 'required|numeric|min:1',
    'min_term'            => 'required|integer|min:1|max:600',
    'max_term'            => 'required|integer|min:1|max:600',
    'processing_fee_rate' => 'required|numeric|min:0|max:100',
    'penalty_rate'        => 'required|numeric|min:0|max:100',
    'savings_multiplier'  => 'required|numeric|min:0|max:100',
    'min_guarantors'      => 'required|integer|min:0|max:20',
    'status'              => 'required|in:active,inactive',
];

return [

    'list' => function () {
        Auth::authorize('loans.view');
        [$where, $params] = loans_filters();
        $order = sort_clause([
            'principal' => 'l.principal', 'balance' => 'l.balance', 'applied_at' => 'l.applied_at', 'loan_no' => 'l.id', 'member' => 'm.first_name',
        ], 'l.id DESC');
        $res = paginate(
            'l.id, l.loan_no, l.member_id, l.principal, l.balance, l.amount_paid, l.total_payable, l.penalty_accrued,
             l.installment_amount, l.term_months, l.status, l.applied_at, l.disbursed_at, l.maturity_date,
             p.name AS product_name, m.member_no, m.first_name, m.last_name',
            "FROM loans l JOIN members m ON m.id = l.member_id JOIN loan_products p ON p.id = l.product_id $where",
            $params, $order
        );
        $arrears = loans_arrears(array_map(fn($r) => (int) $r['id'], array_filter($res['data'], fn($r) => $r['status'] === 'active')));
        foreach ($res['data'] as &$r) {
            $r['arrears'] = $arrears[(int) $r['id']]['arrears'] ?? 0;
            $r['days_overdue'] = $arrears[(int) $r['id']]['days'] ?? 0;
        }
        return $res;
    },

    'export' => function () {
        Auth::authorize('loans.view');
        [$where, $params] = loans_filters();
        export_csv('loans', [
            'loan_no' => 'Loan No', 'member_no' => 'Member No', 'member' => 'Member', 'product_name' => 'Product',
            'principal' => 'Principal', 'total_interest' => 'Interest', 'total_payable' => 'Total Payable', 'amount_paid' => 'Paid',
            'balance' => 'Balance', 'term_months' => 'Term (months)', 'status' => 'Status', 'applied_at' => 'Applied',
            'disbursed_at' => 'Disbursed', 'maturity_date' => 'Maturity',
        ], "SELECT l.*, m.member_no, CONCAT(m.first_name, ' ', m.last_name) AS member, p.name AS product_name
            FROM loans l JOIN members m ON m.id = l.member_id JOIN loan_products p ON p.id = l.product_id $where ORDER BY l.id", $params);
    },

    'stats' => function () {
        Auth::authorize('loans.view');
        [$bs, $bp] = branch_scope('l.branch_id');
        $by = DB::all("SELECT l.status, COUNT(*) AS n, SUM(l.principal) AS principal, SUM(l.balance) AS balance FROM loans l WHERE 1=1 $bs GROUP BY l.status", $bp);
        $s = [];
        foreach ($by as $r) {
            $s[$r['status']] = $r;
        }
        $portfolio = (float) ($s['active']['balance'] ?? 0);
        $par30 = (float) DB::value(
            "SELECT COALESCE(SUM(l.balance), 0) FROM loans l WHERE l.status = 'active' $bs
             AND EXISTS (SELECT 1 FROM loan_schedules s WHERE s.loan_id = l.id AND s.status <> 'paid' AND s.due_date < ?)",
            [...$bp, date('Y-m-d', strtotime('-30 days'))]
        );
        $arrears = (float) DB::value(
            "SELECT COALESCE(SUM(s.principal_due + s.interest_due + s.penalty_due - s.principal_paid - s.interest_paid - s.penalty_paid), 0)
             FROM loan_schedules s JOIN loans l ON l.id = s.loan_id
             WHERE l.status = 'active' AND s.status <> 'paid' AND s.due_date < ? $bs",
            [today(), ...$bp]
        );
        [$ts, $tp] = branch_scope('t.branch_id');
        $month = DB::pairs(
            "SELECT t.type, SUM(t.amount) FROM transactions t
             WHERE t.created_at >= ? AND t.status = 'posted' AND t.type IN ('loan_disbursement','loan_repayment') $ts GROUP BY t.type",
            [date('Y-m-01 00:00:00'), ...$tp]
        );
        return ['data' => [
            'portfolio'        => $portfolio,
            'active'           => (int) ($s['active']['n'] ?? 0),
            'pending'          => (int) ($s['pending']['n'] ?? 0),
            'pending_amount'   => (float) ($s['pending']['principal'] ?? 0),
            'approved'         => (int) ($s['approved']['n'] ?? 0),
            'approved_amount'  => (float) ($s['approved']['principal'] ?? 0),
            'closed'           => (int) ($s['closed']['n'] ?? 0),
            'arrears'          => $arrears,
            'par30'            => $portfolio > 0 ? round($par30 / $portfolio * 100, 2) : 0,
            'disbursed_month'  => (float) ($month['loan_disbursement'] ?? 0),
            'repaid_month'     => (float) ($month['loan_repayment'] ?? 0),
        ]];
    },

    'get' => function () {
        Auth::authorize('loans.view');
        $l = loan_scoped(input_int('id'));
        $l['member_name'] = member_name($l);
        $schedule = DB::all('SELECT * FROM loan_schedules WHERE loan_id = ? ORDER BY installment_no', [$l['id']]);
        $today = today();
        foreach ($schedule as &$s) {
            $s['total_due'] = round($s['principal_due'] + $s['interest_due'] + $s['penalty_due'], 2);
            $s['total_paid'] = round($s['principal_paid'] + $s['interest_paid'] + $s['penalty_paid'], 2);
            if ($s['status'] !== 'paid' && $s['due_date'] < $today) {
                $s['status'] = 'overdue';
            }
        }
        $arrears = loans_arrears([(int) $l['id']])[(int) $l['id']] ?? ['arrears' => 0, 'days' => 0];
        return ['data' => [
            'loan'        => $l,
            'schedule'    => $schedule,
            'repayments'  => DB::all('SELECT r.*, t.txn_ref, u.name AS received_by FROM loan_repayments r LEFT JOIN transactions t ON t.id = r.transaction_id LEFT JOIN users u ON u.id = r.created_by WHERE r.loan_id = ? ORDER BY r.id DESC', [$l['id']]),
            'guarantors'  => DB::all('SELECT g.*, m.member_no, m.first_name, m.last_name, m.phone FROM loan_guarantors g JOIN members m ON m.id = g.member_id WHERE g.loan_id = ?', [$l['id']]),
            'arrears'     => $arrears['arrears'],
            'days_overdue'=> $arrears['days'],
            'due_now'     => $l['status'] === 'active' ? LoanService::dueNow((int) $l['id']) : 0,
            'progress'    => (float) $l['total_payable'] > 0 ? min(100, round($l['amount_paid'] / ((float) $l['total_payable'] + (float) $l['penalty_accrued']) * 100, 1)) : 0,
            'can'         => [
                'approve'  => Auth::can('loans.approve'),
                'disburse' => Auth::can('loans.disburse'),
                'repay'    => Auth::can('loans.repay'),
                'writeoff' => Auth::can('loans.writeoff'),
            ],
        ]];
    },

    /** Live quote for the application form. */
    'calculate' => function () {
        Auth::authorize('loans.view');
        $product = DB::row('SELECT * FROM loan_products WHERE id = ?', [input_int('product_id')]);
        if (!$product) {
            throw new AppError('Select a loan product.');
        }
        $amount = max(0, input_float('amount'));
        $term = max(1, min(600, input_int('term_months', (int) $product['min_term'])));
        $out = ['product' => $product];
        if ($amount > 0) {
            $out['quote'] = LoanService::quote($product, $amount, $term);
        }
        if ($mid = input_int('member_id')) {
            MemberService::findOrFail($mid);
            $out['eligibility'] = LoanService::eligibility($mid, $product);
        }
        return ['data' => $out];
    },

    'apply' => function () {
        post_only();
        Auth::authorize('loans.apply');
        $d = Validator::make($_POST, [
            'member_id'   => 'required|integer',
            'product_id'  => 'required|integer',
            'amount'      => 'required|numeric|min:1',
            'term_months' => 'required|integer|min:1',
            'purpose'     => 'required|string|max:255',
        ], ['member_id' => 'Member', 'product_id' => 'Loan product', 'term_months' => 'Term']);
        MemberService::findOrFail($d['member_id']);
        $d['guarantors'] = array_values(array_filter((array) ($_POST['guarantors'] ?? []), 'is_array'));
        $id = LoanService::apply($d);
        $no = DB::value('SELECT loan_no FROM loans WHERE id = ?', [$id]);
        audit('apply', 'loan', $id, "Loan application $no for " . money($d['amount']));
        return ['message' => "Loan application $no submitted for approval.", 'id' => $id];
    },

    'approve' => function () {
        post_only();
        Auth::authorize('loans.approve');
        $l = loan_scoped(input_int('loan_id'));
        LoanService::approve((int) $l['id'], input('notes') ?: null);
        audit('approve', 'loan', $l['id'], "Approved loan {$l['loan_no']}");
        return ['message' => "Loan {$l['loan_no']} approved. It can now be disbursed."];
    },

    'reject' => function () {
        post_only();
        Auth::authorize('loans.approve');
        $d = Validator::make($_POST, ['loan_id' => 'required|integer', 'reason' => 'required|string|min:3|max:255']);
        $l = loan_scoped($d['loan_id']);
        LoanService::reject((int) $l['id'], $d['reason']);
        audit('reject', 'loan', $l['id'], "Rejected loan {$l['loan_no']}: {$d['reason']}");
        return ['message' => "Loan {$l['loan_no']} rejected."];
    },

    'disburse' => function () {
        post_only();
        Auth::authorize('loans.disburse');
        $d = Validator::make($_POST, [
            'loan_id'     => 'required|integer',
            'method'      => 'required|in:savings,cash,mobile_money,bank,cheque',
            'payment_ref' => 'nullable|string|max:80',
            'date'        => 'required|date',
        ], ['method' => 'Disbursement method', 'date' => 'Disbursement date']);
        if ($d['date'] > today()) {
            throw new ValidationError(['date' => 'Disbursement date cannot be in the future.']);
        }
        $l = loan_scoped($d['loan_id']);
        $r = LoanService::disburse((int) $l['id'], $d);
        audit('disburse', 'loan', $l['id'], "Disbursed loan {$l['loan_no']} ({$r['transaction']['ref']})");
        return ['message' => "Loan {$l['loan_no']} disbursed. Net amount " . money($r['net']) . ($d['method'] === 'savings' ? ' credited to savings.' : ' paid out.')];
    },

    'repay' => function () {
        post_only();
        Auth::authorize('loans.repay');
        $d = Validator::make($_POST, [
            'loan_id'            => 'required|integer',
            'amount'             => 'required|numeric|min:1',
            'source'             => 'required|in:cash,savings',
            'payment_method'     => 'nullable|in:cash,mobile_money,bank,cheque',
            'payment_ref'        => 'nullable|string|max:80',
            'savings_account_id' => 'nullable|integer',
        ]);
        $l = loan_scoped($d['loan_id']);
        $r = LoanService::repay((int) $l['id'], $d['amount'], $d);
        audit('repay', 'loan', $l['id'], "Repayment {$r['transaction']['ref']} of " . money($d['amount']) . " on {$l['loan_no']}");
        return [
            'message' => 'Repayment of ' . money($d['amount']) . " received on {$l['loan_no']}." . ($r['closed'] ? ' 🎉 The loan is now fully paid.' : ' Balance ' . money($r['balance']) . '.'),
            'receipt' => ['ref' => $r['transaction']['ref'], 'amount' => $d['amount'], 'balance' => $r['balance'], 'member' => member_name($l), 'account' => $l['loan_no'], 'type' => 'Loan Repayment'],
        ];
    },

    'write_off' => function () {
        post_only();
        Auth::authorize('loans.writeoff');
        $d = Validator::make($_POST, ['loan_id' => 'required|integer', 'reason' => 'required|string|min:3|max:200']);
        $l = loan_scoped($d['loan_id']);
        LoanService::writeOff((int) $l['id'], $d['reason']);
        audit('write_off', 'loan', $l['id'], "Wrote off {$l['loan_no']} with balance " . money($l['balance']));
        return ['message' => "Loan {$l['loan_no']} written off."];
    },

    'products' => function () {
        Auth::authorize('loans.view');
        return ['data' => DB::all(
            "SELECT p.*, COUNT(l.id) AS active_loans, COALESCE(SUM(l.balance), 0) AS portfolio
             FROM loan_products p LEFT JOIN loans l ON l.product_id = p.id AND l.status = 'active'
             GROUP BY p.id ORDER BY p.status, p.name"
        )];
    },

    'product_save' => function () use ($productRules) {
        post_only();
        Auth::authorize('loans.products');
        $id = input_int('id');
        $d = Validator::make($_POST, $productRules, ['min_term' => 'Minimum term', 'max_term' => 'Maximum term', 'savings_multiplier' => 'Savings multiplier']);
        $d['code'] = strtoupper($d['code']);
        if ($d['max_amount'] < $d['min_amount']) {
            throw new ValidationError(['max_amount' => 'Maximum amount must be greater than the minimum.']);
        }
        if ($d['max_term'] < $d['min_term']) {
            throw new ValidationError(['max_term' => 'Maximum term must be greater than the minimum.']);
        }
        if (DB::value('SELECT id FROM loan_products WHERE code = ? AND id <> ?', [$d['code'], $id])) {
            throw new ValidationError(['code' => 'This code is already in use.']);
        }
        if ($id) {
            DB::update('loan_products', $d, 'id = ?', [$id]);
        } else {
            $id = DB::insert('loan_products', $d);
        }
        audit('save', 'loan_product', $id, "Saved loan product {$d['name']}");
        return ['message' => "Loan product “{$d['name']}” saved."];
    },
];
