<?php
defined('SACCO') or exit;

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function attempt(string $email, string $password): array
    {
        $user = DB::row('SELECT * FROM users WHERE email = ?', [strtolower(trim($email))]);

        if (!$user) {
            // Spend comparable time so response timing does not reveal valid emails.
            password_verify($password, password_hash('timing-equaliser', PASSWORD_DEFAULT));
            throw new AppError('Invalid email or password.', 401);
        }

        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $mins = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
            throw new AppError("Too many failed attempts. Try again in $mins minute(s).", 423);
        }

        if (!password_verify($password, $user['password_hash'])) {
            $fails = (int) $user['failed_logins'] + 1;
            $locked = $fails >= (int) config('security.max_failed_logins', 5);
            DB::update('users', [
                'failed_logins' => $locked ? 0 : $fails,
                'locked_until'  => $locked ? date('Y-m-d H:i:s', time() + 60 * (int) config('security.lockout_minutes', 15)) : null,
            ], 'id = ?', [$user['id']]);
            [self::$user, self::$loaded] = [['id' => $user['id']], true];
            audit('login_failed', 'user', $user['id'], 'Failed login attempt' . ($locked ? ' — account locked' : ''));
            [self::$user, self::$loaded] = [null, false];
            throw new AppError('Invalid email or password.', 401);
        }

        if ($user['status'] !== 'active') {
            throw new AppError('Your account is inactive. Please contact the administrator.', 403);
        }

        $update = ['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now()];
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $update['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        DB::update('users', $update, 'id = ?', [$user['id']]);

        return $user;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['last_activity'] = time();
        unset($_SESSION['csrf']);
        self::$loaded = false;
        self::$user = null;
    }

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;

        $uid = $_SESSION['uid'] ?? null;
        if (!$uid) {
            return null;
        }

        $idle = (int) config('session.idle_timeout', 7200);
        if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > $idle) {
            self::logout();
            return null;
        }
        $_SESSION['last_activity'] = time();

        $u = DB::row(
            'SELECT u.id, u.branch_id, u.name, u.email, u.phone, u.role, u.status, u.last_login_at, b.name AS branch_name
             FROM users u LEFT JOIN branches b ON b.id = u.branch_id WHERE u.id = ?',
            [$uid]
        );
        if (!$u || $u['status'] !== 'active') {
            self::logout();
            return null;
        }
        return self::$user = $u;
    }

    /** CLI / background jobs: act as a given user without a session. */
    public static function actAs(?int $userId): void
    {
        self::$loaded = true;
        self::$user = $userId
            ? DB::row('SELECT id, branch_id, name, email, role, status FROM users WHERE id = ?', [$userId])
            : null;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function role(): ?string
    {
        return self::user()['role'] ?? null;
    }

    public static function can(string $permission): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        if ($u['role'] === 'admin') {
            return true;
        }
        return in_array($u['role'], permissions()[$permission] ?? [], true);
    }

    public static function authorize(string $permission): void
    {
        if (!self::can($permission)) {
            throw new AppError('You do not have permission to perform this action.', 403);
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            session_destroy();
        }
        self::$user = null;
        self::$loaded = true;
    }

    public static function roles(): array
    {
        return [
            'admin'        => 'Administrator',
            'manager'      => 'Manager',
            'loan_officer' => 'Loan Officer',
            'teller'       => 'Teller',
            'accountant'   => 'Accountant',
        ];
    }
}
