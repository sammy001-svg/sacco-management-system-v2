<?php
defined('SACCO') or exit;

/** Branch whose vault the supervisor is working with. */
function vault_branch(): int
{
    $own = Auth::user()['branch_id'] ?? null;
    if ($own) {
        return (int) $own;
    }
    $b = input_int('branch_id');
    return $b ?: VaultService::defaultBranch();
}

return [

    'summary' => function () {
        Auth::authorize('tills.supervise');
        $branch = vault_branch();
        $tills = [];
        foreach (DB::all("SELECT id FROM till_sessions WHERE status = 'open' AND (branch_id = ? OR (branch_id IS NULL AND ? = ?))", [$branch, $branch, VaultService::defaultBranch()]) as $t) {
            $tills[] = TillService::summary((int) $t['id']);
        }
        $pending = DB::all(
            "SELECT m.*, u.name AS user_name FROM cash_movements m LEFT JOIN users u ON u.id = m.user_id
             WHERE m.branch_id = ? AND m.status = 'pending' ORDER BY m.id",
            [$branch]
        );
        foreach ($pending as &$p) {
            $p['type_label'] = VaultService::TYPES[$p['type']] ?? $p['type'];
            $p['mine'] = (int) $p['requested_by'] === Auth::id();
        }
        return ['data' => [
            'branch_id'   => $branch,
            'branch_name' => DB::value('SELECT name FROM branches WHERE id = ?', [$branch]),
            'balance'     => VaultService::balance($branch),
            'tills'       => $tills,
            'in_tills'    => round(array_sum(array_column($tills, 'expected_now')), 2),
            'pending'     => $pending,
        ]];
    },

    'movements' => function () {
        Auth::authorize('tills.supervise');
        $where = 'WHERE m.branch_id = ?';
        $params = [vault_branch()];
        if (array_key_exists($t = (string) input('type', ''), VaultService::TYPES)) {
            $where .= ' AND m.type = ?';
            $params[] = $t;
        }
        if ($from = input_date('date_from')) {
            $where .= ' AND m.created_at >= ?';
            $params[] = "$from 00:00:00";
        }
        if ($to = input_date('date_to')) {
            $where .= ' AND m.created_at <= ?';
            $params[] = "$to 23:59:59";
        }
        $res = paginate(
            'm.*, u.name AS user_name, r.name AS requested_by_name, a.name AS approved_by_name',
            "FROM cash_movements m LEFT JOIN users u ON u.id = m.user_id LEFT JOIN users r ON r.id = m.requested_by LEFT JOIN users a ON a.id = m.approved_by $where",
            $params, 'm.id DESC', 15, "FROM cash_movements m $where"
        );
        foreach ($res['data'] as &$r) {
            $r['type_label'] = VaultService::TYPES[$r['type']] ?? $r['type'];
        }
        return $res;
    },

    'approve' => function () {
        post_only();
        Auth::authorize('tills.supervise');
        $m = VaultService::approve(input_int('id'));
        audit('vault_release', 'cash_movement', $m['id'], (VaultService::TYPES[$m['type']] ?? $m['type']) . ' of ' . money($m['amount']) . ' released');
        $teller = DB::value('SELECT name FROM users WHERE id = ?', [$m['user_id']]);
        return ['message' => money($m['amount']) . ' released to ' . $teller . ($m['type'] === 'float' ? ' as their opening float — their till is now open.' : ' as a top-up.') . ' Count it out and hand it over.'];
    },

    'reject' => function () {
        post_only();
        Auth::authorize('tills.supervise');
        $d = Validator::make($_POST, ['id' => 'required|integer', 'note' => 'required|string|min:3|max:255'], ['note' => 'Reason']);
        $m = VaultService::reject($d['id'], $d['note']);
        audit('vault_reject', 'cash_movement', $m['id'], 'Rejected ' . (VaultService::TYPES[$m['type']] ?? $m['type']) . ": {$d['note']}");
        return ['message' => 'Request rejected.'];
    },

    'bank' => function () {
        post_only();
        Auth::authorize('tills.supervise');
        $d = Validator::make($_POST, [
            'direction' => 'required|in:to,from', 'amount' => 'required|numeric|min:1',
            'reference' => 'nullable|string|max:80', 'note' => 'nullable|string|max:255',
        ]);
        $branch = vault_branch();
        VaultService::bank($branch, $d['direction'], $d['amount'], $d['reference'], $d['note']);
        audit('vault_bank', 'vault', $branch, ($d['direction'] === 'to' ? 'Banked ' : 'Withdrew from bank ') . money($d['amount']));
        return ['message' => $d['direction'] === 'to'
            ? money($d['amount']) . ' taken from the vault and banked.'
            : money($d['amount']) . ' withdrawn from the bank into the vault.'];
    },

    'count' => function () {
        post_only();
        Auth::authorize('tills.supervise');
        $d = Validator::make($_POST, ['counted' => 'nullable|numeric|min:0', 'note' => 'nullable|string|max:255']);
        $denoms = [];
        foreach ((array) ($_POST['denoms'] ?? []) as $n => $q) {
            if (in_array((int) $n, TillService::DENOMINATIONS, true) && (int) $q > 0) {
                $denoms[(int) $n] = (int) $q;
            }
        }
        $counted = $denoms ? array_sum(array_map(fn($n, $q) => $n * $q, array_keys($denoms), $denoms)) : (float) $d['counted'];
        if (!$denoms && $d['counted'] === null) {
            throw new ValidationError(['counted' => 'Enter the counted amount.']);
        }
        $branch = vault_branch();
        $r = VaultService::count($branch, $counted, $denoms, $d['note']);
        audit('vault_count', 'vault', $branch, 'Vault counted ' . money($counted) . ', variance ' . money($r['variance']));
        return ['message' => 'Vault count recorded. ' . ($r['variance'] == 0.0 ? 'It balanced exactly.' : ($r['variance'] < 0 ? 'Shortage of ' . money(-$r['variance']) : 'Overage of ' . money($r['variance'])) . ' posted to Cash Over / Short.')];
    },
];
