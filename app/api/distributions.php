<?php
defined('SACCO') or exit;

/** Interest and dividends are SACCO-wide: head office only. */
function distributions_guard(string $perm): void
{
    Auth::authorize($perm);
    if (Auth::user()['branch_id'] ?? null) {
        throw new AppError('Interest and dividend runs are managed by head office.', 403);
    }
}

return [

    'list' => function () {
        distributions_guard('distributions.prepare');
        return paginate(
            'r.*, u.name AS user_name, p.name AS product_name',
            'FROM distribution_runs r LEFT JOIN users u ON u.id = r.created_by LEFT JOIN savings_products p ON p.id = r.product_id',
            [], 'r.id DESC', 15, 'FROM distribution_runs r'
        );
    },

    'get' => function () {
        distributions_guard('distributions.prepare');
        $run = DistributionService::find(input_int('id'));
        $run['total_accounts'] = (int) DB::value('SELECT COUNT(DISTINCT member_id) FROM distribution_lines WHERE run_id = ?', [$run['id']]);
        $run['can_post'] = Auth::can('distributions.post');
        return ['data' => $run];
    },

    'lines' => function () {
        distributions_guard('distributions.prepare');
        $runId = input_int('id');
        $where = 'WHERE l.run_id = ?';
        $params = [$runId];
        if (in_array($st = (string) input('status', ''), ['pending', 'posted', 'skipped'], true)) {
            $where .= ' AND l.status = ?';
            $params[] = $st;
        }
        if (($q = trim((string) input('search', ''))) !== '') {
            [$ss, $sp] = member_search_clause($q);
            $where .= $ss;
            $params = [...$params, ...$sp];
        }
        return paginate(
            'l.*, m.member_no, m.first_name, m.last_name, t.txn_ref',
            "FROM distribution_lines l JOIN members m ON m.id = l.member_id LEFT JOIN transactions t ON t.id = l.transaction_id $where",
            $params, sort_clause(['gross' => 'l.gross', 'basis' => 'l.basis'], 'l.id'), 20
        );
    },

    'create' => function () {
        post_only();
        distributions_guard('distributions.prepare');
        $d = Validator::make($_POST, [
            'kind'         => 'required|in:savings_interest,dividend',
            'title'        => 'required|string|max:120',
            'period_from'  => 'required|date',
            'period_to'    => 'required|date',
            'method'       => 'required|in:average,closing',
            'rate'         => 'nullable|numeric|min:0|max:100',
            'product_id'   => 'nullable|integer',
            'wht_rate'     => 'required|numeric|min:0|max:100',
            'posting_date' => 'required|date',
        ], ['period_from' => 'Period start', 'period_to' => 'Period end', 'wht_rate' => 'Withholding tax rate']);
        $id = DistributionService::create($d);
        audit('create', 'distribution_run', $id, "Started {$d['title']}");
        return ['message' => 'Run created. Calculating…', 'id' => $id];
    },

    'compute' => function () {
        post_only();
        distributions_guard('distributions.prepare');
        set_time_limit(120);
        return ['data' => DistributionService::compute(input_int('id'))];
    },

    'post' => function () {
        post_only();
        distributions_guard('distributions.post');
        set_time_limit(120);
        $run = DistributionService::post(input_int('id'));
        if ($run['status'] === 'posted') {
            audit('post', 'distribution_run', $run['id'], "Posted {$run['title']}: " . money($run['net']) . " to {$run['posted_count']} accounts");
        }
        return ['data' => $run];
    },

    'delete' => function () {
        post_only();
        distributions_guard('distributions.prepare');
        DistributionService::delete(input_int('id'));
        audit('delete', 'distribution_run', input_int('id'), 'Deleted draft run');
        return ['message' => 'Draft run deleted.'];
    },
];
