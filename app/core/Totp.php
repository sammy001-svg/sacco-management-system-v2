<?php
defined('SACCO') or exit;

/**
 * Time-based one-time passwords (RFC 6238: HMAC-SHA1, 30-second steps,
 * 6 digits) plus single-use recovery codes.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(int $bytes = 20): string
    {
        return self::base32(random_bytes($bytes));
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function code(string $secret, ?int $step = null): string
    {
        $step ??= intdiv(time(), 30);
        $hash = hash_hmac('sha1', pack('N*', 0) . pack('N*', $step), self::unbase32($secret), true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Accepts the previous, current and next step to tolerate clock drift. */
    public static function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return false;
        }
        $now = intdiv(time(), 30);
        foreach ([-1, 0, 1] as $d) {
            if (hash_equals(self::code($secret, $now + $d), $code)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{0:array, 1:string} plain codes and the hashed JSON to store */
    public static function recoveryCodes(int $n = 8): array
    {
        $plain = [];
        for ($i = 0; $i < $n; $i++) {
            $c = strtoupper(bin2hex(random_bytes(5)));
            $plain[] = substr($c, 0, 5) . '-' . substr($c, 5);
        }
        return [$plain, json_encode(array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $plain))];
    }

    /** Consumes a recovery code. Returns the remaining hashed JSON, or null if no match. */
    public static function useRecoveryCode(?string $stored, string $code): ?string
    {
        $hashes = json_decode((string) $stored, true) ?: [];
        $code = strtoupper(trim($code));
        foreach ($hashes as $i => $h) {
            if (password_verify($code, $h)) {
                unset($hashes[$i]);
                return json_encode(array_values($hashes));
            }
        }
        return null;
    }

    private static function base32(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private static function unbase32(string $s): string
    {
        $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
        $bits = '';
        foreach (str_split($s) as $c) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
