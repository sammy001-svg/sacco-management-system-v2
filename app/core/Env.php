<?php
defined('SACCO') or exit;

/**
 * Minimal .env loader (no Composer needed — works on any cPanel host).
 *
 *   KEY=value
 *   KEY="value with spaces # not a comment"
 *   # comment lines and blank lines are ignored
 *
 * Real environment variables (set by the server) always win over the file.
 */
final class Env
{
    private static array $vars = [];

    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (str_starts_with($key, 'export ')) {
                $key = trim(substr($key, 7));
            }
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/i', $key)) {
                continue;
            }
            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                $end = strpos($value, $quote, 1);
                $value = $end === false ? substr($value, 1) : substr($value, 1, $end - 1);
                if ($quote === '"') {
                    $value = str_replace(['\\n', '\\"'], ["\n", '"'], $value);
                }
            } else {
                $value = trim(preg_replace('/\s+#.*$/', '', $value)); // inline comment
            }
            self::$vars[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $server = getenv($key);
        $value = $server !== false ? $server : (self::$vars[$key] ?? null);
        if ($value === null || $value === '') {
            return $default;
        }
        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            default => $value,
        };
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, $default);
        return is_bool($v) ? $v : in_array(strtolower((string) $v), ['1', 'yes', 'on'], true);
    }
}
