<?php
defined('SACCO') or exit;

/**
 * The general ledger. Every money movement is posted here exactly once and
 * is never edited; corrections are made with reversal entries.
 */
final class Ledger
{
    public const TYPES = [
        'deposit'            => 'Deposit',
        'withdrawal'         => 'Withdrawal',
        'share_purchase'     => 'Share Purchase',
        'share_transfer_in'  => 'Share Transfer In',
        'share_transfer_out' => 'Share Transfer Out',
        'loan_disbursement'  => 'Loan Disbursement',
        'loan_repayment'     => 'Loan Repayment',
        'processing_fee'     => 'Processing Fee',
        'registration_fee'   => 'Registration Fee',
        'withdrawal_fee'     => 'Withdrawal Fee',
        'penalty'            => 'Penalty',
        'expense'            => 'Expense',
        'reversal'           => 'Reversal',
        'interest'           => 'Savings Interest',
        'dividend'           => 'Dividend',
        'write_off'          => 'Loan Write-off',
    ];

    public const METHODS = [
        'cash'         => 'Cash',
        'mobile_money' => 'Mobile Money',
        'bank'         => 'Bank Transfer',
        'cheque'       => 'Cheque',
        'internal'     => 'Internal Transfer',
    ];

    /** Types an authorised user may reverse from the ledger screen. */
    public const REVERSIBLE = ['deposit', 'withdrawal', 'withdrawal_fee', 'share_purchase', 'registration_fee', 'loan_repayment'];

    /** Whether a ledger row can be reversed from the ledger screen. */
    public static function isReversible(array $t): bool
    {
        if ($t['status'] !== 'posted' || !in_array($t['type'], self::REVERSIBLE, true)) {
            return false;
        }
        // Internal legs are reversed together with the operation they belong to.
        return $t['payment_method'] !== 'internal' || $t['type'] === 'loan_repayment';
    }

    /**
     * When set, postings are recorded as made by this user (used when an
     * approver releases a request: the maker stays the poster and their till
     * holds the cash).
     */
    public static ?int $actor = null;

    /** @return array{id:int, ref:string} */
    public static function post(array $t): array
    {
        $at = $t['at'] ?? now();
        Accounting::assertOpen($at);
        $by = $t['created_by'] ?? self::$actor ?? Auth::id();
        if (($t['payment_method'] ?? 'cash') === 'cash' && in_array($t['flow'] ?? 'none', ['in', 'out'], true)) {
            TillService::assertOpen($by);
        }

        $id = DB::insert('transactions', [
            'branch_id'      => $t['branch_id'] ?? null,
            'member_id'      => $t['member_id'] ?? null,
            'account_type'   => $t['account_type'],
            'account_id'     => $t['account_id'] ?? null,
            'type'           => $t['type'],
            'amount'         => round((float) $t['amount'], 2),
            'units'          => $t['units'] ?? null,
            'balance_after'  => isset($t['balance_after']) ? round((float) $t['balance_after'], 2) : null,
            'flow'           => $t['flow'] ?? 'none',
            'payment_method' => $t['payment_method'] ?? 'cash',
            'payment_ref'    => ($t['payment_ref'] ?? '') !== '' ? $t['payment_ref'] : null,
            'narration'      => ($t['narration'] ?? '') !== '' ? mb_substr($t['narration'], 0, 255) : null,
            'status'         => 'posted',
            'reversal_of'    => $t['reversal_of'] ?? null,
            'created_by'     => $by,
            'created_at'     => $at,
        ]);
        $ref = self::ref($id);
        DB::query('UPDATE transactions SET txn_ref = ? WHERE id = ?', [$ref, $id]);

        // Double entry: every ledger row gets its balanced journal immediately.
        $row = DB::row('SELECT * FROM transactions WHERE id = ?', [$id]);
        Accounting::postForTransaction($row, $t['gl'] ?? []);

        return ['id' => $id, 'ref' => $ref];
    }

    public static function ref(int $id): string
    {
        return 'TX' . str_pad((string) $id, 9, '0', STR_PAD_LEFT);
    }

    /**
     * Reverse a posted transaction: restores the account balance and posts an
     * equal and opposite entry linked to the original.
     */
    public static function reverse(int $id, string $reason, bool $allowInternal = false): array
    {
        return DB::transaction(function () use ($id, $reason, $allowInternal) {
            $t = DB::row('SELECT * FROM transactions WHERE id = ? FOR UPDATE', [$id]);
            if (!$t) {
                throw new AppError('Transaction not found.', 404);
            }
            if ($t['status'] === 'reversed') {
                throw new AppError('This transaction has already been reversed.');
            }
            if ($t['type'] === 'loan_repayment') {
                return LoanService::reverseRepayment($id, $reason);
            }
            if (!in_array($t['type'], self::REVERSIBLE, true) || ($t['payment_method'] === 'internal' && !$allowInternal)) {
                throw new AppError('This type of transaction cannot be reversed from the ledger. It is corrected together with the operation it belongs to.');
            }

            $amount = (float) $t['amount'];
            $balanceAfter = null;

            switch ($t['type']) {
                case 'deposit':
                    $acc = DB::row('SELECT balance FROM savings_accounts WHERE id = ? FOR UPDATE', [$t['account_id']]);
                    if (!$acc || (float) $acc['balance'] < $amount) {
                        throw new AppError('The account balance is too low to reverse this deposit.');
                    }
                    DB::query('UPDATE savings_accounts SET balance = balance - ? WHERE id = ?', [$amount, $t['account_id']]);
                    $balanceAfter = DB::value('SELECT balance FROM savings_accounts WHERE id = ?', [$t['account_id']]);
                    break;

                case 'withdrawal':
                case 'withdrawal_fee':
                    DB::query('SELECT id FROM savings_accounts WHERE id = ? FOR UPDATE', [$t['account_id']]);
                    DB::query('UPDATE savings_accounts SET balance = balance + ? WHERE id = ?', [$amount, $t['account_id']]);
                    $balanceAfter = DB::value('SELECT balance FROM savings_accounts WHERE id = ?', [$t['account_id']]);
                    break;

                case 'share_purchase':
                    $acc = DB::row('SELECT shares, balance FROM share_accounts WHERE id = ? FOR UPDATE', [$t['account_id']]);
                    $units = (int) ($t['units'] ?? 0);
                    if (!$acc || (int) $acc['shares'] < $units || (float) $acc['balance'] < $amount) {
                        throw new AppError('The member no longer holds enough shares to reverse this purchase.');
                    }
                    DB::query('UPDATE share_accounts SET shares = shares - ?, balance = balance - ? WHERE id = ?', [$units, $amount, $t['account_id']]);
                    $balanceAfter = DB::value('SELECT balance FROM share_accounts WHERE id = ?', [$t['account_id']]);
                    break;
            }

            $flow = ['in' => 'out', 'out' => 'in'][$t['flow']] ?? 'none';
            $rev = self::post([
                'branch_id'      => $t['branch_id'],
                'member_id'      => $t['member_id'],
                'account_type'   => $t['account_type'],
                'account_id'     => $t['account_id'],
                'type'           => 'reversal',
                'amount'         => $amount,
                'units'          => $t['units'],
                'balance_after'  => $balanceAfter,
                'flow'           => $flow,
                'payment_method' => $t['payment_method'],
                'payment_ref'    => $t['txn_ref'],
                'narration'      => 'Reversal of ' . $t['txn_ref'] . ': ' . $reason,
                'reversal_of'    => $t['id'],
            ]);
            DB::query("UPDATE transactions SET status = 'reversed' WHERE id = ?", [$id]);

            return $rev;
        });
    }
}
