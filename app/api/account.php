<?php
defined('SACCO') or exit;

/** The signed-in user's own profile. */
return [

    'profile' => function () {
        post_only();
        $d = Validator::make($_POST, [
            'name'             => 'required|string|max:120',
            'phone'            => 'nullable|phone',
            'current_password' => 'nullable|string',
            'new_password'     => 'nullable|string|min:8|max:72',
        ]);
        $row = ['name' => $d['name'], 'phone' => $d['phone']];

        if ($d['new_password']) {
            $hash = DB::value('SELECT password_hash FROM users WHERE id = ?', [Auth::id()]);
            if (!$d['current_password'] || !password_verify($d['current_password'], (string) $hash)) {
                throw new ValidationError(['current_password' => 'Your current password is incorrect.']);
            }
            if (!preg_match('/[A-Za-z]/', $d['new_password']) || !preg_match('/\d/', $d['new_password'])) {
                throw new ValidationError(['new_password' => 'Use letters and numbers.']);
            }
            $row['password_hash'] = password_hash($d['new_password'], PASSWORD_DEFAULT);
        }
        DB::update('users', $row, 'id = ?', [Auth::id()]);
        audit('profile', 'user', Auth::id(), 'Updated own profile' . ($d['new_password'] ? ' and password' : ''));
        return ['message' => 'Your profile has been updated.' . ($d['new_password'] ? ' Password changed.' : '')];
    },
];
