<?php
/**
 * Safaricom Daraja STK push callback:  mpesa_callback.php?key=<MPESA_CALLBACK_KEY>
 *
 * Daraja does not sign callbacks, so the address carries a long secret key
 * (and MPESA_ALLOWED_IPS can pin Safaricom's servers). Processing is
 * idempotent, and Safaricom always gets "accepted" for a well-formed call so
 * it does not keep retrying.
 */
define('SACCO_NO_SESSION', true);
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$reply = function (int $status, string $desc) {
    http_response_code($status);
    echo json_encode(['ResultCode' => $status === 200 ? 0 : 1, 'ResultDesc' => $desc]);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $reply(405, 'POST only');
}
$key = (string) config('mpesa.callback_key');
if (strlen($key) < 24 || str_starts_with($key, 'change-me') || !hash_equals($key, (string) ($_GET['key'] ?? ''))) {
    error_log('[mpesa] callback rejected: bad key from ' . client_ip());
    $reply(403, 'Forbidden');
}
$allowed = array_filter(array_map('trim', explode(',', (string) config('mpesa.allowed_ips'))));
if ($allowed && !in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowed, true)) {
    error_log('[mpesa] callback rejected: IP ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ' not in MPESA_ALLOWED_IPS');
    $reply(403, 'Forbidden');
}

$raw = (string) file_get_contents('php://input', false, null, 0, 65536);
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $reply(400, 'Invalid JSON');
}

try {
    $r = MpesaService::handleCallback($payload);
    if ($r) {
        audit('mpesa_callback', 'mpesa_request', $r['id'], "M-Pesa {$r['status_label']}: " . money($r['amount']) . " from {$r['member_no']}" . ($r['mpesa_receipt'] ? " ({$r['mpesa_receipt']})" : ''));
    }
} catch (Throwable $e) {
    // Recorded for us; Safaricom must not retry a payment we have already seen.
    error_log('[mpesa] callback processing error: ' . $e->getMessage());
}
$reply(200, 'Accepted');
