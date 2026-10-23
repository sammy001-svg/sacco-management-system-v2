<?php
defined('SACCO') or exit;

/* ---------------------------------------------------------------------
 | Errors
 * ------------------------------------------------------------------- */

/** A business-rule error whose message is safe to show to the user. */
class AppError extends RuntimeException
{
    public function __construct(string $message, public int $status = 400)
    {
        parent::__construct($message);
    }
}

/** Field-level validation failure (HTTP 422). */
class ValidationError extends AppError
{
    public function __construct(public array $errors, string $message = 'Please correct the highlighted fields.')
    {
        parent::__construct($message, 422);
    }
}

/* ---------------------------------------------------------------------
 | Config, settings, input
 * ------------------------------------------------------------------- */

function config(string $key, mixed $default = null): mixed
{
    $value = $GLOBALS['config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

/**
 * True while the system itself is doing work (installer, demo seeding,
 * migrations, cron/CLI). Operator controls such as teller tills and
 * maker-checker approvals apply to people, not to these processes.
 */
function system_process(?bool $on = null): bool
{
    static $flag = false;
    if ($on !== null) {
        $flag = $on;
    }
    return $flag || PHP_SAPI === 'cli';
}

function is_installed(): bool
{
    try {
        return (int) DB::value('SELECT COUNT(*) FROM users') > 0;
    } catch (Throwable) {
        return false;
    }
}

function permissions(): array
{
    static $map = null;
    return $map ??= require APP_PATH . '/permissions.php';
}

function setting(string $key, mixed $default = null): mixed
{
    if (!isset($GLOBALS['__settings'])) {
        try {
            $GLOBALS['__settings'] = DB::pairs('SELECT `key`, `value` FROM settings');
        } catch (Throwable) {
            $GLOBALS['__settings'] = [];
        }
    }
    $v = $GLOBALS['__settings'][$key] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

function settings_flush(): void
{
    unset($GLOBALS['__settings']);
}

function input(string $key, mixed $default = null): mixed
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function input_int(string $key, int $default = 0): int
{
    $v = input($key);
    return is_numeric($v) ? (int) $v : $default;
}

function input_float(string $key, float $default = 0.0): float
{
    $v = input($key);
    return is_numeric($v) ? (float) $v : $default;
}

/** A Y-m-d date from input, or $default when missing/invalid. */
function input_date(string $key, ?string $default = null): ?string
{
    $v = input($key);
    if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false) {
        return $v;
    }
    return $default;
}

/* ---------------------------------------------------------------------
 | Formatting
 * ------------------------------------------------------------------- */

function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

function currency(): string
{
    return (string) setting('currency', 'KES');
}

function money(mixed $n, bool $symbol = true): string
{
    $f = number_format((float) $n, 2);
    return $symbol ? currency() . ' ' . $f : $f;
}

function fmt_date(?string $d, string $format = 'd M Y'): string
{
    return $d ? date($format, strtotime($d)) : '—';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

function label(string $s): string
{
    return ucwords(str_replace('_', ' ', $s));
}

function round_money(float $n): float
{
    return round($n + 0.0, 2);
}

/* ---------------------------------------------------------------------
 | Security
 * ------------------------------------------------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_verify(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_token'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        throw new AppError('Your session has expired. Please refresh the page and try again.', 419);
    }
}

function post_only(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new AppError('Method not allowed.', 405);
    }
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
}

/* ---------------------------------------------------------------------
 | HTTP
 * ------------------------------------------------------------------- */

function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/* ---------------------------------------------------------------------
 | Audit & numbering
 * ------------------------------------------------------------------- */

function audit(string $action, ?string $entity = null, int|string|null $entityId = null, ?string $description = null): void
{
    try {
        DB::insert('audit_logs', [
            'user_id'     => Auth::id(),
            'action'      => $action,
            'entity'      => $entity,
            'entity_id'   => $entityId !== null ? (int) $entityId : null,
            'description' => $description !== null ? mb_substr($description, 0, 255) : null,
            'ip_address'  => client_ip(),
            'user_agent'  => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? PHP_SAPI), 0, 255),
            'created_at'  => now(),
        ]);
    } catch (Throwable $e) {
        error_log('Audit failed: ' . $e->getMessage());
    }
}

/** Concurrency-safe counter (row lock is held until the surrounding transaction ends). */
function next_seq(string $name): int
{
    DB::query(
        'INSERT INTO sequences (name, value) VALUES (?, LAST_INSERT_ID(1))
         ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1)',
        [$name]
    );
    return (int) DB::value('SELECT LAST_INSERT_ID()');
}

/* ---------------------------------------------------------------------
 | Query helpers for large tables
 * ------------------------------------------------------------------- */

/**
 * Branch restriction: staff attached to a branch only ever see that branch.
 * Head-office users (branch_id NULL) may optionally filter with ?branch_id=.
 *
 * @return array{0:string,1:array}
 */
function branch_scope(string $column): array
{
    $own = Auth::user()['branch_id'] ?? null;
    if ($own) {
        return [" AND $column = ?", [(int) $own]];
    }
    $filter = input_int('branch_id');
    return $filter > 0 ? [" AND $column = ?", [$filter]] : ['', []];
}

/** Whitelisted ORDER BY from ?sort=key&dir=asc|desc. */
function sort_clause(array $map, string $default): string
{
    $key = (string) input('sort', '');
    if (!isset($map[$key])) {
        return $default;
    }
    $dir = strtolower((string) input('dir', 'asc')) === 'desc' ? 'DESC' : 'ASC';
    return $map[$key] . ' ' . $dir;
}

/**
 * Offset pagination. $fromWhere starts with "FROM ..." and includes WHERE.
 * $countFromWhere lets large tables count without display-only joins, which
 * keeps COUNT(*) an index-only scan.
 */
function paginate(string $select, string $fromWhere, array $params, string $orderBy, int $defaultPerPage = 15, ?string $countFromWhere = null): array
{
    $page = max(1, input_int('page', 1));
    $perPage = min(100, max(5, input_int('per_page', $defaultPerPage)));
    $total = (int) DB::value("SELECT COUNT(*) " . ($countFromWhere ?? $fromWhere), $params);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $rows = DB::all("SELECT $select $fromWhere ORDER BY $orderBy LIMIT $perPage OFFSET $offset", $params);

    return [
        'data' => $rows,
        'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $pages],
    ];
}

/** Neutralise spreadsheet formula injection in exported cells. */
function csv_safe(mixed $v): string
{
    $s = (string) ($v ?? '');
    if ($s !== '' && !is_numeric($s) && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $s;
    }
    return $s;
}

/**
 * Stream a CSV straight from an unbuffered query – constant memory even for
 * millions of rows.
 */
function export_csv(string $filename, array $columns, string $sql, array $params = [], ?callable $map = null): never
{
    audit('export', $filename, null, 'Exported ' . $filename . ' to CSV');
    set_time_limit(0);
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_values($columns));

    $pdo = DB::pdo();
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($params));
    while ($row = $stmt->fetch()) {
        if ($map) {
            $row = $map($row);
        }
        $line = [];
        foreach (array_keys($columns) as $key) {
            $line[] = csv_safe($row[$key] ?? '');
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

/** Attach per-member financial totals to a page of member rows (2 indexed queries). */
function attach_member_totals(array $rows, string $idKey = 'id'): array
{
    $ids = array_values(array_unique(array_filter(array_map(fn($r) => (int) $r[$idKey], $rows))));
    if (!$ids) {
        return $rows;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $savings = DB::pairs("SELECT member_id, SUM(balance) FROM savings_accounts WHERE member_id IN ($in) GROUP BY member_id", $ids);
    $shares = DB::pairs("SELECT member_id, balance FROM share_accounts WHERE member_id IN ($in)", $ids);
    $loans = DB::pairs("SELECT member_id, SUM(balance) FROM loans WHERE member_id IN ($in) AND status = 'active' GROUP BY member_id", $ids);
    foreach ($rows as &$r) {
        $id = (int) $r[$idKey];
        $r['savings_total'] = (float) ($savings[$id] ?? 0);
        $r['share_capital'] = (float) ($shares[$id] ?? 0);
        $r['loan_balance'] = (float) ($loans[$id] ?? 0);
    }
    return $rows;
}

/** Tiny file cache for expensive aggregates (dashboard charts on large ledgers). */
function cache_remember(string $key, int $ttl, callable $fn): mixed
{
    $dir = APP_ROOT . '/storage/cache';
    $file = $dir . '/' . sha1($key) . '.cache';
    if (is_file($file) && filemtime($file) > time() - $ttl) {
        $data = @unserialize((string) file_get_contents($file), ['allowed_classes' => false]);
        if ($data !== false) {
            return $data;
        }
    }
    $value = $fn();
    if (is_dir($dir) || @mkdir($dir, 0775, true)) {
        @file_put_contents($file, serialize($value), LOCK_EX);
    }
    return $value;
}

function cache_flush(): void
{
    foreach (glob(APP_ROOT . '/storage/cache/*.cache') ?: [] as $f) {
        @unlink($f);
    }
}

/**
 * Index-friendly member search. Uses prefix LIKEs (term%) so every branch of
 * the OR can be served from an index, even with millions of members.
 *
 * @return array{0:string,1:array}
 */
function member_search_clause(string $term, string $a = 'm'): array
{
    $term = trim($term);
    if ($term === '') {
        return ['', []];
    }
    $esc = fn(string $s) => str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s) . '%';
    $words = preg_split('/\s+/', $term);

    if (count($words) >= 2) {
        [$w1, $w2] = [$esc($words[0]), $esc(end($words))];
        return [" AND (($a.first_name LIKE ? AND $a.last_name LIKE ?) OR ($a.first_name LIKE ? AND $a.last_name LIKE ?) OR ($a.first_name LIKE ? AND $a.other_names LIKE ?))", [$w1, $w2, $w2, $w1, $w1, $w2]];
    }
    $like = $esc($term);
    return [" AND ($a.member_no LIKE ? OR $a.national_id LIKE ? OR $a.phone LIKE ? OR $a.first_name LIKE ? OR $a.last_name LIKE ? OR $a.email LIKE ?)", array_fill(0, 6, $like)];
}

/* ---------------------------------------------------------------------
 | View helpers
 * ------------------------------------------------------------------- */

/** Premium KPI card; the value is filled client-side via App.fillKpis(). */
function kpi_card(string $label, string $key, string $icon, string $tone = 'green', string $format = 'money', string $meta = '', string $extra = '', string $spark = ''): string
{
    return '<div class="stat-card ' . e($extra) . '" data-tone="' . e($tone) . '">'
        . '<div class="stat-top"><span class="stat-label">' . e($label) . '</span><span class="stat-icon"><i class="bi bi-' . e($icon) . '"></i></span></div>'
        . '<div class="stat-value" data-kpi="' . e($key) . '" data-format="' . e($format) . '"><span class="skeleton" style="width:70%;height:24px"></span></div>'
        . ($spark !== '' ? '<div data-spark="' . e($spark) . '"></div>' : '')
        . ($meta !== '' ? '<div class="stat-meta">' . $meta . '</div>' : '')
        . '</div>';
}

/** "All branches" filter, shown only to head-office users. */
function branch_filter(string $class = 'select'): string
{
    if (Auth::user()['branch_id'] ?? null) {
        return '';
    }
    $branches = DB::all('SELECT id, name FROM branches ORDER BY name');
    if (count($branches) < 2) {
        return '';
    }
    $html = '<select class="' . e($class) . '" name="branch_id" aria-label="Branch"><option value="">All branches</option>';
    foreach ($branches as $b) {
        $html .= '<option value="' . (int) $b['id'] . '">' . e($b['name']) . '</option>';
    }
    return $html . '</select>';
}

function member_name(array $m): string
{
    return implode(' ', array_filter([trim((string) ($m['first_name'] ?? '')), trim((string) ($m['other_names'] ?? '')), trim((string) ($m['last_name'] ?? ''))]));
}
