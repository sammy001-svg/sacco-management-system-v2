<?php
defined('SACCO') or exit;

function members_filters(): array
{
    $where = 'WHERE 1=1';
    $params = [];
    [$bs, $bp] = branch_scope('m.branch_id');
    $where .= $bs;
    $params = [...$params, ...$bp];

    [$ss, $sp] = member_search_clause((string) input('search', ''));
    $where .= $ss;
    $params = [...$params, ...$sp];

    $status = (string) input('status', '');
    if (in_array($status, MemberService::STATUSES, true)) {
        $where .= ' AND m.status = ?';
        $params[] = $status;
    }
    if ($from = input_date('joined_from')) {
        $where .= ' AND m.joined_at >= ?';
        $params[] = $from;
    }
    if ($to = input_date('joined_to')) {
        $where .= ' AND m.joined_at <= ?';
        $params[] = $to;
    }
    return [$where, $params];
}

return [

    'list' => function () {
        Auth::authorize('members.view');
        [$where, $params] = members_filters();
        $from = "FROM members m JOIN branches b ON b.id = m.branch_id $where";
        $order = sort_clause([
            'name' => 'm.first_name', 'member_no' => 'm.member_no', 'joined_at' => 'm.joined_at', 'status' => 'm.status',
        ], 'm.id DESC');

        $res = paginate(
            'm.id, m.member_no, m.first_name, m.last_name, m.other_names, m.phone, m.email, m.national_id,
             m.gender, m.status, m.joined_at, m.branch_id, b.name AS branch_name',
            $from, $params, $order
        );
        $res['data'] = attach_member_totals($res['data']);
        return $res;
    },

    'export' => function () {
        Auth::authorize('members.view');
        [$where, $params] = members_filters();
        export_csv('members', [
            'member_no' => 'Member No', 'first_name' => 'First Name', 'last_name' => 'Last Name', 'other_names' => 'Other Names',
            'national_id' => 'National ID', 'phone' => 'Phone', 'email' => 'Email', 'gender' => 'Gender', 'branch_name' => 'Branch',
            'occupation' => 'Occupation', 'employer' => 'Employer', 'status' => 'Status', 'joined_at' => 'Joined',
        ], "SELECT m.*, b.name AS branch_name FROM members m JOIN branches b ON b.id = m.branch_id $where ORDER BY m.id", $params);
    },

    'stats' => function () {
        Auth::authorize('members.view');
        [$bs, $bp] = branch_scope('branch_id');
        $rows = DB::pairs("SELECT status, COUNT(*) FROM members WHERE 1=1 $bs GROUP BY status", $bp);
        $new = (int) DB::value("SELECT COUNT(*) FROM members WHERE joined_at >= ? $bs", [date('Y-m-01'), ...$bp]);
        return ['data' => [
            'total'     => array_sum(array_map('intval', $rows)),
            'active'    => (int) ($rows['active'] ?? 0),
            'dormant'   => (int) ($rows['dormant'] ?? 0),
            'suspended' => (int) ($rows['suspended'] ?? 0),
            'new_month' => $new,
        ]];
    },

    /** Lightweight lookup for member pickers & global search. */
    'search' => function () {
        Auth::authorize('members.view');
        $q = (string) input('q', '');
        if (mb_strlen($q) < 1) {
            return ['data' => []];
        }
        $where = 'WHERE 1=1';
        $params = [];
        [$bs, $bp] = branch_scope('m.branch_id');
        [$ss, $sp] = member_search_clause($q);
        $where .= $bs . $ss;
        $params = [...$bp, ...$sp];
        if ($exclude = input_int('exclude')) {
            $where .= ' AND m.id <> ?';
            $params[] = $exclude;
        }
        if (input('active_only')) {
            $where .= " AND m.status IN ('active','dormant')";
        }
        $rows = DB::all(
            "SELECT m.id, m.member_no, m.first_name, m.last_name, m.other_names, m.phone, m.status, b.name AS branch_name
             FROM members m JOIN branches b ON b.id = m.branch_id $where ORDER BY m.first_name, m.last_name LIMIT 10",
            $params
        );
        $rows = attach_member_totals($rows);
        foreach ($rows as &$r) {
            $r['name'] = member_name($r);
        }
        return ['data' => $rows];
    },

    'get' => function () {
        Auth::authorize('members.view');
        $m = MemberService::findOrFail(input_int('id'));
        $m['name'] = member_name($m);
        return ['data' => $m];
    },

    /** Photo, signature and ID the teller checks before paying out cash. */
    'identity' => function () {
        Auth::authorize('members.view');
        $m = MemberService::findOrFail(input_int('id'));
        $docs = [];
        foreach (DB::all(
            "SELECT type, MAX(id) AS id FROM member_documents WHERE member_id = ? AND type IN ('photo','signature','id_front','id_back') GROUP BY type",
            [$m['id']]
        ) as $d) {
            $docs[$d['type']] = (int) $d['id'];
        }
        return ['data' => [
            'id'          => (int) $m['id'],
            'name'        => member_name($m),
            'member_no'   => $m['member_no'],
            'national_id' => $m['national_id'],
            'phone'       => $m['phone'],
            'status'      => $m['status'],
            'date_of_birth' => $m['date_of_birth'] ?? null,
            'docs'        => $docs,
        ]];
    },

    /** Accounts used by the transaction modals once a member is picked. */
    'accounts' => function () {
        Auth::authorize('members.view');
        $m = MemberService::findOrFail(input_int('member_id'));
        $loans = DB::all(
            "SELECT l.id, l.loan_no, l.balance, l.installment_amount, p.name AS product_name
             FROM loans l JOIN loan_products p ON p.id = l.product_id WHERE l.member_id = ? AND l.status = 'active' ORDER BY l.id",
            [$m['id']]
        );
        foreach ($loans as &$l) {
            $l['due_now'] = LoanService::dueNow((int) $l['id']);
        }
        return ['data' => [
            'accounts' => SavingsService::memberAccounts((int) $m['id']),
            'shares'   => DB::row('SELECT shares, balance FROM share_accounts WHERE member_id = ?', [$m['id']]) ?: ['shares' => 0, 'balance' => 0],
            'loans'    => $loans,
        ]];
    },

    /** Everything the member profile needs in one call. */
    'summary' => function () {
        Auth::authorize('members.view');
        $m = MemberService::findOrFail(input_int('id'));
        $id = (int) $m['id'];
        $accounts = DB::all(
            "SELECT a.*, p.name AS product_name, p.min_balance, p.allow_withdrawal, p.interest_rate
             FROM savings_accounts a JOIN savings_products p ON p.id = a.product_id WHERE a.member_id = ? ORDER BY p.is_default DESC, a.id",
            [$id]
        );
        $loans = DB::all("SELECT status, COUNT(*) AS n, COALESCE(SUM(balance),0) AS balance FROM loans WHERE member_id = ? GROUP BY status", [$id]);
        $byStatus = [];
        foreach ($loans as $l) {
            $byStatus[$l['status']] = $l;
        }
        $guarantees = DB::all(
            "SELECT g.amount, g.status, l.id AS loan_id, l.loan_no, l.status AS loan_status, l.balance, m.id AS borrower_id, m.first_name, m.last_name, m.member_no
             FROM loan_guarantors g JOIN loans l ON l.id = g.loan_id JOIN members m ON m.id = l.member_id
             WHERE g.member_id = ? ORDER BY g.id DESC LIMIT 50",
            [$id]
        );
        $elig = LoanService::eligibility($id);
        return ['data' => [
            'accounts'     => $accounts,
            'shares'       => DB::row('SELECT shares, balance FROM share_accounts WHERE member_id = ?', [$id]) ?: ['shares' => 0, 'balance' => 0],
            'savings'      => array_sum(array_map(fn($a) => $a['status'] !== 'closed' ? (float) $a['balance'] : 0, $accounts)),
            'loan_balance' => (float) ($byStatus['active']['balance'] ?? 0),
            'active_loans' => (int) ($byStatus['active']['n'] ?? 0),
            'pending_loans'=> (int) ($byStatus['pending']['n'] ?? 0) + (int) ($byStatus['approved']['n'] ?? 0),
            'guaranteed'   => $elig['guaranteed'],
            'free_savings' => $elig['free_savings'],
            'guarantees'   => $guarantees,
        ]];
    },

    'save' => function () {
        post_only();
        Auth::authorize('members.manage');
        $id = input_int('id');

        $d = Validator::make($_POST, [
            'member_no'                => 'nullable|string|max:30',
            'branch_id'                => 'required|integer|exists:branches,id',
            'first_name'               => 'required|string|max:80',
            'last_name'                => 'required|string|max:80',
            'other_names'              => 'nullable|string|max:80',
            'gender'                   => 'nullable|in:male,female,other',
            'date_of_birth'            => 'nullable|date',
            'national_id'              => 'required|string|max:40',
            'tax_pin'                  => 'nullable|string|max:30',
            'phone'                    => 'required|phone',
            'email'                    => 'nullable|email|max:190',
            'address'                  => 'nullable|string|max:255',
            'occupation'               => 'nullable|string|max:120',
            'employer'                 => 'nullable|string|max:160',
            'monthly_income'           => 'nullable|numeric|min:0',
            'next_of_kin_name'         => 'nullable|string|max:160',
            'next_of_kin_phone'        => 'nullable|phone',
            'next_of_kin_relationship' => 'nullable|string|max:60',
            'status'                   => 'required|in:' . implode(',', MemberService::STATUSES),
            'joined_at'                => 'required|date',
        ], ['national_id' => 'National ID', 'next_of_kin_phone' => 'Next of kin phone', 'date_of_birth' => 'Date of birth', 'joined_at' => 'Date joined']);

        if ($own = Auth::user()['branch_id']) {
            $d['branch_id'] = (int) $own;
        }
        if ($d['date_of_birth'] && $d['date_of_birth'] > date('Y-m-d', strtotime('-16 years'))) {
            throw new ValidationError(['date_of_birth' => 'Members must be at least 16 years old.']);
        }
        if ($d['joined_at'] > today()) {
            throw new ValidationError(['joined_at' => 'Join date cannot be in the future.']);
        }
        if (DB::value('SELECT id FROM members WHERE national_id = ? AND id <> ?', [$d['national_id'], $id])) {
            throw new ValidationError(['national_id' => 'Another member is already registered with this National ID.']);
        }
        if ($d['member_no'] && DB::value('SELECT id FROM members WHERE member_no = ? AND id <> ?', [$d['member_no'], $id])) {
            throw new ValidationError(['member_no' => 'This member number is already in use.']);
        }

        if ($id) {
            $existing = MemberService::findOrFail($id);
            if (!$d['member_no']) {
                $d['member_no'] = $existing['member_no'];
            }
            DB::update('members', $d, 'id = ?', [$id]);
            audit('update', 'member', $id, 'Updated member ' . $d['member_no']);
            return ['message' => 'Member details updated.', 'id' => $id];
        }

        $fee = 0.0;
        if (input('pay_registration')) {
            $fee = max(0, input_float('registration_amount', (float) setting('registration_fee', 0)));
        }
        $newId = MemberService::create($d, [
            'registration_fee' => $fee,
            'payment_method'   => in_array(input('payment_method'), ['cash', 'mobile_money', 'bank', 'cheque'], true) ? input('payment_method') : 'cash',
            'payment_ref'      => input('payment_ref') ?: null,
        ]);
        $no = DB::value('SELECT member_no FROM members WHERE id = ?', [$newId]);
        audit('create', 'member', $newId, "Registered member $no");
        return ['message' => "Member $no registered successfully.", 'id' => $newId, 'redirect' => "index.php?page=member&id=$newId"];
    },

    'set_status' => function () {
        post_only();
        Auth::authorize('members.manage');
        $m = MemberService::findOrFail(input_int('id'));
        $status = (string) input('status');
        if (!in_array($status, MemberService::STATUSES, true)) {
            throw new AppError('Invalid status.');
        }
        if ($status === 'exited' && DB::value("SELECT 1 FROM loans WHERE member_id = ? AND status IN ('active','approved','pending') LIMIT 1", [$m['id']])) {
            throw new AppError('This member has open loans and cannot exit the SACCO yet.');
        }
        DB::update('members', ['status' => $status], 'id = ?', [$m['id']]);
        audit('status', 'member', $m['id'], "Status of {$m['member_no']} changed to $status");
        return ['message' => 'Member status changed to ' . label($status) . '.'];
    },

    'delete' => function () {
        post_only();
        Auth::authorize('members.delete');
        $m = MemberService::findOrFail(input_int('id'));
        $hasHistory = DB::value('SELECT 1 FROM transactions WHERE member_id = ? LIMIT 1', [$m['id']])
            || DB::value('SELECT 1 FROM loans WHERE member_id = ? LIMIT 1', [$m['id']])
            || DB::value('SELECT 1 FROM loan_guarantors WHERE member_id = ? LIMIT 1', [$m['id']]);
        if ($hasHistory) {
            throw new AppError('This member has financial history and cannot be deleted. Change their status to "Exited" instead.');
        }
        DB::transaction(function () use ($m) {
            DB::query('DELETE FROM savings_accounts WHERE member_id = ?', [$m['id']]);
            DB::query('DELETE FROM share_accounts WHERE member_id = ?', [$m['id']]);
            DB::query('DELETE FROM members WHERE id = ?', [$m['id']]);
        });
        audit('delete', 'member', $m['id'], "Deleted member {$m['member_no']}");
        return ['message' => 'Member deleted.'];
    },
];
