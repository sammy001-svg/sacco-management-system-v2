<?php
defined('SACCO') or exit;

function till_scoped(int $id): array
{
    $s = TillService::summary($id);
    $mine = (int) $s['user_id'] === Auth::id();
    $own = Auth::user()['branch_id'] ?? null;
    if (!$mine && (!Auth::can('tills.supervise') || ($own && (int) $own !== (int) $s['branch_id']))) {
        throw new AppError('Till session not found.', 404);
    }
    return $s;
}

function clean_denoms(): array
{
    $out = [];
    foreach ((array) ($_POST['denoms'] ?? []) as $note => $qty) {
        if (in_array((int) $note, TillService::DENOMINATIONS, true) && (int) $qty > 0) {
            $out[(int) $note] = (int) $qty;
        }
    }
    return $out;
}

return [

    /** Everything the teller needs about their own drawer. */
    'mine' => function () {
        Auth::authorize('tills.use');
        $uid = (int) Auth::id();
        $open = TillService::openFor($uid);
        $pending = DB::all("SELECT id, type, amount, created_at, note FROM cash_movements WHERE user_id = ? AND status = 'pending' ORDER BY id", [$uid]);
        $branch = VaultService::branchOfUser($uid);
        $today = DB::row(
            "SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN flow = 'in' THEN amount END), 0) AS cash_in, COALESCE(SUM(CASE WHEN flow = 'out' THEN amount END), 0) AS cash_out
             FROM transactions WHERE created_by = ? AND created_at >= ?",
            [$uid, today() . ' 00:00:00']
        );
        return ['data' => [
            'session'       => $open ? TillService::summary((int) $open['id']) : null,
            'pending'       => $pending,
            'required'      => (string) setting('require_till', '1') === '1',
            'supervisor'    => Auth::can('tills.supervise'),
            'vault_balance' => VaultService::balance($branch),
            'today'         => $today,
            'denominations' => TillService::DENOMINATIONS,
        ]];
    },

    'list' => function () {
        Auth::authorize('tills.use');
        $where = 'WHERE 1=1';
        $params = [];
        if (!Auth::can('tills.supervise')) {
            $where .= ' AND t.user_id = ?';
            $params[] = Auth::id();
        } else {
            [$bs, $bp] = branch_scope('t.branch_id');
            $where .= $bs;
            $params = [...$params, ...$bp];
            if ($u = input_int('user_id')) {
                $where .= ' AND t.user_id = ?';
                $params[] = $u;
            }
        }
        if (in_array($st = (string) input('status', ''), ['open', 'closed'], true)) {
            $where .= ' AND t.status = ?';
            $params[] = $st;
        }
        if ($from = input_date('date_from')) {
            $where .= ' AND t.opened_at >= ?';
            $params[] = "$from 00:00:00";
        }
        if ($to = input_date('date_to')) {
            $where .= ' AND t.opened_at <= ?';
            $params[] = "$to 23:59:59";
        }
        $res = paginate(
            't.*, u.name AS user_name, b.name AS branch_name, c.name AS closed_by_name',
            "FROM till_sessions t JOIN users u ON u.id = t.user_id LEFT JOIN branches b ON b.id = t.branch_id LEFT JOIN users c ON c.id = t.closed_by $where",
            $params, 't.id DESC', 15, "FROM till_sessions t $where"
        );
        foreach ($res['data'] as &$r) {
            if ($r['status'] === 'open') {
                $r['expected_cash'] = TillService::summary((int) $r['id'])['expected_now'];
            }
        }
        return $res;
    },

    'get' => function () {
        Auth::authorize('tills.use');
        return ['data' => till_scoped(input_int('id'))];
    },

    'request_float' => function () {
        post_only();
        Auth::authorize('tills.use');
        $d = Validator::make($_POST, ['amount' => 'required|numeric|min:0', 'note' => 'nullable|string|max:255'], ['amount' => 'Float']);
        $r = VaultService::requestFloat((int) Auth::id(), $d['amount'], $d['note']);
        audit('till_float', 'till', $r['session'] ?? null, 'Float of ' . money($d['amount']) . ($r['status'] === 'open' ? ' issued' : ' requested'));
        return ['message' => $r['status'] === 'open'
            ? 'Till opened with a float of ' . money($d['amount']) . '.'
            : 'Float of ' . money($d['amount']) . ' requested. Your till opens as soon as a supervisor releases the cash.'] + $r;
    },

    'request_topup' => function () {
        post_only();
        Auth::authorize('tills.use');
        $d = Validator::make($_POST, ['amount' => 'required|numeric|min:1', 'note' => 'nullable|string|max:255']);
        $r = VaultService::requestTopUp((int) Auth::id(), $d['amount'], $d['note']);
        audit('till_topup', 'till', null, 'Top-up of ' . money($d['amount']) . ' ' . $r['status']);
        return ['message' => $r['status'] === 'completed' ? 'Top-up of ' . money($d['amount']) . ' added to your till.' : 'Top-up of ' . money($d['amount']) . ' requested from the vault.'] + $r;
    },

    'drop' => function () {
        post_only();
        Auth::authorize('tills.use');
        $d = Validator::make($_POST, ['amount' => 'required|numeric|min:1', 'note' => 'nullable|string|max:255']);
        VaultService::drop((int) Auth::id(), $d['amount'], clean_denoms(), $d['note']);
        audit('till_drop', 'till', null, 'Cash drop of ' . money($d['amount']) . ' to the vault');
        return ['message' => 'Cash drop of ' . money($d['amount']) . ' recorded. Hand the cash to the vault custodian.'];
    },

    'cancel_request' => function () {
        post_only();
        Auth::authorize('tills.use');
        VaultService::cancel(input_int('id'));
        return ['message' => 'Request cancelled.'];
    },

    'close' => function () {
        post_only();
        Auth::authorize('tills.use');
        $s = till_scoped(input_int('id'));
        $d = Validator::make($_POST, ['counted_cash' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:255']);
        $denoms = clean_denoms();
        if (!$denoms && $d['counted_cash'] === null) {
            throw new ValidationError(['counted_cash' => 'Count the cash in the drawer.']);
        }
        $r = TillService::close((int) $s['id'], (float) $d['counted_cash'], $denoms, $d['notes']);
        $v = (float) $r['variance'];
        audit('till_close', 'till', $s['id'], "Closed till of {$s['user_name']}: counted " . money($r['counted_cash']) . ', variance ' . money($v));
        return [
            'message' => 'Till closed and ' . money($r['counted_cash']) . ' surrendered to the vault. ' . ($v == 0.0 ? 'Cash balanced exactly.' : ($v < 0 ? 'Shortage of ' . money(-$v) : 'Overage of ' . money($v)) . ' posted to Cash Over / Short.'),
            'data'    => $r,
            'report'  => 'index.php?page=till_report&id=' . $s['id'],
        ];
    },
];
