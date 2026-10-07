<?php
defined('SACCO') or exit;

/** Two-step login for the signed-in user. */
return [

    'status' => function () {
        $u = DB::row('SELECT totp_enabled, recovery_codes FROM users WHERE id = ?', [Auth::id()]);
        return ['data' => [
            'enabled'  => (bool) $u['totp_enabled'],
            'recovery' => count(json_decode((string) $u['recovery_codes'], true) ?: []),
        ]];
    },

    /** Step 1: a new secret, held in the session until confirmed. */
    'setup' => function () {
        post_only();
        $secret = Totp::secret();
        $_SESSION['totp_setup'] = $secret;
        $u = Auth::user();
        return ['data' => ['secret' => $secret, 'uri' => Totp::uri($secret, $u['email'], (string) setting('sacco_name', 'SaccoPro'))]];
    },

    /** Step 2: prove the app works, then enable and hand out recovery codes once. */
    'enable' => function () {
        post_only();
        $secret = $_SESSION['totp_setup'] ?? null;
        if (!$secret) {
            throw new AppError('Start the setup again.');
        }
        if (!Totp::verify($secret, (string) input('code', ''))) {
            throw new ValidationError(['code' => 'That code is not valid. Check the time on your phone and try the newest code.']);
        }
        [$codes, $hashed] = Totp::recoveryCodes();
        DB::update('users', ['totp_secret' => $secret, 'totp_enabled' => 1, 'recovery_codes' => $hashed], 'id = ?', [Auth::id()]);
        unset($_SESSION['totp_setup']);
        audit('2fa_enable', 'user', Auth::id(), 'Enabled two-step login');
        return ['message' => 'Two-step login is on.', 'data' => ['recovery_codes' => $codes]];
    },

    'disable' => function () {
        post_only();
        $hash = DB::value('SELECT password_hash FROM users WHERE id = ?', [Auth::id()]);
        if (!password_verify((string) ($_POST['password'] ?? ''), (string) $hash)) {
            throw new ValidationError(['password' => 'Your password is incorrect.']);
        }
        DB::update('users', ['totp_secret' => null, 'totp_enabled' => 0, 'recovery_codes' => null], 'id = ?', [Auth::id()]);
        audit('2fa_disable', 'user', Auth::id(), 'Disabled two-step login');
        return ['message' => 'Two-step login is off.'];
    },

    /** Administrators can reset a user who lost their phone. */
    'reset' => function () {
        post_only();
        Auth::authorize('users.manage');
        $id = input_int('user_id');
        DB::update('users', ['totp_secret' => null, 'totp_enabled' => 0, 'recovery_codes' => null], 'id = ?', [$id]);
        audit('2fa_reset', 'user', $id, 'Reset two-step login');
        return ['message' => 'Two-step login reset. The user signs in with their password and can set it up again.'];
    },
];
