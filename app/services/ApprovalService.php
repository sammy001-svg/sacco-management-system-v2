<?php
defined('SACCO') or exit;

/**
 * Maker-checker control. Sensitive actions are stored as requests and only
 * executed when a different user with the approvals.decide permission
 * releases them. The request's maker stays recorded as the poster, so cash
 * lands in the maker's till.
 */
final class ApprovalService
{
    public const TYPES = [
        'withdrawal' => 'Large withdrawal',
        'reversal'   => 'Transaction reversal',
        'write_off'  => 'Loan write-off',
        'journal'    => 'Manual journal',
    ];

    public static function needed(string $type, float $amount = 0): bool
    {
        if (system_process()) {
            return false;
        }
        switch ($type) {
            case 'withdrawal':
                $limit = (float) setting('approval_withdrawal_limit', 0);
                return $limit > 0 && $amount >= $limit;
            case 'journal':
                $limit = (float) setting('approval_journal_limit', 0);
                return $limit > 0 && $amount >= $limit;
            case 'reversal':
                return (string) setting('approve_reversals', '1') === '1';
            case 'write_off':
                return (string) setting('approve_write_offs', '1') === '1';
        }
        return false;
    }

    public static function request(string $type, string $summary, array $payload, ?float $amount = null, ?int $branchId = null, ?int $memberId = null): array
    {
        $id = DB::insert('approval_requests', [
            'type' => $type, 'summary' => mb_substr($summary, 0, 255), 'amount' => $amount, 'payload' => json_encode($payload),
            'branch_id' => $branchId, 'member_id' => $memberId, 'requested_by' => Auth::id(),
        ]);
        audit('request', 'approval', $id, self::TYPES[$type] . ': ' . $summary);
        return ['pending_approval' => $id, 'message' => self::TYPES[$type] . " sent for approval (request #$id). A supervisor must approve it before it takes effect."];
    }

    private static function lock(int $id): array
    {
        $r = DB::row('SELECT * FROM approval_requests WHERE id = ? FOR UPDATE', [$id]);
        $own = Auth::user()['branch_id'] ?? null;
        if (!$r || ($own && $r['branch_id'] && (int) $r['branch_id'] !== (int) $own)) {
            throw new AppError('Request not found.', 404);
        }
        if ($r['status'] !== 'pending') {
            throw new AppError("This request is already {$r['status']}.");
        }
        return $r;
    }

    public static function approve(int $id, ?string $note = null): array
    {
        return DB::transaction(function () use ($id, $note) {
            $r = self::lock($id);
            if ((int) $r['requested_by'] === Auth::id()) {
                throw new AppError('You cannot approve your own request. Another supervisor must approve it.', 403);
            }
            try {
                $result = self::execute($r);
            } catch (AppError $e) {
                throw new AppError('Could not be applied: ' . $e->getMessage() . ' The request is still pending — reject it or try again.', $e->status);
            }
            DB::update('approval_requests', [
                'status' => 'approved', 'decided_by' => Auth::id(), 'decided_at' => now(), 'note' => $note, 'result' => mb_substr($result, 0, 255),
            ], 'id = ?', [$id]);
            return ['result' => $result, 'request' => $r];
        });
    }

    public static function reject(int $id, string $note): array
    {
        return DB::transaction(function () use ($id, $note) {
            $r = self::lock($id);
            DB::update('approval_requests', ['status' => 'rejected', 'decided_by' => Auth::id(), 'decided_at' => now(), 'note' => $note], 'id = ?', [$id]);
            return $r;
        });
    }

    public static function cancel(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $r = self::lock($id);
            if ((int) $r['requested_by'] !== Auth::id()) {
                throw new AppError('Only the person who made a request can cancel it.', 403);
            }
            DB::update('approval_requests', ['status' => 'cancelled', 'decided_by' => Auth::id(), 'decided_at' => now()], 'id = ?', [$id]);
            return $r;
        });
    }

    /** Perform the stored action as the maker. Returns a short result text. */
    private static function execute(array $r): string
    {
        $p = json_decode($r['payload'], true) ?: [];
        $previous = Ledger::$actor;
        Ledger::$actor = (int) $r['requested_by'];
        try {
            switch ($r['type']) {
                case 'withdrawal':
                    $res = SavingsService::withdraw((int) $p['account_id'], (float) $p['amount'], $p + ['narration' => 'Withdrawal (approved request #' . $r['id'] . ')']);
                    return 'Posted ' . $res['transaction']['ref'] . '; new balance ' . money($res['balance']);
                case 'reversal':
                    $res = Ledger::reverse((int) $p['transaction_id'], (string) $p['reason']);
                    return 'Reversed with ' . $res['ref'];
                case 'write_off':
                    LoanService::writeOff((int) $p['loan_id'], (string) $p['reason']);
                    return 'Loan written off';
                case 'journal':
                    $jid = Accounting::post($p['header'] + ['created_by' => (int) $r['requested_by']], $p['lines']);
                    return 'Posted journal ' . DB::value('SELECT entry_no FROM journal_entries WHERE id = ?', [$jid]);
            }
            throw new AppError('Unknown request type.');
        } finally {
            Ledger::$actor = $previous;
        }
    }

    public static function pendingCount(): int
    {
        $own = Auth::user()['branch_id'] ?? null;
        return (int) DB::value(
            "SELECT COUNT(*) FROM approval_requests WHERE status = 'pending'" . ($own ? ' AND (branch_id = ? OR branch_id IS NULL)' : ''),
            $own ? [(int) $own] : []
        );
    }
}
