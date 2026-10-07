<?php
defined('SACCO') or exit;

const SETTINGS_SCHEMA = [
    'organisation' => [
        'sacco_name'    => 'required|string|max:120',
        'sacco_tagline' => 'nullable|string|max:160',
        'sacco_email'   => 'nullable|email|max:190',
        'sacco_phone'   => 'nullable|string|max:40',
        'sacco_address' => 'nullable|string|max:255',
    ],
    'financial' => [
        'currency'          => 'required|string|max:6',
        'share_price'       => 'required|numeric|min:0.01',
        'min_share_capital' => 'required|numeric|min:0',
        'registration_fee'  => 'required|numeric|min:0',
        'wht_interest_rate' => 'required|numeric|min:0|max:100',
        'wht_dividend_rate' => 'required|numeric|min:0|max:100',
    ],
    'operations' => [
        'member_no_prefix'   => 'required|string|max:8',
        'penalty_grace_days' => 'required|integer|min:0|max:90',
    ],
    'controls' => [
        'require_till'              => 'bool',
        'approval_withdrawal_limit' => 'required|numeric|min:0',
        'approval_journal_limit'    => 'required|numeric|min:0',
        'approve_reversals'         => 'bool',
        'approve_write_offs'        => 'bool',
        'backup_keep'               => 'required|integer|min:1|max:365',
    ],
];

return [

    'get' => function () {
        Auth::authorize('settings.manage');
        return ['data' => DB::pairs('SELECT `key`, `value` FROM settings')];
    },

    'save' => function () {
        post_only();
        Auth::authorize('settings.manage');
        $group = (string) input('group');
        if (!isset(SETTINGS_SCHEMA[$group])) {
            throw new AppError('Unknown settings group.');
        }
        $d = Validator::make($_POST, SETTINGS_SCHEMA[$group]);
        if (isset($d['currency'])) {
            $d['currency'] = strtoupper($d['currency']);
        }
        if (isset($d['member_no_prefix'])) {
            $d['member_no_prefix'] = strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $d['member_no_prefix']));
        }
        foreach ($d as $k => $v) {
            DB::query('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$k, $v === null ? null : (string) $v]);
        }
        settings_flush();
        cache_flush();
        audit('update', 'settings', null, 'Updated ' . $group . ' settings');
        return ['message' => label($group) . ' settings saved.'];
    },

    'run_jobs' => function () {
        post_only();
        Auth::authorize('system.jobs');
        $r = LoanService::runDailyJobs();
        cache_flush();
        audit('run_jobs', 'system', null, "Daily jobs: {$r['overdue_marked']} overdue, {$r['penalties_applied']} penalties");
        return ['message' => "Daily jobs complete: {$r['overdue_marked']} installment(s) marked overdue, {$r['penalties_applied']} penalt" . ($r['penalties_applied'] === 1 ? 'y' : 'ies') . ' (' . money($r['penalty_total']) . ') applied.', 'data' => $r];
    },
];
