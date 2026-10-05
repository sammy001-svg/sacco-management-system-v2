<?php
defined('SACCO') or exit;

function shares_filters(): array
{
    $where = 'WHERE 1=1';
    $params = [];
    [$bs, $bp] = branch_scope('m.branch_id');
    $where .= $bs;
    $params = [...$params, ...$bp];
    if (($q = trim((string) input('search', ''))) !== '') {
        [$ss, $sp] = member_search_clause($q);
        $where .= $ss;
        $params = [...$params, ...$sp];
    }
    if (input('holders_only')) {
        $where .= ' AND s.shares > 0';
    }
    return [$where, $params];
}

return [

    'list' => function () {
        Auth::authorize('shares.view');
        [$where, $params] = shares_filters();
        $order = sort_clause(['shares' => 's.shares', 'balance' => 's.balance', 'member' => 'm.first_name'], 's.balance DESC');
        return paginate(
            's.id, s.member_id, s.shares, s.balance, s.updated_at, m.member_no, m.first_name, m.last_name, m.phone, m.status',
            "FROM share_accounts s JOIN members m ON m.id = s.member_id $where",
            $params, $order
        );
    },

    'export' => function () {
        Auth::authorize('shares.view');
        [$where, $params] = shares_filters();
        export_csv('share-register', [
            'member_no' => 'Member No', 'member' => 'Member', 'shares' => 'Shares', 'balance' => 'Share Capital', 'status' => 'Member Status',
        ], "SELECT s.*, m.member_no, m.status, CONCAT(m.first_name, ' ', m.last_name) AS member
            FROM share_accounts s JOIN members m ON m.id = s.member_id $where ORDER BY s.balance DESC", $params);
    },

    'stats' => function () {
        Auth::authorize('shares.view');
        [$bs, $bp] = branch_scope('m.branch_id');
        $t = DB::row(
            "SELECT COALESCE(SUM(s.balance),0) AS capital, COALESCE(SUM(s.shares),0) AS shares, SUM(s.shares > 0) AS holders
             FROM share_accounts s JOIN members m ON m.id = s.member_id WHERE 1=1 $bs",
            $bp
        );
        $min = (float) setting('min_share_capital', 0);
        $below = $min > 0 ? (int) DB::value(
            "SELECT COUNT(*) FROM share_accounts s JOIN members m ON m.id = s.member_id WHERE m.status = 'active' AND s.balance < ? $bs",
            [$min, ...$bp]
        ) : 0;
        return ['data' => [
            'capital'     => (float) $t['capital'],
            'shares'      => (int) $t['shares'],
            'holders'     => (int) $t['holders'],
            'price'       => ShareService::price(),
            'min_capital' => $min,
            'below_min'   => $below,
        ]];
    },

    'buy' => function () {
        post_only();
        Auth::authorize('shares.transact');
        $d = Validator::make($_POST, [
            'member_id'          => 'required|integer',
            'shares'             => 'required|integer|min:1|max:10000000',
            'source'             => 'required|in:cash,savings',
            'payment_method'     => 'nullable|in:cash,mobile_money,bank,cheque',
            'savings_account_id' => 'nullable|integer',
            'payment_ref'        => 'nullable|string|max:80',
            'narration'          => 'nullable|string|max:255',
        ], ['member_id' => 'Member']);
        $m = MemberService::findOrFail($d['member_id']);
        $r = ShareService::purchase((int) $m['id'], $d['shares'], $d + ['payment_method' => $d['payment_method'] ?? 'cash']);
        audit('share_purchase', 'member', $m['id'], "{$d['shares']} shares bought for {$m['member_no']} ({$r['transaction']['ref']})");
        return [
            'message' => "{$d['shares']} shares (" . money($r['amount']) . ") added for {$m['first_name']}. Holding: " . number_format($r['shares']) . ' shares.',
            'ref'     => $r['transaction']['ref'],
            'receipt' => ['ref' => $r['transaction']['ref'], 'amount' => $r['amount'], 'balance' => $r['balance'], 'member' => member_name($m), 'account' => 'Share capital', 'type' => 'Share Purchase'],
        ];
    },

    'transfer' => function () {
        post_only();
        Auth::authorize('shares.transact');
        $d = Validator::make($_POST, [
            'from_member_id' => 'required|integer',
            'to_member_id'   => 'required|integer',
            'shares'         => 'required|integer|min:1',
            'narration'      => 'nullable|string|max:200',
        ], ['from_member_id' => 'Transferring member', 'to_member_id' => 'Receiving member']);
        $from = MemberService::findOrFail($d['from_member_id']);
        $to = MemberService::findOrFail($d['to_member_id']);
        $r = ShareService::transfer((int) $from['id'], (int) $to['id'], $d['shares'], $d);
        audit('share_transfer', 'member', $from['id'], "{$d['shares']} shares {$from['member_no']} → {$to['member_no']}");
        return ['message' => "{$d['shares']} shares (" . money($r['value']) . ") transferred to {$to['first_name']} {$to['last_name']}."];
    },
];
