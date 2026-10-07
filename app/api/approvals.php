<?php
defined('SACCO') or exit;

return [

    'list' => function () {
        $where = 'WHERE 1=1';
        $params = [];
        if (!Auth::can('approvals.decide')) {
            $where .= ' AND a.requested_by = ?';  // makers see their own requests
            $params[] = Auth::id();
        } elseif ($own = Auth::user()['branch_id'] ?? null) {
            $where .= ' AND (a.branch_id = ? OR a.branch_id IS NULL)';
            $params[] = (int) $own;
        }
        if (in_array($st = (string) input('status', 'pending'), ['pending', 'approved', 'rejected', 'cancelled'], true)) {
            $where .= ' AND a.status = ?';
            $params[] = $st;
        }
        if (array_key_exists($t = (string) input('type', ''), ApprovalService::TYPES)) {
            $where .= ' AND a.type = ?';
            $params[] = $t;
        }
        $res = paginate(
            'a.id, a.type, a.summary, a.amount, a.status, a.created_at, a.decided_at, a.note, a.result, a.requested_by, a.member_id,
             r.name AS requested_by_name, d.name AS decided_by_name, b.name AS branch_name',
            "FROM approval_requests a JOIN users r ON r.id = a.requested_by LEFT JOIN users d ON d.id = a.decided_by LEFT JOIN branches b ON b.id = a.branch_id $where",
            $params, 'a.id DESC', 20, "FROM approval_requests a $where"
        );
        $me = Auth::id();
        $decide = Auth::can('approvals.decide');
        foreach ($res['data'] as &$r) {
            $r['type_label'] = ApprovalService::TYPES[$r['type']] ?? $r['type'];
            $r['can_decide'] = $decide && $r['status'] === 'pending' && (int) $r['requested_by'] !== $me;
            $r['can_cancel'] = $r['status'] === 'pending' && (int) $r['requested_by'] === $me;
        }
        return $res;
    },

    'count' => function () {
        return ['data' => Auth::can('approvals.decide') ? ApprovalService::pendingCount() : 0];
    },

    'approve' => function () {
        post_only();
        Auth::authorize('approvals.decide');
        $r = ApprovalService::approve(input_int('id'), input('note') ?: null);
        audit('approve', 'approval', $r['request']['id'], "Approved #{$r['request']['id']} {$r['request']['summary']} — {$r['result']}");
        return ['message' => "Request #{$r['request']['id']} approved. {$r['result']}."];
    },

    'reject' => function () {
        post_only();
        Auth::authorize('approvals.decide');
        $d = Validator::make($_POST, ['id' => 'required|integer', 'note' => 'required|string|min:3|max:255'], ['note' => 'Reason']);
        $r = ApprovalService::reject($d['id'], $d['note']);
        audit('reject', 'approval', $r['id'], "Rejected #{$r['id']} {$r['summary']}: {$d['note']}");
        return ['message' => "Request #{$r['id']} rejected."];
    },

    'cancel' => function () {
        post_only();
        $r = ApprovalService::cancel(input_int('id'));
        audit('cancel', 'approval', $r['id'], "Cancelled request #{$r['id']}");
        return ['message' => "Request #{$r['id']} cancelled."];
    },
];
