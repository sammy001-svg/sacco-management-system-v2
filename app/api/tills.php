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

return [

    'mine' => function () {
        Auth::authorize('tills.use');
        $open = TillService::openFor((int) Auth::id());
        return ['data' => [
            'session'       => $open ? TillService::summary((int) $open['id']) : null,
            'required'      => (string) setting('require_till', '1') === '1',
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

    'open' => function () {
        post_only();
        Auth::authorize('tills.use');
        $d = Validator::make($_POST, ['opening_float' => 'required|numeric|min:0'], ['opening_float' => 'Opening float']);
        $s = TillService::open((int) Auth::id(), $d['opening_float']);
        audit('till_open', 'till', $s['id'], 'Opened till with float ' . money($d['opening_float']));
        return ['message' => 'Till opened with a float of ' . money($d['opening_float']) . '.', 'data' => $s];
    },

    'close' => function () {
        post_only();
        Auth::authorize('tills.use');
        $s = till_scoped(input_int('id'));
        $d = Validator::make($_POST, ['counted_cash' => 'nullable|numeric|min:0', 'notes' => 'nullable|string|max:255']);
        $denoms = array_map('intval', (array) ($_POST['denoms'] ?? []));
        if (!array_filter($denoms) && $d['counted_cash'] === null) {
            throw new ValidationError(['counted_cash' => 'Count the cash in the drawer.']);
        }
        $r = TillService::close((int) $s['id'], (float) $d['counted_cash'], $denoms, $d['notes']);
        $v = (float) $r['variance'];
        audit('till_close', 'till', $s['id'], "Closed till of {$s['user_name']}: counted " . money($r['counted_cash']) . ', variance ' . money($v));
        return [
            'message' => 'Till closed. ' . ($v == 0.0 ? 'Cash balanced exactly.' : ($v < 0 ? 'Shortage of ' . money(-$v) : 'Overage of ' . money($v)) . ' posted to Cash Over / Short.'),
            'data'    => $r,
        ];
    },
];
