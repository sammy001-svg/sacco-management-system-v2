<?php
defined('SACCO') or exit;

return [

    /** What is configured in .env (never the secrets themselves). */
    'status' => function () {
        Auth::authorize('settings.manage');
        $m = config('mpesa');
        $set = fn(string $v) => $v !== '';
        return ['data' => [
            'mpesa' => [
                'configured'   => MpesaService::configured(),
                'env'          => $m['env'],
                'type'         => $m['type'],
                'shortcode'    => $m['shortcode'],
                'till_number'  => $m['till_number'],
                'keys'         => $set($m['consumer_key']) && $set($m['consumer_secret']),
                'passkey'      => $set($m['passkey']),
                'callback_key' => strlen($m['callback_key']) >= 24,
                'callback_url' => preg_replace('/key=[^&]+/', 'key=••••••', MpesaService::callbackUrl()),
                'https'        => str_starts_with(MpesaService::callbackUrl(), 'https://'),
                'pending'      => (int) DB::value("SELECT COUNT(*) FROM mpesa_requests WHERE status = 'pending'"),
                'last_paid'    => DB::value("SELECT MAX(paid_at) FROM mpesa_requests WHERE status IN ('paid','unposted')"),
            ],
            'sms' => [
                'configured' => SmsService::configured(),
                'base_url'   => config('sms.base_url'),
                'client_id'  => config('sms.client_id') !== '' ? substr(config('sms.client_id'), 0, 4) . '••••' : '',
                'sender_id'  => config('sms.sender_id'),
                'queued'     => (int) DB::value("SELECT COUNT(*) FROM sms_messages WHERE status IN ('queued','sending')"),
                'last_sent'  => DB::value('SELECT MAX(sent_at) FROM sms_messages'),
            ],
            'cron_last_run' => setting('cron_minute_at'),
        ]];
    },

    'test_mpesa' => function () {
        post_only();
        Auth::authorize('settings.manage');
        if (!MpesaService::configured()) {
            throw new AppError('Fill in MPESA_CONSUMER_KEY, MPESA_CONSUMER_SECRET, MPESA_SHORTCODE and MPESA_PASSKEY in .env first.');
        }
        MpesaService::token(true);
        return ['message' => 'Connected to M-Pesa (' . config('mpesa.env') . '). Daraja accepted the consumer key and secret.'];
    },

    'test_sms' => function () {
        post_only();
        Auth::authorize('settings.manage');
        $b = SmsService::balance();
        if ($b === null) {
            throw new AppError('Fill in SMS_CLIENT_ID and SMS_API_KEY in .env first.');
        }
        return ['message' => "Connected to Shanfix SMS as {$b['account']}. Balance: " . number_format($b['units'], 2) . ' units.'];
    },
];
