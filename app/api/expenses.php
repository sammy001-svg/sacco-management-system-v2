<?php
defined('SACCO') or exit;

const EXPENSE_CATEGORIES = ['Salaries', 'Rent', 'Utilities', 'Stationery', 'Transport', 'Marketing', 'ICT & Software',
    'Bank Charges', 'Repairs & Maintenance', 'Insurance', 'Professional Fees', 'Meetings & AGM', 'Training', 'Security', 'Other'];

function expense_filters(): array
{
    $where = 'WHERE 1=1';
    $params = [];
    [$bs, $bp] = branch_scope('e.branch_id');
    $where .= $bs;
    $params = [...$params, ...$bp];
    if ($c = (string) input('category', '')) {
        $where .= ' AND e.category = ?';
        $params[] = $c;
    }
    if (($q = trim((string) input('search', ''))) !== '') {
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
        $where .= ' AND (e.payee LIKE ? OR e.description LIKE ? OR e.payment_ref LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    if ($from = input_date('date_from')) {
        $where .= ' AND e.expense_date >= ?';
        $params[] = $from;
    }
    if ($to = input_date('date_to')) {
        $where .= ' AND e.expense_date <= ?';
        $params[] = $to;
    }
    return [$where, $params];
}

function expense_scoped(int $id): array
{
    $e = DB::row('SELECT * FROM expenses WHERE id = ?', [$id]);
    $own = Auth::user()['branch_id'] ?? null;
    if (!$e || ($own && (int) $own !== (int) $e['branch_id'])) {
        throw new AppError('Expense not found.', 404);
    }
    return $e;
}

/** Undo an expense's ledger entry (used when voiding or changing the amount). */
function expense_reverse_ledger(array $e, string $reason): void
{
    if (!$e['transaction_id']) {
        return;
    }
    $t = DB::row('SELECT * FROM transactions WHERE id = ? FOR UPDATE', [$e['transaction_id']]);
    if (!$t || $t['status'] === 'reversed') {
        return;
    }
    Ledger::post([
        'branch_id' => $t['branch_id'], 'account_type' => 'expense', 'type' => 'reversal', 'amount' => $t['amount'],
        'flow' => 'in', 'payment_method' => $t['payment_method'], 'payment_ref' => $t['txn_ref'],
        'narration' => "Reversal of {$t['txn_ref']}: $reason", 'reversal_of' => $t['id'],
    ]);
    DB::query("UPDATE transactions SET status = 'reversed' WHERE id = ?", [$t['id']]);
}

return [

    'list' => function () {
        Auth::authorize('expenses.view');
        [$where, $params] = expense_filters();
        $order = sort_clause(['expense_date' => 'e.expense_date', 'amount' => 'e.amount', 'category' => 'e.category'], 'e.expense_date DESC, e.id DESC');
        $res = paginate(
            'e.*, b.name AS branch_name, u.name AS user_name, t.txn_ref',
            "FROM expenses e JOIN branches b ON b.id = e.branch_id LEFT JOIN users u ON u.id = e.created_by LEFT JOIN transactions t ON t.id = e.transaction_id $where",
            $params, $order
        );
        $res['total'] = (float) DB::value("SELECT COALESCE(SUM(e.amount), 0) FROM expenses e $where", $params);
        return $res;
    },

    'export' => function () {
        Auth::authorize('expenses.view');
        [$where, $params] = expense_filters();
        export_csv('expenses', [
            'expense_date' => 'Date', 'category' => 'Category', 'payee' => 'Payee', 'description' => 'Description',
            'amount' => 'Amount', 'payment_method' => 'Method', 'payment_ref' => 'Reference', 'branch_name' => 'Branch',
        ], "SELECT e.*, b.name AS branch_name FROM expenses e JOIN branches b ON b.id = e.branch_id $where ORDER BY e.expense_date", $params);
    },

    'stats' => function () {
        Auth::authorize('expenses.view');
        [$bs, $bp] = branch_scope('branch_id');
        $month = (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? $bs", [date('Y-m-01'), ...$bp]);
        $last = (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? AND expense_date < ? $bs",
            [date('Y-m-01', strtotime('first day of last month')), date('Y-m-01'), ...$bp]);
        $year = (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= ? $bs", [date('Y-01-01'), ...$bp]);
        $top = DB::row("SELECT category, SUM(amount) AS total FROM expenses WHERE expense_date >= ? $bs GROUP BY category ORDER BY total DESC LIMIT 1", [date('Y-m-01'), ...$bp]);
        return ['data' => [
            'month'        => $month,
            'last_month'   => $last,
            'change'       => $last > 0 ? round(($month - $last) / $last * 100, 1) : null,
            'year'         => $year,
            'top_category' => $top['category'] ?? '—',
            'top_amount'   => (float) ($top['total'] ?? 0),
        ]];
    },

    'categories' => function () {
        Auth::authorize('expenses.view');
        $used = array_column(DB::all('SELECT DISTINCT category FROM expenses ORDER BY category'), 'category');
        return ['data' => array_values(array_unique([...EXPENSE_CATEGORIES, ...$used]))];
    },

    'save' => function () {
        post_only();
        Auth::authorize('expenses.manage');
        $id = input_int('id');
        $d = Validator::make($_POST, [
            'branch_id'      => 'required|integer|exists:branches,id',
            'category'       => 'required|string|max:60',
            'payee'          => 'nullable|string|max:120',
            'amount'         => 'required|numeric|min:1',
            'description'    => 'nullable|string|max:255',
            'payment_method' => 'required|in:cash,mobile_money,bank,cheque',
            'payment_ref'    => 'nullable|string|max:80',
            'expense_date'   => 'required|date',
        ]);
        if ($own = Auth::user()['branch_id']) {
            $d['branch_id'] = (int) $own;
        }
        if ($d['expense_date'] > today()) {
            throw new ValidationError(['expense_date' => 'Expense date cannot be in the future.']);
        }

        DB::transaction(function () use (&$id, $d) {
            $post = fn() => Ledger::post([
                'branch_id' => $d['branch_id'], 'account_type' => 'expense', 'type' => 'expense', 'amount' => $d['amount'],
                'flow' => 'out', 'payment_method' => $d['payment_method'], 'payment_ref' => $d['payment_ref'],
                'narration' => "{$d['category']}: " . ($d['description'] ?: ($d['payee'] ?: 'expense')),
                'at' => $d['expense_date'] === today() ? now() : "{$d['expense_date']} 12:00:00",
            ])['id'];

            if ($id) {
                $e = expense_scoped($id);
                $moneyChanged = (float) $e['amount'] !== (float) $d['amount'] || $e['payment_method'] !== $d['payment_method']
                    || (int) $e['branch_id'] !== (int) $d['branch_id'] || $e['expense_date'] !== $d['expense_date'];
                if ($moneyChanged) {
                    expense_reverse_ledger($e, 'expense amended');
                    $d['transaction_id'] = $post();
                }
                DB::update('expenses', $d, 'id = ?', [$id]);
            } else {
                $d['transaction_id'] = $post();
                $d['created_by'] = Auth::id();
                $id = DB::insert('expenses', $d);
            }
        });
        audit('save', 'expense', $id, "{$d['category']} expense of " . money($d['amount']));
        return ['message' => 'Expense of ' . money($d['amount']) . ' recorded.'];
    },

    'delete' => function () {
        post_only();
        Auth::authorize('expenses.manage');
        $e = expense_scoped(input_int('id'));
        DB::transaction(function () use ($e) {
            expense_reverse_ledger($e, 'expense voided');
            DB::query('DELETE FROM expenses WHERE id = ?', [$e['id']]);
        });
        audit('delete', 'expense', $e['id'], "Voided {$e['category']} expense of " . money($e['amount']));
        return ['message' => 'Expense voided and its ledger entry reversed.'];
    },
];
