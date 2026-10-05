<?php
defined('SACCO') or exit;

final class SavingsService
{
    public static function openAccount(int $memberId, int $productId, ?string $date = null): int
    {
        $id = DB::insert('savings_accounts', [
            'member_id'  => $memberId,
            'product_id' => $productId,
            'balance'    => 0,
            'status'     => 'active',
            'opened_at'  => $date ?? today(),
        ]);
        DB::query('UPDATE savings_accounts SET account_no = ? WHERE id = ?', ['SV' . str_pad((string) $id, 8, '0', STR_PAD_LEFT), $id]);
        return $id;
    }

    /** Lock and load an account with its product and member context. */
    private static function lock(int $accountId): array
    {
        $a = DB::row(
            'SELECT a.*, p.name AS product_name, p.min_balance, p.allow_withdrawal, p.withdrawal_fee,
                    m.branch_id, m.status AS member_status
             FROM savings_accounts a
             JOIN savings_products p ON p.id = a.product_id
             JOIN members m ON m.id = a.member_id
             WHERE a.id = ? FOR UPDATE',
            [$accountId]
        );
        if (!$a) {
            throw new AppError('Savings account not found.', 404);
        }
        return $a;
    }

    public static function deposit(int $accountId, float $amount, array $o = []): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new ValidationError(['amount' => 'Amount must be greater than zero.']);
        }

        return DB::transaction(function () use ($accountId, $amount, $o) {
            $a = self::lock($accountId);
            if ($a['status'] !== 'active') {
                throw new AppError("This account is {$a['status']} and cannot receive deposits.");
            }
            if ($a['member_status'] === 'exited') {
                throw new AppError('This member has exited the SACCO.');
            }

            DB::query('UPDATE savings_accounts SET balance = balance + ? WHERE id = ?', [$amount, $accountId]);
            $balance = (float) DB::value('SELECT balance FROM savings_accounts WHERE id = ?', [$accountId]);

            $tx = Ledger::post([
                'branch_id'      => $a['branch_id'],
                'member_id'      => $a['member_id'],
                'account_type'   => 'savings',
                'account_id'     => $accountId,
                'type'           => 'deposit',
                'amount'         => $amount,
                'balance_after'  => $balance,
                'flow'           => $o['flow'] ?? 'in',
                'payment_method' => $o['payment_method'] ?? 'cash',
                'payment_ref'    => $o['payment_ref'] ?? null,
                'narration'      => $o['narration'] ?? ('Deposit to ' . $a['product_name']),
                'at'             => $o['at'] ?? null,
            ]);

            if ($a['member_status'] === 'dormant') {
                DB::query("UPDATE members SET status = 'active' WHERE id = ?", [$a['member_id']]);
            }

            return ['transaction' => $tx, 'balance' => $balance, 'member_id' => (int) $a['member_id']];
        });
    }

    /**
     * Options: payment_method, payment_ref, narration, at,
     *          flow (default 'out'), no_fee (internal transfers),
     *          member_id (assert ownership).
     */
    public static function withdraw(int $accountId, float $amount, array $o = []): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new ValidationError(['amount' => 'Amount must be greater than zero.']);
        }

        return DB::transaction(function () use ($accountId, $amount, $o) {
            $a = self::lock($accountId);
            if (isset($o['member_id']) && (int) $o['member_id'] !== (int) $a['member_id']) {
                throw new AppError('The selected savings account does not belong to this member.');
            }
            if ($a['status'] !== 'active') {
                throw new AppError("This account is {$a['status']}; withdrawals are blocked.");
            }
            if (!(int) $a['allow_withdrawal']) {
                throw new AppError("Withdrawals are not permitted on {$a['product_name']} accounts.");
            }

            $fee = empty($o['no_fee']) ? (float) $a['withdrawal_fee'] : 0.0;
            $available = round((float) $a['balance'] - (float) $a['min_balance'], 2);
            if ($amount + $fee > $available + 0.0001) {
                $msg = 'Insufficient funds. Available to withdraw: ' . money(max(0, $available - $fee));
                if ($fee > 0) {
                    $msg .= ' (after a ' . money($fee) . ' fee)';
                }
                throw new ValidationError(['amount' => $msg . '.'], $msg . '.');
            }

            DB::query('UPDATE savings_accounts SET balance = balance - ? WHERE id = ?', [$amount, $accountId]);
            $balance = (float) DB::value('SELECT balance FROM savings_accounts WHERE id = ?', [$accountId]);

            $tx = Ledger::post([
                'branch_id'      => $a['branch_id'],
                'member_id'      => $a['member_id'],
                'account_type'   => 'savings',
                'account_id'     => $accountId,
                'type'           => 'withdrawal',
                'amount'         => $amount,
                'balance_after'  => $balance,
                'flow'           => $o['flow'] ?? 'out',
                'payment_method' => $o['payment_method'] ?? 'cash',
                'payment_ref'    => $o['payment_ref'] ?? null,
                'narration'      => $o['narration'] ?? ('Withdrawal from ' . $a['product_name']),
                'at'             => $o['at'] ?? null,
            ]);

            if ($fee > 0) {
                DB::query('UPDATE savings_accounts SET balance = balance - ? WHERE id = ?', [$fee, $accountId]);
                $balance = (float) DB::value('SELECT balance FROM savings_accounts WHERE id = ?', [$accountId]);
                Ledger::post([
                    'branch_id'      => $a['branch_id'],
                    'member_id'      => $a['member_id'],
                    'account_type'   => 'savings',
                    'account_id'     => $accountId,
                    'type'           => 'withdrawal_fee',
                    'amount'         => $fee,
                    'balance_after'  => $balance,
                    'flow'           => 'none',
                    'payment_method' => $o['payment_method'] ?? 'cash',
                    'payment_ref'    => $tx['ref'],
                    'narration'      => 'Withdrawal charge',
                    'at'             => $o['at'] ?? null,
                ]);
            }

            return ['transaction' => $tx, 'balance' => $balance, 'fee' => $fee, 'member_id' => (int) $a['member_id']];
        });
    }

    /** Active accounts of a member, for pickers. */
    public static function memberAccounts(int $memberId, bool $withdrawableOnly = false): array
    {
        $sql = "SELECT a.id, a.account_no, a.balance, a.status, p.name AS product_name, p.min_balance,
                       p.allow_withdrawal, p.withdrawal_fee
                FROM savings_accounts a JOIN savings_products p ON p.id = a.product_id
                WHERE a.member_id = ? AND a.status <> 'closed'";
        if ($withdrawableOnly) {
            $sql .= ' AND p.allow_withdrawal = 1';
        }
        return DB::all($sql . ' ORDER BY p.is_default DESC, a.id', [$memberId]);
    }

    public static function defaultAccountId(int $memberId): ?int
    {
        $id = DB::value(
            "SELECT a.id FROM savings_accounts a JOIN savings_products p ON p.id = a.product_id
             WHERE a.member_id = ? AND a.status = 'active' ORDER BY p.is_default DESC, a.id LIMIT 1",
            [$memberId]
        );
        return $id ? (int) $id : null;
    }
}
