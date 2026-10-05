<?php
defined('SACCO') or exit;

return [

    'list' => function () {
        Auth::authorize('audit.view');
        $where = 'WHERE 1=1';
        $params = [];
        if ($uid = input_int('user_id')) {
            $where .= ' AND a.user_id = ?';
            $params[] = $uid;
        }
        if (($action = (string) input('action', '')) !== '' && preg_match('/^[a-z_]+$/', $action)) {
            $where .= ' AND a.action = ?';
            $params[] = $action;
        }
        if (($q = trim((string) input('search', ''))) !== '') {
            $where .= ' AND a.description LIKE ?';
            $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
        }
        if ($from = input_date('date_from')) {
            $where .= ' AND a.created_at >= ?';
            $params[] = "$from 00:00:00";
        }
        if ($to = input_date('date_to')) {
            $where .= ' AND a.created_at <= ?';
            $params[] = "$to 23:59:59";
        }
        return paginate(
            'a.*, u.name AS user_name, u.role AS user_role',
            "FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id $where",
            $params, 'a.id DESC', 25, "FROM audit_logs a $where"
        );
    },

    'actions' => function () {
        Auth::authorize('audit.view');
        return ['data' => array_column(DB::all('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action')];
    },
];
