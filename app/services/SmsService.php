<?php
defined('SACCO') or exit;

/**
 * SMS through the Shanfix SMS API (api/v1). Messages are written to the
 * sms_messages outbox first and sent afterwards — at the end of the web
 * request and by cron/minute.php — so no screen ever waits on the network.
 *
 * Shanfix limits: sendsms 60 calls/min, bulksend 10 calls/min with up to
 * 1,000 recipients each. One dispatch run stays below both.
 */
final class SmsService
{
    public const CATEGORIES = [
        'transaction'  => 'Transaction receipt',
        'disbursement' => 'Loan disbursement',
        'due'          => 'Due reminder',
        'arrears'      => 'Arrears alert',
        'bulk'         => 'Bulk message',
        'test'         => 'Test message',
        'general'      => 'General',
    ];
    public const PLACEHOLDERS = ['first_name', 'name', 'member_no', 'savings', 'shares', 'loan_balance', 'sacco'];

    private const SINGLE_CALLS_PER_RUN = 50;
    private const BULK_CALLS_PER_RUN = 8;
    private const BULK_CHUNK = 1000;
    private const MAX_ATTEMPTS = 5;

    private static bool $muted = false;
    private static bool $flushRegistered = false;

    /** Demo seeding and installs post thousands of transactions — never text anyone for them. */
    public static function mute(bool $on = true): void
    {
        self::$muted = $on;
    }

    public static function configured(): bool
    {
        return config('sms.client_id') !== '' && config('sms.api_key') !== '';
    }

    public static function enabled(): bool
    {
        return self::configured() && (string) setting('sms_enabled', '1') === '1';
    }

    /* ------------------------------------------------------------------
     | Outbox
     * ---------------------------------------------------------------- */

    /** Add a message to the outbox. Returns its id; invalid numbers are logged as failed. */
    public static function queue(string $phone, string $message, string $category = 'general', array $o = []): int
    {
        $msisdn = normalize_msisdn($phone);
        $id = DB::insert('sms_messages', [
            'batch_id'   => $o['batch_id'] ?? null,
            'member_id'  => $o['member_id'] ?? null,
            'phone'      => $msisdn ?? mb_substr(trim($phone), 0, 15),
            'message'    => mb_substr($message, 0, 918),
            'category'   => isset(self::CATEGORIES[$category]) ? $category : 'general',
            'status'     => $msisdn ? 'queued' : 'failed',
            'error'      => $msisdn ? null : 'Invalid phone number',
            'ref'        => $o['ref'] ?? null,
            'created_by' => $o['created_by'] ?? Auth::id(),
            'created_at' => now(),
        ]);
        if ($msisdn && empty($o['batch_id'])) {
            self::flushAfterResponse();
        }
        return $id;
    }

    /** Send what is queued once the response has gone to the browser. */
    private static function flushAfterResponse(): void
    {
        if (self::$flushRegistered || PHP_SAPI === 'cli') {
            return;
        }
        self::$flushRegistered = true;
        register_shutdown_function(static function () {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            try {
                self::dispatch(20, false);
            } catch (Throwable $e) {
                error_log('[sms] ' . $e->getMessage());
            }
        });
    }

    public static function render(string $template, array $vars): string
    {
        $out = preg_replace_callback('/\{([a-z_]+)\}/', fn($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $template);
        return trim(preg_replace('/[ \t]{2,}/', ' ', (string) $out));
    }

    /** SMS parts a message needs (GSM-7: 160/153 chars, Unicode: 70/67). */
    public static function segments(string $message): int
    {
        $len = mb_strlen($message);
        if ($len === 0) {
            return 0;
        }
        $unicode = (bool) preg_match('/[^\x{0A}\x{0D}\x{20}-\x{7E}£¥èéùìòÇØøÅåÄÖÑÜ§äöñüà€]/u', $message);
        [$single, $multi] = $unicode ? [70, 67] : [160, 153];
        return $len <= $single ? 1 : (int) ceil($len / $multi);
    }

    /* ------------------------------------------------------------------
     | Dispatch
     * ---------------------------------------------------------------- */

    /**
     * Send queued messages. $bulk = false sends only one-to-one messages
     * (receipts), leaving campaigns to the cron worker.
     */
    public static function dispatch(int $limit = 2000, bool $bulk = true): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'retry' => 0];
        if (!self::enabled()) {
            return $stats;
        }
        // Rows stuck in "sending" (worker died mid-call) go back to the queue.
        DB::query("UPDATE sms_messages SET status = 'queued', claim_token = NULL WHERE status = 'sending' AND next_attempt_at < ?", [date('Y-m-d H:i:s', time() - 600)]);

        $token = bin2hex(random_bytes(8));
        DB::query(
            "UPDATE sms_messages SET status = 'sending', claim_token = ?, attempts = attempts + 1, next_attempt_at = ?
             WHERE status = 'queued' AND (next_attempt_at IS NULL OR next_attempt_at <= ?)" . ($bulk ? '' : ' AND batch_id IS NULL') . '
             ORDER BY id LIMIT ' . max(1, $limit),
            [$token, now(), now()]
        );
        $rows = DB::all("SELECT id, batch_id, phone, message FROM sms_messages WHERE claim_token = ? AND status = 'sending' ORDER BY id", [$token]);
        if (!$rows) {
            return $stats;
        }

        // Identical texts go out together through bulksend; the rest one by one.
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['message']][] = $r;
        }
        $singleCalls = 0;
        $bulkCalls = 0;
        $release = [];
        foreach ($groups as $message => $list) {
            if (count($list) >= 2) {
                foreach (array_chunk($list, self::BULK_CHUNK) as $chunk) {
                    if ($bulkCalls++ >= self::BULK_CALLS_PER_RUN) {
                        $release = [...$release, ...array_column($chunk, 'id')];
                        continue;
                    }
                    self::sendBulk((string) $message, $chunk, $stats);
                }
            } else {
                if ($singleCalls++ >= self::SINGLE_CALLS_PER_RUN) {
                    $release[] = $list[0]['id'];
                    continue;
                }
                self::sendOne($list[0], $stats);
            }
        }
        if ($release) {
            // Over this minute's API allowance: back to the queue untouched.
            DB::query('UPDATE sms_messages SET status = \'queued\', attempts = GREATEST(attempts - 1, 0), claim_token = NULL WHERE id IN (' . implode(',', array_map('intval', $release)) . ')');
        }
        self::refreshBatches(array_unique(array_filter(array_column($rows, 'batch_id'))));
        return $stats;
    }

    private static function auth(): array
    {
        return ['X-Client-ID' => config('sms.client_id'), 'X-API-Key' => config('sms.api_key')];
    }

    private static function payload(array $extra): array
    {
        $sender = config('sms.sender_id');
        return $extra + ($sender !== '' ? ['sender_id' => $sender] : []);
    }

    private static function sendOne(array $row, array &$stats): void
    {
        $res = Http::post(config('sms.base_url') . '/api/v1/sendsms.php', [
            'headers' => self::auth(),
            'json'    => self::payload(['to' => $row['phone'], 'message' => $row['message']]),
            'timeout' => 20,
        ]);
        $j = $res['json'] ?? [];
        if (!empty($j['success'])) {
            DB::update('sms_messages', [
                'status' => 'sent', 'provider_message_id' => (string) ($j['message_id'] ?? ''), 'units' => (float) ($j['units_charged'] ?? 0),
                'sent_at' => now(), 'error' => null, 'claim_token' => null,
            ], 'id = ?', [$row['id']]);
            $stats['sent']++;
            return;
        }
        self::failOrRetry([(int) $row['id']], $res, $stats);
    }

    private static function sendBulk(string $message, array $chunk, array &$stats): void
    {
        $res = Http::post(config('sms.base_url') . '/api/v1/bulksend.php', [
            'headers' => self::auth(),
            'json'    => self::payload(['to' => array_column($chunk, 'phone'), 'message' => $message]),
            'timeout' => 90,
        ]);
        $j = $res['json'] ?? [];
        $ids = array_map('intval', array_column($chunk, 'id'));
        if (empty($j['success'])) {
            self::failOrRetry($ids, $res, $stats);
            return;
        }
        $invalid = array_flip(array_map(fn($p) => normalize_msisdn((string) $p) ?? (string) $p, (array) ($j['invalid_numbers'] ?? [])));
        $bad = [];
        $good = [];
        foreach ($chunk as $r) {
            isset($invalid[$r['phone']]) ? $bad[] = (int) $r['id'] : $good[] = (int) $r['id'];
        }
        $perUnit = $good ? round((float) ($j['units_charged'] ?? 0) / count($good), 2) : 0;
        if ($good) {
            DB::query("UPDATE sms_messages SET status = 'sent', units = ?, sent_at = ?, error = NULL, claim_token = NULL WHERE id IN (" . implode(',', $good) . ')', [$perUnit, now()]);
        }
        if ($bad) {
            DB::query("UPDATE sms_messages SET status = 'failed', error = 'Rejected as an invalid number', claim_token = NULL WHERE id IN (" . implode(',', $bad) . ')');
        }
        $stats['sent'] += count($good);
        $stats['failed'] += count($bad);
    }

    /** Network trouble, rate limits and server errors retry with back-off; anything else fails for good. */
    private static function failOrRetry(array $ids, array $res, array &$stats): void
    {
        $error = mb_substr((string) ($res['json']['error'] ?? $res['error'] ?? ('HTTP ' . $res['status'])), 0, 255);
        $transient = $res['status'] === 0 || $res['status'] === 429 || $res['status'] >= 500;
        $in = implode(',', array_map('intval', $ids));
        if ($transient) {
            DB::query(
                "UPDATE sms_messages SET status = IF(attempts >= ?, 'failed', 'queued'), error = ?, claim_token = NULL,
                        next_attempt_at = DATE_ADD(NOW(), INTERVAL POW(2, attempts) MINUTE) WHERE id IN ($in)",
                [self::MAX_ATTEMPTS, $error]
            );
            $stats['retry'] += count($ids);
        } else {
            DB::query("UPDATE sms_messages SET status = 'failed', error = ?, claim_token = NULL WHERE id IN ($in)", [$error]);
            $stats['failed'] += count($ids);
        }
        if ($res['status'] === 401) {
            error_log('[sms] Shanfix rejected the API credentials (SMS_CLIENT_ID / SMS_API_KEY).');
        }
    }

    public static function refreshBatches(array $ids): void
    {
        foreach ($ids as $id) {
            $c = DB::row(
                "SELECT SUM(status IN ('sent','delivered')) AS sent, SUM(status = 'failed') AS failed, SUM(status IN ('queued','sending')) AS open,
                        COALESCE(SUM(units), 0) AS units FROM sms_messages WHERE batch_id = ?",
                [$id]
            );
            DB::query(
                "UPDATE sms_batches SET sent = ?, failed = ?, units = ?, status = IF(status = 'cancelled', status, IF(? > 0, 'sending', 'done')),
                        completed_at = IF(? > 0, NULL, COALESCE(completed_at, NOW())) WHERE id = ?",
                [(int) $c['sent'], (int) $c['failed'], (float) $c['units'], (int) $c['open'], (int) $c['open'], $id]
            );
        }
    }

    /** Pull delivery reports for recent single sends (bulksend does not return message ids). */
    public static function refreshDelivery(int $limit = 500): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $rows = DB::pairs(
            "SELECT provider_message_id, id FROM sms_messages WHERE status = 'sent' AND provider_message_id IS NOT NULL AND provider_message_id <> ''
             AND sent_at >= ? ORDER BY id LIMIT $limit",
            [date('Y-m-d H:i:s', time() - 3 * 86400)]
        );
        if (!$rows) {
            return 0;
        }
        $res = Http::get(config('sms.base_url') . '/api/v1/status.php?message_ids=' . implode(',', array_keys($rows)), ['headers' => self::auth(), 'timeout' => 30]);
        $n = 0;
        foreach ((array) ($res['json']['messages'] ?? []) as $m) {
            $id = $rows[(string) ($m['message_id'] ?? '')] ?? null;
            $st = strtolower((string) ($m['status'] ?? ''));
            if (!$id) {
                continue;
            }
            if ($st === 'delivered') {
                DB::update('sms_messages', ['status' => 'delivered', 'delivered_at' => $m['delivered_at'] ?: now()], 'id = ?', [$id]);
                $n++;
            } elseif (in_array($st, ['failed', 'rejected', 'undelivered', 'expired'], true)) {
                DB::update('sms_messages', ['status' => 'failed', 'error' => mb_substr((string) ($m['failed_reason'] ?: 'Not delivered'), 0, 255)], 'id = ?', [$id]);
                $n++;
            }
        }
        return $n;
    }

    /** Units left on the Shanfix account (null when it cannot be reached). */
    public static function balance(): ?array
    {
        if (!self::configured()) {
            return null;
        }
        $res = Http::get(config('sms.base_url') . '/api/v1/balance.php', ['headers' => self::auth(), 'timeout' => 15]);
        $j = $res['json'] ?? [];
        if (empty($j['success'])) {
            throw new AppError('Shanfix SMS: ' . ($j['error'] ?? $res['error'] ?? 'no response (HTTP ' . $res['status'] . ')'), 502);
        }
        return ['units' => (float) $j['sms_units'], 'account' => (string) ($j['client_name'] ?? '')];
    }

    /** Send right now and report the provider's answer (Settings → test SMS). */
    public static function sendNow(string $phone, string $message, string $category = 'test'): array
    {
        if (!self::configured()) {
            throw new AppError('Add SMS_CLIENT_ID and SMS_API_KEY to the .env file first.');
        }
        $id = self::queue($phone, $message, $category);
        $row = DB::row('SELECT * FROM sms_messages WHERE id = ?', [$id]);
        if ($row['status'] === 'failed') {
            throw new ValidationError(['phone' => 'Enter a valid Kenyan mobile number.']);
        }
        DB::update('sms_messages', ['status' => 'sending', 'attempts' => 1, 'claim_token' => 'now'], 'id = ?', [$id]);
        $stats = ['sent' => 0, 'failed' => 0, 'retry' => 0];
        self::sendOne($row, $stats);
        return DB::row('SELECT * FROM sms_messages WHERE id = ?', [$id]);
    }

    /* ------------------------------------------------------------------
     | Automatic notifications
     * ---------------------------------------------------------------- */

    /** Called by Ledger::post for every posted transaction. */
    public static function afterTransaction(array $t): void
    {
        if (self::$muted || empty($t['member_id']) || !self::enabled()) {
            return;
        }
        $type = $t['type'];
        $internal = $t['payment_method'] === 'internal';
        if ($type === 'loan_disbursement') {
            if ((string) setting('sms_on_disbursement', '1') !== '1') {
                return;
            }
            $tpl = (string) setting('sms_tpl_disbursement');
            $category = 'disbursement';
        } elseif (in_array($type, ['deposit', 'withdrawal', 'loan_repayment', 'share_purchase'], true)) {
            // Money moved between a member's own accounts is texted once, on the receiving side.
            if ((string) setting('sms_on_transactions', '1') !== '1' || ($internal && in_array($type, ['deposit', 'withdrawal'], true))) {
                return;
            }
            $tpl = (string) setting($type === 'withdrawal' ? 'sms_tpl_withdrawal' : 'sms_tpl_transaction');
            $category = 'transaction';
        } else {
            return;
        }
        $m = DB::row('SELECT id, member_no, first_name, last_name, other_names, phone FROM members WHERE id = ?', [$t['member_id']]);
        if (!$m || !$m['phone'] || $tpl === '') {
            return;
        }
        $vars = self::memberVars($m) + [
            'type'    => Ledger::TYPES[$type] ?? ucfirst($type),
            'amount'  => money($t['amount']),
            'account' => self::accountLabel($t),
            'balance' => $t['balance_after'] !== null ? money($t['balance_after']) : '',
            'ref'     => $t['payment_method'] === 'mobile_money' && $t['payment_ref'] ? $t['payment_ref'] : $t['txn_ref'],
            'date'    => date('d/m/Y H:i', strtotime($t['created_at'])),
        ];
        self::queue($m['phone'], self::render($tpl, $vars), $category, ['member_id' => $m['id'], 'ref' => $t['txn_ref'], 'created_by' => $t['created_by']]);
    }

    private static function accountLabel(array $t): string
    {
        return match ($t['account_type']) {
            'savings' => (string) (DB::value('SELECT account_no FROM savings_accounts WHERE id = ?', [$t['account_id']]) ?? 'savings'),
            'loan'    => (string) (DB::value('SELECT loan_no FROM loans WHERE id = ?', [$t['account_id']]) ?? 'loan'),
            'shares'  => 'share capital',
            default   => $t['account_type'],
        };
    }

    public static function signature(): string
    {
        return (string) (setting('sms_signature') ?: setting('sacco_name', ''));
    }

    public static function memberVars(array $m): array
    {
        return [
            'first_name' => ucfirst(strtolower((string) $m['first_name'])),
            'name'       => member_name($m),
            'member_no'  => $m['member_no'] ?? '',
            'sacco'      => self::signature(),
        ];
    }

    /** Daily: installments falling due in N days. Returns the number queued. */
    public static function dueReminders(): int
    {
        if (!self::enabled() || (string) setting('sms_due_reminders', '1') !== '1') {
            return 0;
        }
        $days = max(0, (int) setting('sms_due_reminder_days', 3));
        $date = date('Y-m-d', strtotime("+$days days"));
        $rows = DB::all(
            "SELECT s.loan_id, s.installment_no, s.due_date,
                    s.principal_due + s.interest_due + s.penalty_due - s.principal_paid - s.interest_paid - s.penalty_paid AS due,
                    l.loan_no, m.id AS member_id, m.member_no, m.first_name, m.last_name, m.other_names, m.phone
             FROM loan_schedules s JOIN loans l ON l.id = s.loan_id JOIN members m ON m.id = l.member_id
             WHERE s.due_date = ? AND s.status IN ('pending','partial') AND l.status = 'active' AND m.phone <> ''",
            [$date]
        );
        $tpl = (string) setting('sms_tpl_due');
        $n = 0;
        foreach ($rows as $r) {
            $ref = "{$r['loan_no']}#{$r['installment_no']}";
            if ((float) $r['due'] <= 0 || DB::value("SELECT 1 FROM sms_messages WHERE category = 'due' AND ref = ? LIMIT 1", [$ref])) {
                continue;
            }
            $vars = self::memberVars($r) + ['account' => $r['loan_no'], 'amount' => money($r['due']), 'due_date' => date('d/m/Y', strtotime($r['due_date']))];
            self::queue($r['phone'], self::render($tpl, $vars), 'due', ['member_id' => $r['member_id'], 'ref' => $ref, 'created_by' => null]);
            $n++;
        }
        return $n;
    }

    /** Daily: loans with overdue installments, at most once every N days per loan. */
    public static function arrearsAlerts(): int
    {
        if (!self::enabled() || (string) setting('sms_arrears_alerts', '1') !== '1') {
            return 0;
        }
        $every = max(1, (int) setting('sms_arrears_every_days', 7));
        $rows = DB::all(
            "SELECT l.id, l.loan_no, m.id AS member_id, m.member_no, m.first_name, m.last_name, m.other_names, m.phone,
                    SUM(s.principal_due + s.interest_due + s.penalty_due - s.principal_paid - s.interest_paid - s.penalty_paid) AS arrears
             FROM loan_schedules s JOIN loans l ON l.id = s.loan_id JOIN members m ON m.id = l.member_id
             WHERE s.due_date < ? AND s.status <> 'paid' AND l.status = 'active' AND m.phone <> ''
             GROUP BY l.id, l.loan_no, m.id, m.member_no, m.first_name, m.last_name, m.other_names, m.phone HAVING arrears > 0",
            [today()]
        );
        $tpl = (string) setting('sms_tpl_arrears');
        $since = date('Y-m-d H:i:s', strtotime("-$every days"));
        $n = 0;
        foreach ($rows as $r) {
            if (DB::value("SELECT 1 FROM sms_messages WHERE category = 'arrears' AND ref = ? AND created_at > ? LIMIT 1", [$r['loan_no'], $since])) {
                continue;
            }
            $vars = self::memberVars($r) + ['account' => $r['loan_no'], 'amount' => money($r['arrears'])];
            self::queue($r['phone'], self::render($tpl, $vars), 'arrears', ['member_id' => $r['member_id'], 'ref' => $r['loan_no'], 'created_by' => null]);
            $n++;
        }
        return $n;
    }

    /* ------------------------------------------------------------------
     | Bulk campaigns
     * ---------------------------------------------------------------- */

    public const AUDIENCES = [
        'all'       => 'All active members',
        'branch'    => 'Active members of a branch',
        'borrowers' => 'Members with active loans',
        'arrears'   => 'Members with loans in arrears',
        'dormant'   => 'Dormant members',
        'members'   => 'Selected members',
        'numbers'   => 'Pasted phone numbers',
    ];

    /** Members matching an audience: id, names, phone and balances for placeholders. */
    public static function audience(array $a): array
    {
        $type = $a['type'] ?? 'all';
        $where = "WHERE m.phone <> ''";
        $params = [];
        [$bs, $bp] = branch_scope('m.branch_id');
        $where .= $bs;
        $params = [...$params, ...$bp];
        switch ($type) {
            case 'dormant':
                $where .= " AND m.status = 'dormant'";
                break;
            case 'branch':
                $where .= " AND m.status = 'active' AND m.branch_id = ?";
                $params[] = (int) ($a['branch_id'] ?? 0);
                break;
            case 'borrowers':
                $where .= " AND m.status IN ('active','dormant') AND EXISTS (SELECT 1 FROM loans l WHERE l.member_id = m.id AND l.status = 'active')";
                break;
            case 'arrears':
                $where .= " AND EXISTS (SELECT 1 FROM loans l JOIN loan_schedules s ON s.loan_id = l.id WHERE l.member_id = m.id AND l.status = 'active' AND s.status <> 'paid' AND s.due_date < CURDATE())";
                break;
            case 'members':
                $ids = array_filter(array_map('intval', (array) ($a['member_ids'] ?? [])));
                $where .= $ids ? ' AND m.id IN (' . implode(',', $ids) . ')' : ' AND 1=0';
                break;
            default:
                $where .= " AND m.status = 'active'";
        }
        return DB::all(
            "SELECT m.id, m.member_no, m.first_name, m.last_name, m.other_names, m.phone,
                    (SELECT COALESCE(SUM(balance),0) FROM savings_accounts WHERE member_id = m.id) AS savings,
                    (SELECT COALESCE(balance,0) FROM share_accounts WHERE member_id = m.id) AS shares,
                    (SELECT COALESCE(SUM(balance),0) FROM loans WHERE member_id = m.id AND status = 'active') AS loan_balance
             FROM members m $where ORDER BY m.id",
            $params
        );
    }

    /** Queue a campaign. Returns the batch row. */
    public static function campaign(string $title, string $message, array $audience, array $numbers = []): array
    {
        if (!self::configured()) {
            throw new AppError('SMS is not set up yet. Add the Shanfix SMS_CLIENT_ID and SMS_API_KEY to the .env file.');
        }
        return DB::transaction(function () use ($title, $message, $audience, $numbers) {
            $batch = DB::insert('sms_batches', [
                'title' => mb_substr($title, 0, 120), 'message' => $message,
                'audience' => mb_substr(self::AUDIENCES[$audience['type'] ?? 'all'] ?? 'Custom', 0, 255),
                'status' => 'queued', 'created_by' => Auth::id(), 'created_at' => now(),
            ]);
            $seen = [];
            $total = 0;
            $personal = (bool) preg_match('/\{(' . implode('|', self::PLACEHOLDERS) . ')\}/', $message);
            if (($audience['type'] ?? '') === 'numbers') {
                foreach ($numbers as $p) {
                    $n = normalize_msisdn($p) ?? trim($p);
                    if ($n === '' || isset($seen[$n])) {
                        continue;
                    }
                    $seen[$n] = true;
                    self::queue($n, self::render($message, ['sacco' => self::signature()]), 'bulk', ['batch_id' => $batch]);
                    $total++;
                }
            } else {
                foreach (self::audience($audience) as $m) {
                    $n = normalize_msisdn($m['phone']) ?? $m['phone'];
                    if (isset($seen[$n])) {
                        continue;
                    }
                    $seen[$n] = true;
                    $text = $personal ? self::render($message, self::memberVars($m) + [
                        'savings' => money($m['savings']), 'shares' => money($m['shares']), 'loan_balance' => money($m['loan_balance']),
                    ]) : self::render($message, ['sacco' => self::signature()]);
                    self::queue($n, $text, 'bulk', ['batch_id' => $batch, 'member_id' => $m['id']]);
                    $total++;
                }
            }
            if (!$total) {
                throw new ValidationError(['audience' => 'Nobody matches this audience.']);
            }
            DB::update('sms_batches', ['total' => $total], 'id = ?', [$batch]);
            self::refreshBatches([$batch]);
            return DB::row('SELECT * FROM sms_batches WHERE id = ?', [$batch]);
        });
    }

    public static function cancelBatch(int $id): int
    {
        $n = DB::query("UPDATE sms_messages SET status = 'cancelled', claim_token = NULL WHERE batch_id = ? AND status = 'queued'", [$id])->rowCount();
        DB::query("UPDATE sms_batches SET status = 'cancelled', completed_at = NOW() WHERE id = ? AND status IN ('queued','sending')", [$id]);
        self::refreshBatches([$id]);
        return $n;
    }
}
