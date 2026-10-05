<?php
defined('SACCO') or exit;

return [

    'summary' => function () {
        Auth::authorize('dashboard.view');
        [$ms, $mp] = branch_scope('m.branch_id');
        [$ls, $lp] = branch_scope('l.branch_id');
        [$ts, $tp] = branch_scope('t.branch_id');
        $monthStart = date('Y-m-01 00:00:00');
        $today = today();

        $members = DB::row(
            "SELECT COUNT(*) AS total, SUM(m.status = 'active') AS active, SUM(m.joined_at >= ?) AS new_month FROM members m WHERE 1=1 $ms",
            [date('Y-m-01'), ...$mp]
        );
        $savings = (float) ($ms
            ? DB::value("SELECT COALESCE(SUM(a.balance),0) FROM savings_accounts a JOIN members m ON m.id = a.member_id WHERE 1=1 $ms", $mp)
            : DB::value('SELECT COALESCE(SUM(balance),0) FROM savings_accounts'));
        $shares = (float) ($ms
            ? DB::value("SELECT COALESCE(SUM(s.balance),0) FROM share_accounts s JOIN members m ON m.id = s.member_id WHERE 1=1 $ms", $mp)
            : DB::value('SELECT COALESCE(SUM(balance),0) FROM share_accounts'));
        $loans = DB::row(
            "SELECT COALESCE(SUM(CASE WHEN l.status = 'active' THEN l.balance END),0) AS portfolio,
                    SUM(l.status = 'active') AS active, SUM(l.status = 'pending') AS pending, SUM(l.status = 'approved') AS approved
             FROM loans l WHERE l.status IN ('active','pending','approved') $ls",
            $lp
        );
        $par30 = (float) DB::value(
            "SELECT COALESCE(SUM(l.balance),0) FROM loans l WHERE l.status = 'active' $ls
             AND EXISTS (SELECT 1 FROM loan_schedules s WHERE s.loan_id = l.id AND s.status <> 'paid' AND s.due_date < ?)",
            [...$lp, date('Y-m-d', strtotime('-30 days'))]
        );
        $flows = DB::pairs(
            "SELECT t.type, SUM(t.amount) FROM transactions t
             WHERE t.created_at >= ? AND t.status = 'posted'
               AND t.type IN ('deposit','withdrawal','loan_disbursement','loan_repayment','share_purchase')
               AND NOT (t.type IN ('deposit','withdrawal') AND t.payment_method = 'internal') $ts
             GROUP BY t.type",
            [$monthStart, ...$tp]
        );
        $income = (float) DB::value(
            "SELECT COALESCE(SUM(t.amount),0) FROM transactions t
             WHERE t.created_at >= ? AND t.status = 'posted' AND t.type IN ('processing_fee','registration_fee','withdrawal_fee') $ts",
            [$monthStart, ...$tp]
        ) + (float) DB::value(
            "SELECT COALESCE(SUM(r.interest_paid + r.penalty_paid),0) FROM loan_repayments r JOIN loans l ON l.id = r.loan_id WHERE r.created_at >= ? $ls",
            [$monthStart, ...$lp]
        );

        $portfolio = (float) $loans['portfolio'];
        $kpis = [
            'members'          => (int) $members['total'],
            'members_active'   => (int) $members['active'],
            'members_new'      => (int) $members['new_month'],
            'savings'          => $savings,
            'shares'           => $shares,
            'portfolio'        => $portfolio,
            'loans_active'     => (int) $loans['active'],
            'loans_pending'    => (int) $loans['pending'],
            'loans_approved'   => (int) $loans['approved'],
            'par30'            => $portfolio > 0 ? round($par30 / $portfolio * 100, 2) : 0,
            'deposits_month'   => (float) ($flows['deposit'] ?? 0),
            'withdrawals_month'=> (float) ($flows['withdrawal'] ?? 0),
            'disbursed_month'  => (float) ($flows['loan_disbursement'] ?? 0),
            'repaid_month'     => (float) ($flows['loan_repayment'] ?? 0),
            'shares_month'     => (float) ($flows['share_purchase'] ?? 0),
            'income_month'     => round($income, 2),
            'liquidity'        => $savings > 0 ? round(($savings + $shares - $portfolio) / $savings * 100, 1) : 0,
        ];

        // 12-month series are the heaviest queries on a big ledger: cache briefly.
        $scopeKey = 'dash:' . ($tp[0] ?? 'all');
        $charts = cache_remember($scopeKey, 120, function () use ($ts, $tp, $ms, $mp, $ls, $lp) {
            $start = date('Y-m-01', strtotime('-11 months'));
            $months = [];
            for ($i = 11; $i >= 0; $i--) {
                $months[date('Y-m', strtotime(date('Y-m-01') . " -$i months"))] = 0.0;
            }
            $series = ['deposit' => $months, 'withdrawal' => $months, 'loan_disbursement' => $months, 'loan_repayment' => $months, 'share_purchase' => $months];
            $rows = DB::all(
                "SELECT DATE_FORMAT(t.created_at, '%Y-%m') AS ym, t.type, SUM(t.amount) AS total
                 FROM transactions t
                 WHERE t.created_at >= ? AND t.status = 'posted'
                   AND t.type IN ('deposit','withdrawal','loan_disbursement','loan_repayment','share_purchase')
                   AND NOT (t.type IN ('deposit','withdrawal') AND t.payment_method = 'internal') $ts
                 GROUP BY ym, t.type",
                ["$start 00:00:00", ...$tp]
            );
            foreach ($rows as $r) {
                if (isset($series[$r['type']][$r['ym']])) {
                    $series[$r['type']][$r['ym']] = (float) $r['total'];
                }
            }
            $growth = $months;
            foreach (DB::all("SELECT DATE_FORMAT(m.joined_at, '%Y-%m') AS ym, COUNT(*) AS n FROM members m WHERE m.joined_at >= ? $ms GROUP BY ym", [$start, ...$mp]) as $r) {
                if (isset($growth[$r['ym']])) {
                    $growth[$r['ym']] = (int) $r['n'];
                }
            }
            $byProduct = DB::all(
                "SELECT p.name, COUNT(*) AS loans, SUM(l.balance) AS balance FROM loans l JOIN loan_products p ON p.id = l.product_id
                 WHERE l.status = 'active' $ls GROUP BY p.id ORDER BY balance DESC",
                $lp
            );
            return [
                'labels'     => array_map(fn($ym) => date('M y', strtotime("$ym-01")), array_keys($months)),
                'deposits'   => array_values($series['deposit']),
                'withdrawals'=> array_values($series['withdrawal']),
                'disbursed'  => array_values($series['loan_disbursement']),
                'repaid'     => array_values($series['loan_repayment']),
                'shares'     => array_values($series['share_purchase']),
                'growth'     => array_values($growth),
                'by_product' => $byProduct,
            ];
        });

        $recent = DB::all(
            "SELECT t.id, t.txn_ref, t.type, t.amount, t.flow, t.payment_method, t.created_at, t.status, t.member_id,
                    m.first_name, m.last_name, m.member_no
             FROM transactions t LEFT JOIN members m ON m.id = t.member_id
             WHERE 1=1 $ts ORDER BY t.id DESC LIMIT 7",
            $tp
        );
        $dues = DB::all(
            "SELECT s.due_date, s.installment_no, l.id AS loan_id, l.loan_no, m.id AS member_id, m.first_name, m.last_name, m.phone,
                    (s.principal_due + s.interest_due + s.penalty_due - s.principal_paid - s.interest_paid - s.penalty_paid) AS amount
             FROM loan_schedules s JOIN loans l ON l.id = s.loan_id JOIN members m ON m.id = l.member_id
             WHERE s.due_date BETWEEN ? AND ? AND s.status IN ('pending','partial') AND l.status = 'active' $ls
             ORDER BY s.due_date LIMIT 6",
            [$today, date('Y-m-d', strtotime('+10 days')), ...$lp]
        );

        return ['data' => ['kpis' => $kpis, 'charts' => $charts, 'recent' => $recent, 'dues' => $dues]];
    },

    /** Topbar notification centre. */
    'alerts' => function () {
        [$ls, $lp] = branch_scope('l.branch_id');
        $items = [];
        if (Auth::can('loans.approve')) {
            $n = (int) DB::value("SELECT COUNT(*) FROM loans l WHERE l.status = 'pending' $ls", $lp);
            if ($n) {
                $items[] = ['icon' => 'hourglass-split', 'tone' => 'amber', 'title' => "$n loan application" . ($n > 1 ? 's' : '') . ' awaiting approval', 'url' => 'index.php?page=loans&status=pending'];
            }
        }
        if (Auth::can('loans.disburse')) {
            $n = (int) DB::value("SELECT COUNT(*) FROM loans l WHERE l.status = 'approved' $ls", $lp);
            if ($n) {
                $items[] = ['icon' => 'send-check', 'tone' => 'blue', 'title' => "$n approved loan" . ($n > 1 ? 's' : '') . ' ready to disburse', 'url' => 'index.php?page=loans&status=approved'];
            }
        }
        if (Auth::can('loans.view')) {
            $n = (int) DB::value(
                "SELECT COUNT(DISTINCT l.id) FROM loans l JOIN loan_schedules s ON s.loan_id = l.id
                 WHERE l.status = 'active' AND s.status <> 'paid' AND s.due_date < ? $ls",
                [today(), ...$lp]
            );
            if ($n) {
                $items[] = ['icon' => 'exclamation-octagon', 'tone' => 'red', 'title' => "$n loan" . ($n > 1 ? 's are' : ' is') . ' in arrears', 'url' => 'index.php?page=loans&status=arrears'];
            }
            $n = (int) DB::value(
                "SELECT COUNT(*) FROM loan_schedules s JOIN loans l ON l.id = s.loan_id
                 WHERE s.due_date BETWEEN ? AND ? AND s.status IN ('pending','partial') AND l.status = 'active' $ls",
                [today(), date('Y-m-d', strtotime('+3 days')), ...$lp]
            );
            if ($n) {
                $items[] = ['icon' => 'calendar-event', 'tone' => 'green', 'title' => "$n installment" . ($n > 1 ? 's' : '') . ' due in the next 3 days', 'url' => 'index.php?page=loans&status=active'];
            }
        }
        return ['data' => $items];
    },
];
