<?php
defined('SACCO') or exit;

/**
 * Small cURL client for the outside services (M-Pesa, SMS). Never throws on
 * HTTP errors — callers inspect the status and decoded JSON body.
 */
final class Http
{
    /** @return array{status:int, json:?array, body:string, error:?string} */
    public static function request(string $method, string $url, array $o = []): array
    {
        $ch = curl_init();
        $headers = ['Accept: application/json'];
        foreach ($o['headers'] ?? [] as $k => $v) {
            $headers[] = "$k: $v";
        }
        $opts = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $o['connect_timeout'] ?? 8,
            CURLOPT_TIMEOUT        => $o['timeout'] ?? 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'SaccoPro/' . APP_DB_VERSION,
        ];
        if (isset($o['json'])) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($o['json'], JSON_UNESCAPED_SLASHES);
        } elseif (isset($o['form'])) {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($o['form']);
        }
        if (isset($o['basic'])) {
            $opts[CURLOPT_USERPWD] = $o['basic'];
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $error = $body === false ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = is_string($body) && $body !== '' ? json_decode($body, true) : null;
        return ['status' => $status, 'json' => is_array($json) ? $json : null, 'body' => (string) $body, 'error' => $error];
    }

    public static function get(string $url, array $o = []): array
    {
        return self::request('GET', $url, $o);
    }

    public static function post(string $url, array $o = []): array
    {
        return self::request('POST', $url, $o);
    }
}

/** 0712 345 678, +254712345678, 712345678 → 254712345678 (null when not a Kenyan mobile). */
function normalize_msisdn(?string $phone): ?string
{
    $n = preg_replace('/\D/', '', (string) $phone);
    if (strlen($n) === 10 && $n[0] === '0') {
        $n = '254' . substr($n, 1);
    } elseif (strlen($n) === 9 && in_array($n[0], ['7', '1'], true)) {
        $n = '254' . $n;
    }
    return preg_match('/^254[17]\d{8}$/', $n) ? $n : null;
}

/** 254712345678 → 0712 *** 678 for screens and logs. */
function mask_msisdn(string $msisdn): string
{
    $local = '0' . substr($msisdn, 3);
    return substr($local, 0, 4) . ' *** ' . substr($local, -3);
}
