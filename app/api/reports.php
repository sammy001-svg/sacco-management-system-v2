<?php
defined('SACCO') or exit;

return [

    'summary' => function () {
        Auth::authorize('reports.view');
        $from = input_date('date_from', date('Y-m-01'));
        $to = input_date('date_to', today());
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $range = ["$from 00:00:00", "$to 23:59:59"];
        [$ts, $tp] = branch_scope('t.branch_id');
        [$ls, $lp] = branch_scope('l.branch_id');
        [$ms, $mp] = branch_scope('m.branch_id');
        [$es, $ep] = branch_scope('e.branch_id');

        // Ledger activity by type
        $activity = DB::all(
            "SELECT t.type, t.flow, COUNT(*) AS n, SUM(t.amount) AS total FROM transactions t
             WHERE t.created_at BETWEEN ? AND ? AND t.status = 'posted' $ts GROUP BY t.type, t.flow ORDER BY total DESC",
            [...$range, ...$tp]
        );
        $cashIn = $cashOut = 0.0;
        foreach ($activity as &$a) {
            $a['label'] = Ledger::TYPES[$a['type']] ?? label($a['type']);
            $cashIn += $a['flow'] === 'in' ? (float) $a['total'] : 0;
            $cashOut += $a['flow'] === 'out' ? (float) $a['total'] : 0;
        }
        unset($a);

        // Income statement
        $fees = DB::pairs(
            "SELECT t.type, SUM(t.amount) FROM transactions t WHERE t.created_at BETWEEN ? AND ? AND t.status = 'posted'
             AND t.type IN ('processing_fee','registration_fee','withdrawal_fee') $ts GROUP BY t.type",
            [...$range, ...$tp]
        );
        $loanIncome = DB::row(
            "SELECT COALESCE(SUM(r.interest_paid),0) AS interest, COALESCE(SUM(r.penalty_paid),0) AS penalty
             FROM loan_repayments r JOIN loans l ON l.id = r.loan_id WHERE r.created_at BETWEEN ? AND ? $ls",
            [...$range, ...$lp]
        );
        $income = [
            ['label' => 'Loan interest',     'amount' => (float) $loanIncome['interest']],
            ['label' => 'Loan penalties',    'amount' => (float) $loanIncome['penalty']],
            ['label' => 'Processing fees',   'amount' => (float) ($fees['processing_fee'] ?? 0)],
            ['label' => 'Registration fees', 'amount' => (float) ($fees['registration_fee'] ?? 0)],
            ['label' => 'Withdrawal fees',   'amount' => (float) ($fees['withdrawal_fee'] ?? 0)],
        ];
        $incomeTotal = array_sum(array_column($income, 'amount'));
        $expenses = DB::all(
            "SELECT e.category AS label, SUM(e.amount) AS amount FROM expenses e WHERE e.expense_date BETWEEN ? AND ? $es GROUP BY e.category ORDER BY amount DESC",
            [$from, $to, ...$ep]
        );
        $expenseTotal = array_sum(array_map(fn($e) => (float) $e['amount'], $expenses));

        // Portfolio at risk (as of today)
        $order = ['Current', '1–30 days', '31–60 days', '61–90 days', '91–180 days', 'Over 180 days'];
        $par = array_fill_keys($order, ['loans' => 0, 'balance' => 0.0]);
        $rows = DB::all(
            "SELECT CASE
                      WHEN x.days IS NULL THEN 'Current'
                      WHEN x.days <= 30 THEN '1–30 days'
                      WHEN x.days <= 60 THEN '31–60 days'
                      WHEN x.days <= 90 THEN '61–90 days'
                      WHEN x.days <= 180 THEN '91–180 days'
                      ELSE 'Over 180 days' END AS bucket,
                    COUNT(*) AS loans, SUM(l.balance) AS balance
             FROM loans l
             LEFT JOIN (SELECT loan_id, DATEDIFF(?, MIN(due_date)) AS days FROM loan_schedules
                        WHERE status <> 'paid' AND due_date < ? GROUP BY loan_id) x ON x.loan_id = l.id
             WHERE l.status = 'active' $ls GROUP BY bucket",
            [today(), today(), ...$lp]
        );
        foreach ($rows as $r) {
            $par[$r['bucket']] = ['loans' => (int) $r['loans'], 'balance' => (float) $r['balance']];
        }
        $portfolio = array_sum(array_column($par, 'balance'));
        $parList = [];
        foreach ($par as $bucket => $v) {
            $parList[] = ['bucket' => $bucket] + $v + ['share' => $portfolio > 0 ? round($v['balance'] / $portfolio * 100, 2) : 0];
        }

        $products = DB::all(
            "SELECT p.name,
                    SUM(l.status = 'active') AS active, COALESCE(SUM(CASE WHEN l.status = 'active' THEN l.balance END), 0) AS outstanding,
                    SUM(l.disbursed_at BETWEEN ? AND ?) AS disbursed_count,
                    COALESCE(SUM(CASE WHEN l.disbursed_at BETWEEN ? AND ? THEN l.principal END), 0) AS disbursed
             FROM loan_products p LEFT JOIN loans l ON l.product_id = p.id $ls
             GROUP BY p.id ORDER BY outstanding DESC",
            [...$range, ...$range, ...$lp]
        );

        $topSavers = DB::all(
            "SELECT m.id, m.member_no, m.first_name, m.last_name, SUM(a.balance) AS total
             FROM savings_accounts a JOIN members m ON m.id = a.member_id WHERE 1=1 $ms
             GROUP BY m.id ORDER BY total DESC LIMIT 10",
            $mp
        );
        $membership = DB::row(
            "SELECT SUM(m.joined_at BETWEEN ? AND ?) AS joined, SUM(m.status = 'active') AS active, SUM(m.status = 'exited') AS exited, COUNT(*) AS total
             FROM members m WHERE 1=1 $ms",
            [$from, $to, ...$mp]
        );

        return ['data' => [
            'period'     => ['from' => $from, 'to' => $to],
            'activity'   => $activity,
            'cash'       => ['in' => $cashIn, 'out' => $cashOut, 'net' => $cashIn - $cashOut],
            'income'     => $income,
            'income_total' => $incomeTotal,
            'expenses'   => $expenses,
            'expense_total' => $expenseTotal,
            'surplus'    => $incomeTotal - $expenseTotal,
            'par'        => $parList,
            'portfolio'  => $portfolio,
            'products'   => $products,
            'top_savers' => $topSavers,
            'membership' => array_map('intval', $membership),
        ]];
    },
];
