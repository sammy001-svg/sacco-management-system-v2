<?php
defined('SACCO') or exit;

function mpesa_scoped(int $id): array
{
    $r = MpesaService::find($id);
    $own = Auth::user()['branch_id'] ?? null;
    if ($own && (int) $r['branch_id'] !== (int) $own) {
        throw new AppError('Payment request not found.', 404);
    }
    return $r;
}

function mpesa_filters(): array
{
    $where = 'WHERE 1=1';
    $params = [];
    [$bs, $bp] = branch_scope('r.branch_id');
    $where .= $bs;
    $params = [...$params, ...$bp];
    if (array_key_exists($st = (string) input('status', ''), MpesaService::STATUSES)) {
        $where .= ' AND r.status = ?';
        $params[] = $st;
    }
    if (array_key_exists($p = (string) input('purpose', ''), MpesaService::PURPOSES)) {
        $where .= ' AND r.purpose = ?';
        $params[] = $p;
    }
    if ($mid = input_int('member_id')) {
        $where .= ' AND r.member_id = ?';
        $params[] = $mid;
    }
    if (input('mine')) {
        $where .= ' AND r.requested_by = ?';
        $params[] = Auth::id();
    }
    if ($q = trim((string) input('search', ''))) {
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
        $phone = normalize_msisdn($q);
        $where .= ' AND (r.mpesa_receipt LIKE ? OR m.member_no LIKE ? OR r.phone = ?)';
        array_push($params, strtoupper($like), $like, $phone ?? $q);
    }
    if ($from = input_date('date_from')) {
        $where .= ' AND r.created_at >= ?';
        $params[] = "$from 00:00:00";
    }
    if ($to = input_date('date_to')) {
        $where .= ' AND r.created_at <= ?';
        $params[] = "$to 23:59:59";
    }
    return [$where, $params];
}

return [

    'list' => function () {
        Auth::authorize('mpesa.view');
        [$where, $params] = mpesa_filters();
        $res = paginate(
            'r.id, r.member_id, r.purpose, r.account_id, r.phone, r.amount, r.status, r.result_desc, r.mpesa_receipt, r.paid_amount, r.paid_at,
             r.confirmed_via, r.post_error, r.created_at, r.transaction_id, m.member_no, m.first_name, m.last_name, u.name AS requested_by_name, t.txn_ref',
            "FROM mpesa_requests r JOIN members m ON m.id = r.member_id LEFT JOIN users u ON u.id = r.requested_by LEFT JOIN transactions t ON t.id = r.transaction_id $where",
            $params, 'r.id DESC', 20, "FROM mpesa_requests r JOIN members m ON m.id = r.member_id $where"
        );
        foreach ($res['data'] as &$r) {
            $r['status_label'] = MpesaService::STATUSES[$r['status']] ?? $r['status'];
            $r['purpose_label'] = MpesaService::PURPOSES[$r['purpose']] ?? $r['purpose'];
            $r['phone_masked'] = mask_msisdn($r['phone']);
        }
        return $res;
    },

    'summary' => function () {
        Auth::authorize('mpesa.view');
        [$bs, $bp] = branch_scope('branch_id');
        $t = DB::row(
            "SELECT COUNT(*) AS requests, SUM(status IN ('paid','unposted')) AS paid, COALESCE(SUM(CASE WHEN status IN ('paid','unposted') THEN paid_amount END), 0) AS amount,
                    SUM(status = 'pending') AS pending, SUM(status IN ('failed','cancelled','timeout')) AS unsuccessful
             FROM mpesa_requests WHERE created_at >= ? $bs",
            [today() . ' 00:00:00', ...$bp]
        );
        return ['data' => $t + [
            'unposted'   => (int) DB::value("SELECT COUNT(*) FROM mpesa_requests WHERE status = 'unposted' $bs", $bp),
            'configured' => MpesaService::configured(),
            'enabled'    => MpesaService::enabled(),
            'env'        => config('mpesa.env'),
        ]];
    },

    'get' => function () {
        Auth::authorize('mpesa.view');
        return ['data' => mpesa_scoped(input_int('id'))];
    },

    /** Send the PIN prompt to the member's phone. */
    'stk' => function () {
        post_only();
        Auth::authorize('mpesa.request');
        $d = Validator::make($_POST, [
            'member_id'  => 'required|integer',
            'purpose'    => 'required|in:deposit,loan_repayment,share_purchase',
            'account_id' => 'nullable|integer',
            'loan_id'    => 'nullable|integer',
            'amount'     => 'required|numeric|min:1',
            'phone'      => 'required|string|max:20',
        ], ['member_id' => 'Member']);
        $m = MemberService::findOrFail($d['member_id']);
        $target = match ($d['purpose']) {
            'deposit'        => (int) $d['account_id'],
            'loan_repayment' => (int) $d['loan_id'],
            default          => ShareService::ensureAccount((int) $m['id']),
        };
        if (!$target) {
            throw new ValidationError([$d['purpose'] === 'loan_repayment' ? 'loan_id' : 'account_id' => 'Choose the account to pay into.']);
        }
        $r = MpesaService::request((int) $m['id'], $d['purpose'], $target, (float) $d['amount'], $d['phone']);
        audit('mpesa_stk', 'mpesa_request', $r['id'], "STK push of " . money($r['amount']) . " to {$r['phone_masked']} for {$m['member_no']} ({$r['purpose_label']})");
        return ['message' => 'Payment prompt sent to ' . $r['phone_masked'] . '. Ask the member to enter their M-Pesa PIN.', 'data' => $r];
    },

    /** Polled by the waiting screen; asks Safaricom directly when the callback is slow. */
    'status' => function () {
        Auth::authorize('mpesa.view');
        $r = mpesa_scoped(input_int('id'));
        $age = time() - strtotime($r['created_at']);
        if ($r['status'] === 'pending' && $age > 25 && (!$r['last_query_at'] || strtotime($r['last_query_at']) < time() - 12)) {
            try {
                $r = MpesaService::query((int) $r['id']);
            } catch (AppError $e) {
                // Keep waiting; the callback or cron will settle it.
            }
        }
        $out = ['data' => $r];
        if ($r['status'] === 'paid' && $r['txn_ref']) {
            $tx = DB::row('SELECT t.*, m.first_name, m.last_name FROM transactions t JOIN members m ON m.id = t.member_id WHERE t.id = ?', [$r['transaction_id']]);
            $out['receipt'] = [
                'ref' => $tx['txn_ref'], 'amount' => (float) $r['paid_amount'], 'balance' => $tx['balance_after'], 'id' => (int) $tx['id'],
                'member' => "{$tx['first_name']} {$tx['last_name']}", 'account' => $r['target'], 'type' => 'M-Pesa ' . $r['purpose_label'],
            ];
        }
        return $out;
    },

    'query' => function () {
        post_only();
        Auth::authorize('mpesa.view');
        $r = mpesa_scoped(input_int('id'));
        $r = MpesaService::query((int) $r['id']);
        return ['message' => $r['status'] === 'pending' ? 'M-Pesa is still processing this payment.' : "M-Pesa says: {$r['status_label']}.", 'data' => $r];
    },

    /** Paid but not posted: post again, optionally into the member's savings instead. */
    'repost' => function () {
        post_only();
        Auth::authorize('mpesa.manage');
        $r = mpesa_scoped(input_int('id'));
        if ($r['status'] !== 'unposted') {
            throw new AppError('Only paid requests that failed to post can be reposted.');
        }
        $r = MpesaService::post((int) $r['id'], input('target') === 'savings' ? 'savings' : null);
        if ($r['status'] !== 'paid') {
            throw new AppError('Still could not post: ' . $r['post_error']);
        }
        audit('mpesa_repost', 'mpesa_request', $r['id'], "Reposted M-Pesa {$r['mpesa_receipt']} as {$r['txn_ref']}");
        return ['message' => 'Payment posted as ' . $r['txn_ref'] . '.'];
    },
];
