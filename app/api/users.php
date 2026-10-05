<?php
defined('SACCO') or exit;

return [

    'list' => function () {
        Auth::authorize('users.manage');
        $where = 'WHERE 1=1';
        $params = [];
        if (($q = trim((string) input('search', ''))) !== '') {
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
            $where .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
            array_push($params, $like, $like);
        }
        if (array_key_exists($role = (string) input('role', ''), Auth::roles())) {
            $where .= ' AND u.role = ?';
            $params[] = $role;
        }
        return paginate(
            'u.id, u.name, u.email, u.phone, u.role, u.status, u.branch_id, u.last_login_at, u.created_at, u.locked_until, b.name AS branch_name',
            "FROM users u LEFT JOIN branches b ON b.id = u.branch_id $where",
            $params, sort_clause(['name' => 'u.name', 'role' => 'u.role', 'last_login_at' => 'u.last_login_at'], 'u.id')
        );
    },

    'save' => function () {
        post_only();
        Auth::authorize('users.manage');
        $id = input_int('id');
        $d = Validator::make($_POST, [
            'name'      => 'required|string|max:120',
            'email'     => 'required|email|max:190',
            'phone'     => 'nullable|phone',
            'role'      => 'required|in:' . implode(',', array_keys(Auth::roles())),
            'branch_id' => 'nullable|integer|exists:branches,id',
            'status'    => 'required|in:active,inactive',
            'password'  => ($id ? 'nullable' : 'required') . '|string|min:8|max:72',
        ]);
        if (DB::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$d['email'], $id])) {
            throw new ValidationError(['email' => 'Another user already uses this email.']);
        }
        if ($d['password'] && (!preg_match('/[A-Za-z]/', $d['password']) || !preg_match('/\d/', $d['password']))) {
            throw new ValidationError(['password' => 'Password must contain letters and numbers.']);
        }
        if ($id === Auth::id() && ($d['status'] !== 'active' || $d['role'] !== 'admin')) {
            throw new ValidationError(['role' => 'You cannot demote or deactivate your own account.']);
        }

        $row = $d;
        unset($row['password']);
        if ($d['password']) {
            $row['password_hash'] = password_hash($d['password'], PASSWORD_DEFAULT);
            $row['failed_logins'] = 0;
            $row['locked_until'] = null;
        }
        if ($id) {
            DB::update('users', $row, 'id = ?', [$id]);
        } else {
            $id = DB::insert('users', $row);
        }
        audit('save', 'user', $id, "Saved user {$d['email']} ({$d['role']})" . ($d['password'] ? ' with new password' : ''));
        return ['message' => "User {$d['name']} saved."];
    },

    'unlock' => function () {
        post_only();
        Auth::authorize('users.manage');
        $id = input_int('id');
        DB::update('users', ['failed_logins' => 0, 'locked_until' => null], 'id = ?', [$id]);
        audit('unlock', 'user', $id, 'Unlocked user account');
        return ['message' => 'Account unlocked.'];
    },
];
