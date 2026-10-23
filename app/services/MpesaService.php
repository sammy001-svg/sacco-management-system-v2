<?php
defined('SACCO') or exit;

/**
 * Lipa na M-Pesa Online (STK push) through Safaricom Daraja.
 *
 * Flow: request() sends the PIN prompt to the member's phone → Safaricom
 * calls mpesa_callback.php → handleCallback() records the result and posts
 * the payment. When a callback never arrives, query() asks Safaricom for the
 * outcome (from the waiting screen and from cron/minute.php).
 *
 * Posting is separate from recording: if money arrived but cannot be posted
 * (account closed meanwhile…) the request is marked "unposted" for a manager
 * to repost — the payment itself is never lost.
 */
final class MpesaService
{
    public const PURPOSES = [
        'deposit'        => 'Savings deposit',
        'loan_repayment' => 'Loan repayment',
        'share_purchase' => 'Share purchase',
    ];
    public const STATUSES = [
        'pending' => 'Waiting for PIN', 'paid' => 'Paid', 'failed' => 'Failed', 'cancelled' => 'Cancelled by member',
        'timeout' => 'No response', 'unposted' => 'Paid — needs posting',
    ];
    public const MAX_AMOUNT = 250000;

    public static function configured(): bool
    {
        $c = config('mpesa');
        return $c['consumer_key'] !== '' && $c['consumer_secret'] !== '' && $c['shortcode'] !== '' && $c['passkey'] !== ''
            && ($c['type'] !== 'till' || $c['till_number'] !== '');
    }

    public static function enabled(): bool
    {
        return self::configured() && (string) setting('mpesa_enabled', '1') === '1';
    }

    public static function baseUrl(): string
    {
        return config('mpesa.base_url') ?: (config('mpesa.env') === 'live' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke');
    }

    public static function callbackUrl(): string
    {
        if ($u = config('mpesa.callback_url')) {
            return $u;
        }
        return config('url') . '/mpesa_callback.php?key=' . rawurlencode((string) config('mpesa.callback_key'));
    }

    /** OAuth token, cached for 50 minutes (Daraja tokens live 60). */
    public static function token(bool $fresh = false): string
    {
        $key = 'mpesa-token:' . sha1(self::baseUrl() . config('mpesa.consumer_key'));
        if ($fresh) {
            @unlink(APP_ROOT . '/storage/cache/' . sha1($key) . '.cache');
        }
        $token = cache_remember($key, 3000, function () {
            $res = Http::get(self::baseUrl() . '/oauth/v1/generate?grant_type=client_credentials', [
                'basic' => config('mpesa.consumer_key') . ':' . config('mpesa.consumer_secret'), 'timeout' => 20,
            ]);
            return (string) ($res['json']['access_token'] ?? '');
        });
        if ($token === '') {
            @unlink(APP_ROOT . '/storage/cache/' . sha1($key) . '.cache');
            throw new AppError('M-Pesa sign-in failed. Check MPESA_CONSUMER_KEY, MPESA_CONSUMER_SECRET and MPESA_ENV in .env.', 502);
        }
        return $token;
    }

    private static function password(string $timestamp): string
    {
        return base64_encode(config('mpesa.shortcode') . config('mpesa.passkey') . $timestamp);
    }

    private static function api(string $path, array $body): array
    {
        $res = Http::post(self::baseUrl() . $path, ['headers' => ['Authorization' => 'Bearer ' . self::token()], 'json' => $body, 'timeout' => 30]);
        if ($res['status'] === 401) {
            // Token revoked early — one retry with a new one.
            $res = Http::post(self::baseUrl() . $path, ['headers' => ['Authorization' => 'Bearer ' . self::token(true)], 'json' => $body, 'timeout' => 30]);
        }
        return $res;
    }

    /* ------------------------------------------------------------------
     | STK push
     * ---------------------------------------------------------------- */

    /** Validate the target account and send the PIN prompt. Returns the request row. */
    public static function request(int $memberId, string $purpose, int $accountId, float $amount, string $phone): array
    {
        if (!self::configured()) {
            throw new AppError('M-Pesa is not set up yet. Add the Daraja keys to the .env file (see Settings → Integrations).');
        }
        if ((string) setting('mpesa_enabled', '1') !== '1') {
            throw new AppError('M-Pesa payment prompts are switched off in Settings → Integrations.');
        }
        if (!isset(self::PURPOSES[$purpose])) {
            throw new ValidationError(['purpose' => 'Choose what the payment is for.']);
        }
        if ($amount < 1 || floor($amount) != $amount) {
            throw new ValidationError(['amount' => 'M-Pesa takes whole shillings — enter an amount of at least 1 without cents.']);
        }
        if ($amount > self::MAX_AMOUNT) {
            throw new ValidationError(['amount' => 'M-Pesa allows at most ' . money(self::MAX_AMOUNT) . ' per payment.']);
        }
        $msisdn = normalize_msisdn($phone);
        if (!$msisdn) {
            throw new ValidationError(['phone' => 'Enter a valid Safaricom number, e.g. 0712 345 678.']);
        }
        $m = MemberService::findOrFail($memberId);
        if (!in_array($m['status'], ['active', 'dormant', 'pending'], true)) {
            throw new AppError('Payments can only be requested for active members.');
        }
        self::assertTarget($m, $purpose, $accountId, $amount);

        // The same prompt twice in a row is almost always a double click.
        $recent = DB::value(
            "SELECT id FROM mpesa_requests WHERE member_id = ? AND phone = ? AND amount = ? AND status = 'pending' AND created_at > ? LIMIT 1",
            [$memberId, $msisdn, $amount, date('Y-m-d H:i:s', time() - 60)]
        );
        if ($recent) {
            throw new AppError('A prompt for this amount was sent to this phone less than a minute ago. Wait for the member to respond.', 409);
        }

        $id = DB::insert('mpesa_requests', [
            'member_id' => $memberId, 'branch_id' => $m['branch_id'], 'purpose' => $purpose, 'account_id' => $accountId,
            'phone' => $msisdn, 'amount' => $amount, 'account_ref' => mb_substr((string) $m['member_no'], 0, 12),
            'status' => 'pending', 'requested_by' => Auth::id(), 'created_at' => now(),
        ]);

        $ts = date('YmdHis');
        $till = config('mpesa.type') === 'till';
        $res = self::api('/mpesa/stkpush/v1/processrequest', [
            'BusinessShortCode' => config('mpesa.shortcode'),
            'Password'          => self::password($ts),
            'Timestamp'         => $ts,
            'TransactionType'   => $till ? 'CustomerBuyGoodsOnline' : 'CustomerPayBillOnline',
            'Amount'            => (int) $amount,
            'PartyA'            => $msisdn,
            'PartyB'            => $till ? config('mpesa.till_number') : config('mpesa.shortcode'),
            'PhoneNumber'       => $msisdn,
            'CallBackURL'       => self::callbackUrl(),
            'AccountReference'  => mb_substr((string) $m['member_no'], 0, 12),
            'TransactionDesc'   => ['deposit' => 'Savings', 'loan_repayment' => 'Loan', 'share_purchase' => 'Shares'][$purpose],
        ]);
        $j = $res['json'] ?? [];
        if ((string) ($j['ResponseCode'] ?? '') === '0' && !empty($j['CheckoutRequestID'])) {
            DB::update('mpesa_requests', [
                'merchant_request_id' => $j['MerchantRequestID'] ?? null, 'checkout_request_id' => $j['CheckoutRequestID'],
                'result_desc' => mb_substr((string) ($j['CustomerMessage'] ?? 'Prompt sent'), 0, 255),
            ], 'id = ?', [$id]);
            return self::find($id);
        }
        $err = (string) ($j['errorMessage'] ?? $j['ResponseDescription'] ?? $res['error'] ?? ('HTTP ' . $res['status']));
        DB::update('mpesa_requests', ['status' => 'failed', 'result_code' => mb_substr((string) ($j['errorCode'] ?? $j['ResponseCode'] ?? $res['status']), 0, 20), 'result_desc' => mb_substr($err, 0, 255)], 'id = ?', [$id]);
        throw new AppError('M-Pesa did not accept the request: ' . $err, 502);
    }

    private static function assertTarget(array $m, string $purpose, int $accountId, float $amount): void
    {
        if ($purpose === 'deposit') {
            $a = DB::row('SELECT member_id, status FROM savings_accounts WHERE id = ?', [$accountId]);
            if (!$a || (int) $a['member_id'] !== (int) $m['id'] || $a['status'] !== 'active') {
                throw new ValidationError(['account_id' => 'Choose one of the member\'s active savings accounts.']);
            }
        } elseif ($purpose === 'loan_repayment') {
            $l = DB::row('SELECT member_id, status, balance FROM loans WHERE id = ?', [$accountId]);
            if (!$l || (int) $l['member_id'] !== (int) $m['id'] || $l['status'] !== 'active') {
                throw new ValidationError(['account_id' => 'Choose one of the member\'s active loans.']);
            }
            if ($amount > ceil((float) $l['balance']) && !SavingsService::defaultAccountId((int) $m['id'])) {
                throw new ValidationError(['amount' => 'The loan balance is ' . money($l['balance']) . ' and the member has no savings account for the excess.']);
            }
        } else {
            $price = ShareService::price();
            if ($amount < $price) {
                throw new ValidationError(['amount' => 'One share costs ' . money($price) . '.']);
            }
        }
    }

    /* ------------------------------------------------------------------
     | Results
     * ---------------------------------------------------------------- */

    /** Safaricom's callback body (already JSON-decoded). Idempotent. */
    public static function handleCallback(array $payload): ?array
    {
        $cb = $payload['Body']['stkCallback'] ?? null;
        if (!is_array($cb) || empty($cb['CheckoutRequestID'])) {
            return null;
        }
        $id = DB::value('SELECT id FROM mpesa_requests WHERE checkout_request_id = ?', [$cb['CheckoutRequestID']]);
        if (!$id) {
            error_log('[mpesa] callback for unknown CheckoutRequestID ' . $cb['CheckoutRequestID']);
            return null;
        }
        $meta = [];
        foreach ((array) ($cb['CallbackMetadata']['Item'] ?? []) as $item) {
            if (isset($item['Name'])) {
                $meta[$item['Name']] = $item['Value'] ?? null;
            }
        }
        DB::update('mpesa_requests', ['callback_payload' => mb_substr(json_encode($payload, JSON_UNESCAPED_SLASHES), 0, 60000)], 'id = ?', [$id]);
        return self::resolve((int) $id, (string) ($cb['ResultCode'] ?? ''), (string) ($cb['ResultDesc'] ?? ''), 'callback', [
            'amount'  => isset($meta['Amount']) ? (float) $meta['Amount'] : null,
            'receipt' => isset($meta['MpesaReceiptNumber']) ? (string) $meta['MpesaReceiptNumber'] : null,
            'phone'   => isset($meta['PhoneNumber']) ? (string) $meta['PhoneNumber'] : null,
            'date'    => isset($meta['TransactionDate']) ? (string) $meta['TransactionDate'] : null,
        ]);
    }

    /** Ask Safaricom how a pending prompt ended (when no callback has arrived). */
    public static function query(int $id): array
    {
        $r = self::find($id);
        if ($r['status'] !== 'pending' || !$r['checkout_request_id']) {
            return $r;
        }
        DB::update('mpesa_requests', ['last_query_at' => now()], 'id = ?', [$id]);
        $ts = date('YmdHis');
        $res = self::api('/mpesa/stkpushquery/v1/query', [
            'BusinessShortCode' => config('mpesa.shortcode'), 'Password' => self::password($ts), 'Timestamp' => $ts,
            'CheckoutRequestID' => $r['checkout_request_id'],
        ]);
        $j = $res['json'] ?? [];
        if (isset($j['ResultCode'])) {
            return self::resolve($id, (string) $j['ResultCode'], (string) ($j['ResultDesc'] ?? ''), 'query', []) ?? self::find($id);
        }
        // "The transaction is being processed" and network errors: still pending.
        // After a day without an answer it will never come.
        if (strtotime($r['created_at']) < time() - 86400) {
            DB::update('mpesa_requests', ['status' => 'timeout', 'result_desc' => 'No answer from M-Pesa within 24 hours'], "id = ? AND status = 'pending'", [$id]);
        }
        return self::find($id);
    }

    private static function resolve(int $id, string $code, string $desc, string $via, array $meta): ?array
    {
        $settle = DB::transaction(function () use ($id, $code, $desc, $via, $meta) {
            $r = DB::row('SELECT * FROM mpesa_requests WHERE id = ? FOR UPDATE', [$id]);
            // A callback may follow a query (or Safaricom may resend): first answer wins,
            // except that a receipt number from a late callback is still recorded.
            if ($r['status'] !== 'pending') {
                if ($code === '0' && !empty($meta['receipt']) && !$r['mpesa_receipt'] && in_array($r['status'], ['paid', 'unposted'], true)) {
                    DB::update('mpesa_requests', ['mpesa_receipt' => $meta['receipt']], 'id = ?', [$id]);
                    if ($r['transaction_id']) {
                        DB::update('transactions', ['payment_ref' => $meta['receipt']], 'id = ?', [$r['transaction_id']]);
                    }
                }
                return false;
            }
            if ($code !== '0') {
                $status = match ($code) {
                    '1032' => 'cancelled',
                    '1037', '1019' => 'timeout',
                    default => 'failed',
                };
                DB::update('mpesa_requests', ['status' => $status, 'result_code' => mb_substr($code, 0, 20), 'result_desc' => mb_substr($desc, 0, 255), 'confirmed_via' => $via], 'id = ?', [$id]);
                return false;
            }
            $paid = $meta['date'] ?? null;
            DB::update('mpesa_requests', [
                'status' => 'paid', 'result_code' => '0', 'result_desc' => mb_substr($desc, 0, 255), 'confirmed_via' => $via,
                'mpesa_receipt' => $meta['receipt'] ?? null,
                'paid_amount' => $meta['amount'] ?? $r['amount'],
                'paid_phone' => $meta['phone'] ?? $r['phone'],
                'paid_at' => $paid && strlen($paid) === 14 ? date('Y-m-d H:i:s', strtotime($paid)) : now(),
            ], 'id = ?', [$id]);
            return true;
        });
        if ($settle) {
            self::post($id);
        }
        return self::find($id);
    }

    /** Post a paid request to the member's account. Safe to call again (repost). */
    public static function post(int $id, ?string $redirect = null): array
    {
        try {
            DB::transaction(function () use ($id, $redirect) {
                $r = DB::row('SELECT * FROM mpesa_requests WHERE id = ? FOR UPDATE', [$id]);
                if (!in_array($r['status'], ['paid', 'unposted'], true) || $r['transaction_id']) {
                    return;
                }
                $prev = Ledger::$actor;
                Ledger::$actor = $r['requested_by'] ? (int) $r['requested_by'] : null;
                try {
                    $tx = self::postPayment($r, $redirect);
                } finally {
                    Ledger::$actor = $prev;
                }
                DB::update('mpesa_requests', ['status' => 'paid', 'transaction_id' => $tx['id'], 'post_error' => null], 'id = ?', [$id]);
            });
        } catch (Throwable $e) {
            DB::update('mpesa_requests', ['status' => 'unposted', 'post_error' => mb_substr($e->getMessage(), 0, 255)], 'id = ?', [$id]);
            error_log("[mpesa] request #$id paid but not posted: " . $e->getMessage());
        }
        return self::find($id);
    }

    private static function postPayment(array $r, ?string $redirect): array
    {
        $amount = round((float) ($r['paid_amount'] ?: $r['amount']), 2);
        $ref = $r['mpesa_receipt'] ?: 'STK' . substr((string) $r['checkout_request_id'], -12);
        $o = ['payment_method' => 'mobile_money', 'payment_ref' => $ref];
        $member = (int) $r['member_id'];
        $toSavings = function (float $amt, string $note) use ($member, $o) {
            $acc = SavingsService::defaultAccountId($member);
            if (!$acc) {
                throw new AppError('The member has no savings account to receive the payment.');
            }
            return SavingsService::deposit($acc, $amt, $o + ['narration' => $note])['transaction'];
        };

        if ($redirect === 'savings' || $r['purpose'] === 'deposit') {
            if ($redirect === 'savings') {
                return $toSavings($amount, "M-Pesa payment {$ref} (reposted to savings)");
            }
            return SavingsService::deposit((int) $r['account_id'], $amount, $o + ['narration' => "M-Pesa deposit {$ref}"])['transaction'];
        }
        if ($r['purpose'] === 'loan_repayment') {
            $balance = (float) DB::value('SELECT balance FROM loans WHERE id = ?', [$r['account_id']]);
            $toLoan = min($amount, round($balance, 2));
            if ($toLoan <= 0) {
                return $toSavings($amount, "M-Pesa {$ref}: loan already cleared");
            }
            $tx = LoanService::repay((int) $r['account_id'], $toLoan, $o + ['source' => 'cash', 'narration' => "M-Pesa repayment {$ref}"])['transaction'];
            if ($amount - $toLoan >= 0.01) {
                $toSavings(round($amount - $toLoan, 2), "M-Pesa {$ref}: excess over loan balance");
            }
            return $tx;
        }
        $price = ShareService::price();
        $shares = (int) floor($amount / $price + 1e-9);
        if ($shares < 1) {
            return $toSavings($amount, "M-Pesa {$ref}: below the price of one share");
        }
        $tx = ShareService::purchase($member, $shares, $o + ['source' => 'cash', 'narration' => "M-Pesa share purchase {$ref}"])['transaction'];
        $rest = round($amount - $shares * $price, 2);
        if ($rest >= 0.01) {
            $toSavings($rest, "M-Pesa {$ref}: balance after buying {$shares} shares");
        }
        return $tx;
    }

    public static function find(int $id): array
    {
        $r = DB::row(
            "SELECT r.*, m.member_no, m.first_name, m.last_name, m.other_names, u.name AS requested_by_name, t.txn_ref
             FROM mpesa_requests r JOIN members m ON m.id = r.member_id LEFT JOIN users u ON u.id = r.requested_by
             LEFT JOIN transactions t ON t.id = r.transaction_id WHERE r.id = ?",
            [$id]
        );
        if (!$r) {
            throw new AppError('Payment request not found.', 404);
        }
        $r['member_name'] = member_name($r);
        $r['status_label'] = self::STATUSES[$r['status']] ?? $r['status'];
        $r['purpose_label'] = self::PURPOSES[$r['purpose']] ?? $r['purpose'];
        $r['phone_masked'] = mask_msisdn($r['phone']);
        $r['target'] = match ($r['purpose']) {
            'deposit'        => (string) DB::value('SELECT account_no FROM savings_accounts WHERE id = ?', [$r['account_id']]),
            'loan_repayment' => (string) DB::value('SELECT loan_no FROM loans WHERE id = ?', [$r['account_id']]),
            default          => 'Share capital',
        };
        unset($r['callback_payload']);
        return $r;
    }

    /** Cron: settle prompts whose callback never came. */
    public static function reconcileStale(int $limit = 20): int
    {
        if (!self::configured()) {
            return 0;
        }
        $ids = DB::all(
            "SELECT id FROM mpesa_requests WHERE status = 'pending' AND checkout_request_id IS NOT NULL AND created_at < ?
             AND (last_query_at IS NULL OR last_query_at < ?) ORDER BY id LIMIT $limit",
            [date('Y-m-d H:i:s', time() - 90), date('Y-m-d H:i:s', time() - 120)]
        );
        foreach ($ids as $r) {
            try {
                self::query((int) $r['id']);
            } catch (Throwable $e) {
                error_log('[mpesa] status query failed: ' . $e->getMessage());
            }
        }
        // Prompts that never reached Safaricom.
        DB::query("UPDATE mpesa_requests SET status = 'failed', result_desc = 'Not sent to M-Pesa' WHERE status = 'pending' AND checkout_request_id IS NULL AND created_at < ?", [date('Y-m-d H:i:s', time() - 600)]);
        return count($ids);
    }
}
