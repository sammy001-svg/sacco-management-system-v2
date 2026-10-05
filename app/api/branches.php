<?php
defined('SACCO') or exit;

return [

    'list' => function () {
        Auth::authorize('branches.manage');
        $rows = DB::all('SELECT * FROM branches ORDER BY id');
        $members = DB::pairs('SELECT branch_id, COUNT(*) FROM members GROUP BY branch_id');
        $staff = DB::pairs('SELECT branch_id, COUNT(*) FROM users WHERE branch_id IS NOT NULL GROUP BY branch_id');
        $portfolio = DB::pairs("SELECT branch_id, SUM(balance) FROM loans WHERE status = 'active' GROUP BY branch_id");
        $savings = DB::pairs('SELECT m.branch_id, SUM(a.balance) FROM savings_accounts a JOIN members m ON m.id = a.member_id GROUP BY m.branch_id');
        foreach ($rows as &$r) {
            $id = (int) $r['id'];
            $r['members'] = (int) ($members[$id] ?? 0);
            $r['staff'] = (int) ($staff[$id] ?? 0);
            $r['portfolio'] = (float) ($portfolio[$id] ?? 0);
            $r['savings'] = (float) ($savings[$id] ?? 0);
        }
        return ['data' => $rows];
    },

    'save' => function () {
        post_only();
        Auth::authorize('branches.manage');
        $id = input_int('id');
        $d = Validator::make($_POST, [
            'code'     => 'required|string|max:20',
            'name'     => 'required|string|max:120',
            'location' => 'nullable|string|max:190',
            'phone'    => 'nullable|phone',
            'email'    => 'nullable|email|max:190',
            'status'   => 'required|in:active,inactive',
        ]);
        $d['code'] = strtoupper($d['code']);
        if (DB::value('SELECT id FROM branches WHERE code = ? AND id <> ?', [$d['code'], $id])) {
            throw new ValidationError(['code' => 'This branch code is already in use.']);
        }
        if ($id) {
            DB::update('branches', $d, 'id = ?', [$id]);
        } else {
            $id = DB::insert('branches', $d);
        }
        audit('save', 'branch', $id, "Saved branch {$d['name']}");
        return ['message' => "Branch “{$d['name']}” saved."];
    },

    'delete' => function () {
        post_only();
        Auth::authorize('branches.manage');
        $id = input_int('id');
        if (DB::value('SELECT 1 FROM members WHERE branch_id = ? LIMIT 1', [$id]) || DB::value('SELECT 1 FROM expenses WHERE branch_id = ? LIMIT 1', [$id])) {
            throw new AppError('This branch has members or records. Mark it inactive instead.');
        }
        if ((int) DB::value('SELECT COUNT(*) FROM branches') <= 1) {
            throw new AppError('At least one branch is required.');
        }
        DB::query('UPDATE users SET branch_id = NULL WHERE branch_id = ?', [$id]);
        DB::query('DELETE FROM branches WHERE id = ?', [$id]);
        audit('delete', 'branch', $id, 'Deleted branch');
        return ['message' => 'Branch deleted.'];
    },
];
