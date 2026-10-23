<?php
defined('SACCO') or exit;

function sms_audience_input(): array
{
    $type = (string) input('audience', 'all');
    if (!isset(SmsService::AUDIENCES[$type])) {
        throw new ValidationError(['audience' => 'Choose who receives the message.']);
    }
    return [
        'type'       => $type,
        'branch_id'  => input_int('branch_id'),
        'member_ids' => array_map('intval', (array) ($_POST['member_ids'] ?? $_GET['member_ids'] ?? [])),
    ];
}

function sms_numbers_input(): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) input('numbers', '')))));
}

return [

    'summary' => function () {
        Auth::authorize('sms.view');
        $today = DB::row(
            "SELECT COUNT(*) AS total, SUM(status IN ('sent','delivered')) AS sent, SUM(status = 'delivered') AS delivered,
                    SUM(status = 'failed') AS failed, SUM(status IN ('queued','sending')) AS queued, COALESCE(SUM(units), 0) AS units
             FROM sms_messages WHERE created_at >= ?",
            [today() . ' 00:00:00']
        );
        $balance = null;
        $balanceError = null;
        if (SmsService::configured()) {
            try {
                $balance = cache_remember('sms-balance', 60, fn() => SmsService::balance());
            } catch (AppError $e) {
                $balanceError = $e->getMessage();
            }
        }
        return ['data' => [
            'today'         => $today,
            'queued'        => (int) DB::value("SELECT COUNT(*) FROM sms_messages WHERE status IN ('queued','sending')"),
            'configured'    => SmsService::configured(),
            'enabled'       => SmsService::enabled(),
            'balance'       => $balance,
            'balance_error' => $balanceError,
            'sender_id'     => config('sms.sender_id') ?: 'account default',
        ]];
    },

    'messages' => function () {
        Auth::authorize('sms.view');
        $where = 'WHERE 1=1';
        $params = [];
        if (in_array($st = (string) input('status', ''), ['queued', 'sending', 'sent', 'delivered', 'failed', 'cancelled'], true)) {
            $where .= ' AND s.status = ?';
            $params[] = $st;
        }
        if (isset(SmsService::CATEGORIES[$c = (string) input('category', '')])) {
            $where .= ' AND s.category = ?';
            $params[] = $c;
        }
        if ($b = input_int('batch_id')) {
            $where .= ' AND s.batch_id = ?';
            $params[] = $b;
        }
        if ($m = input_int('member_id')) {
            $where .= ' AND s.member_id = ?';
            $params[] = $m;
        }
        if ($q = trim((string) input('search', ''))) {
            $where .= ' AND s.phone = ?';
            $params[] = normalize_msisdn($q) ?? $q;
        }
        if ($from = input_date('date_from')) {
            $where .= ' AND s.created_at >= ?';
            $params[] = "$from 00:00:00";
        }
        if ($to = input_date('date_to')) {
            $where .= ' AND s.created_at <= ?';
            $params[] = "$to 23:59:59";
        }
        $res = paginate(
            's.id, s.phone, s.message, s.category, s.status, s.units, s.error, s.attempts, s.created_at, s.sent_at, s.delivered_at, s.ref,
             s.member_id, m.member_no, m.first_name, m.last_name',
            "FROM sms_messages s LEFT JOIN members m ON m.id = s.member_id $where",
            $params, 's.id DESC', 20, "FROM sms_messages s $where"
        );
        foreach ($res['data'] as &$r) {
            $r['category_label'] = SmsService::CATEGORIES[$r['category']] ?? $r['category'];
        }
        return $res;
    },

    'batches' => function () {
        Auth::authorize('sms.view');
        return paginate(
            'b.*, u.name AS created_by_name',
            'FROM sms_batches b LEFT JOIN users u ON u.id = b.created_by',
            [], 'b.id DESC', 10, 'FROM sms_batches b'
        );
    },

    /** Recipient count and cost estimate before sending. */
    'preview' => function () {
        Auth::authorize('sms.send');
        $a = sms_audience_input();
        $count = $a['type'] === 'numbers'
            ? count(array_unique(array_map(fn($p) => normalize_msisdn($p) ?? $p, sms_numbers_input())))
            : count(SmsService::audience($a));
        $msg = (string) input('message', '');
        $personal = (bool) preg_match('/\{(' . implode('|', SmsService::PLACEHOLDERS) . ')\}/', $msg);
        $segments = SmsService::segments(SmsService::render($msg, ['first_name' => 'Wanjiku', 'name' => 'Wanjiku Kamau', 'member_no' => 'MBR000000',
            'savings' => money(123456), 'shares' => money(12000), 'loan_balance' => money(45000), 'sacco' => SmsService::signature()]));
        return ['data' => [
            'recipients' => $count, 'segments' => $segments, 'parts' => $count * $segments, 'personal' => $personal,
            // Personalised texts go one call each (60/min); identical ones in bulk (1,000 per call).
            'minutes' => $personal ? (int) ceil($count / 50) : (int) ceil($count / 8000),
        ]];
    },

    'send' => function () {
        post_only();
        Auth::authorize('sms.send');
        $d = Validator::make($_POST, ['title' => 'required|string|max:120', 'message' => 'required|string|min:2|max:918'], ['title' => 'Campaign name']);
        $a = sms_audience_input();
        if ($a['type'] === 'branch' && !$a['branch_id']) {
            throw new ValidationError(['branch_id' => 'Choose the branch.']);
        }
        $b = SmsService::campaign($d['title'], $d['message'], $a, $a['type'] === 'numbers' ? sms_numbers_input() : []);
        audit('sms_campaign', 'sms_batch', $b['id'], "Bulk SMS \"{$b['title']}\" to {$b['total']} recipient(s)");
        return ['message' => number_format((int) $b['total']) . ' message(s) queued. They go out within the next few minutes.', 'data' => $b];
    },

    'cancel_batch' => function () {
        post_only();
        Auth::authorize('sms.send');
        $n = SmsService::cancelBatch(input_int('id'));
        audit('sms_cancel', 'sms_batch', input_int('id'), "Cancelled $n unsent message(s)");
        return ['message' => "$n unsent message(s) cancelled."];
    },

    'retry' => function () {
        post_only();
        Auth::authorize('sms.send');
        $n = DB::query("UPDATE sms_messages SET status = 'queued', attempts = 0, next_attempt_at = NULL, error = NULL WHERE id = ? AND status = 'failed' AND error <> 'Invalid phone number'", [input_int('id')])->rowCount();
        if (!$n) {
            throw new AppError('Only failed messages with a valid number can be retried.');
        }
        return ['message' => 'Message queued again.'];
    },

    /** Send one message straight away and show what Shanfix answered. */
    'test' => function () {
        post_only();
        Auth::authorize('settings.manage');
        $d = Validator::make($_POST, ['phone' => 'required|string|max:20', 'message' => 'nullable|string|max:300']);
        $r = SmsService::sendNow($d['phone'], $d['message'] ?: 'Test message from ' . (setting('sacco_name') ?: 'SaccoPro') . ' — SMS is working.');
        if ($r['status'] !== 'sent') {
            throw new AppError('Shanfix SMS did not send it: ' . ($r['error'] ?: $r['status']), 502);
        }
        return ['message' => 'Test SMS sent to ' . mask_msisdn($r['phone']) . " (Shanfix message #{$r['provider_message_id']}, {$r['units']} unit(s))."];
    },
];
