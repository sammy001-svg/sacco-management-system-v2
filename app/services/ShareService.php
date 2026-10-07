<?php
defined('SACCO') or exit;

final class ShareService
{
    public static function price(): float
    {
        return max(0.01, (float) setting('share_price', 100));
    }

    public static function ensureAccount(int $memberId): int
    {
        DB::query('INSERT IGNORE INTO share_accounts (member_id, shares, balance) VALUES (?, 0, 0)', [$memberId]);
        return (int) DB::value('SELECT id FROM share_accounts WHERE member_id = ?', [$memberId]);
    }

    private static function lock(int $memberId): array
    {
        self::ensureAccount($memberId);
        $acc = DB::row(
            'SELECT s.*, m.branch_id, m.status AS member_status, m.first_name, m.last_name, m.member_no
             FROM share_accounts s JOIN members m ON m.id = s.member_id
             WHERE s.member_id = ? FOR UPDATE',
            [$memberId]
        );
        if (!$acc) {
            throw new AppError('Member not found.', 404);
        }
        return $acc;
    }

    /**
     * Buy whole shares at the current price. Paid in cash/mobile/bank, or by
     * moving funds from the member's savings (source = 'savings').
     */
    public static function purchase(int $memberId, int $shares, array $o = []): array
    {
        if ($shares < 1) {
            throw new ValidationError(['shares' => 'Enter at least one share.']);
        }

        return DB::transaction(function () use ($memberId, $shares, $o) {
            $acc = self::lock($memberId);
            if (!in_array($acc['member_status'], ['active', 'dormant', 'pending'], true)) {
                throw new AppError('Shares can only be bought for active members.');
            }

            $amount = round($shares * self::price(), 2);
            $method = $o['payment_method'] ?? 'cash';
            $flow = 'in';

            if (($o['source'] ?? 'cash') === 'savings') {
                if (empty($o['savings_account_id'])) {
                    throw new ValidationError(['savings_account_id' => 'Choose the savings account to pay from.']);
                }
                SavingsService::withdraw((int) $o['savings_account_id'], $amount, [
                    'member_id'      => $memberId,
                    'flow'           => 'none',
                    'payment_method' => 'internal',
                    'no_fee'         => true,
                    'narration'      => "Transfer to share capital ($shares shares)",
                    'at'             => $o['at'] ?? null,
                ]);
                $method = 'internal';
                $flow = 'none';
            }

            DB::query('UPDATE share_accounts SET shares = shares + ?, balance = balance + ? WHERE id = ?', [$shares, $amount, $acc['id']]);
            $balance = (float) DB::value('SELECT balance FROM share_accounts WHERE id = ?', [$acc['id']]);

            $tx = Ledger::post([
                'branch_id'      => $acc['branch_id'],
                'member_id'      => $memberId,
                'account_type'   => 'shares',
                'account_id'     => $acc['id'],
                'type'           => 'share_purchase',
                'amount'         => $amount,
                'units'          => $shares,
                'balance_after'  => $balance,
                'flow'           => $flow,
                'payment_method' => $method,
                'payment_ref'    => $o['payment_ref'] ?? null,
                'narration'      => $o['narration'] ?? "Purchase of $shares shares @ " . money(self::price()),
                'at'             => $o['at'] ?? null,
            ]);

            return ['transaction' => $tx, 'amount' => $amount, 'shares' => (int) $acc['shares'] + $shares, 'balance' => $balance];
        });
    }

    /**
     * Share capital migrated from a previous system. The exact amount is kept
     * (it need not be a whole number of shares) and posted against Opening
     * Balance Equity rather than cash.
     */
    public static function openingBalance(int $memberId, float $amount, ?string $at = null): array
    {
        $amount = round($amount, 2);
        return DB::transaction(function () use ($memberId, $amount, $at) {
            $acc = self::lock($memberId);
            $units = (int) floor($amount / self::price() + 1e-9);
            DB::query('UPDATE share_accounts SET shares = shares + ?, balance = balance + ? WHERE id = ?', [$units, $amount, $acc['id']]);
            return Ledger::post([
                'branch_id' => $acc['branch_id'], 'member_id' => $memberId, 'account_type' => 'shares', 'account_id' => $acc['id'],
                'type' => 'share_purchase', 'amount' => $amount, 'units' => $units,
                'balance_after' => DB::value('SELECT balance FROM share_accounts WHERE id = ?', [$acc['id']]),
                'flow' => 'none', 'payment_method' => 'internal', 'narration' => 'Opening share capital (migrated)', 'at' => $at,
                'gl' => ['contra' => 'opening_equity'],
            ]);
        });
    }

    /** Move shares between members at the seller's book value. */
    public static function transfer(int $fromMemberId, int $toMemberId, int $shares, array $o = []): array
    {
        if ($fromMemberId === $toMemberId) {
            throw new ValidationError(['to_member_id' => 'Choose a different receiving member.']);
        }
        if ($shares < 1) {
            throw new ValidationError(['shares' => 'Enter at least one share.']);
        }

        return DB::transaction(function () use ($fromMemberId, $toMemberId, $shares, $o) {
            // Lock in a stable order to avoid deadlocks between opposite transfers.
            $first = min($fromMemberId, $toMemberId);
            $second = max($fromMemberId, $toMemberId);
            $locked = [$first => self::lock($first), $second => self::lock($second)];
            $from = $locked[$fromMemberId];
            $to = $locked[$toMemberId];

            if ((int) $from['shares'] < $shares) {
                throw new ValidationError(['shares' => "{$from['first_name']} only holds {$from['shares']} shares."]);
            }
            if (!in_array($to['member_status'], ['active', 'dormant'], true)) {
                throw new AppError('The receiving member must be active.');
            }

            $value = round((float) $from['balance'] * $shares / max(1, (int) $from['shares']), 2);
            $narr = $o['narration'] ?? '';

            DB::query('UPDATE share_accounts SET shares = shares - ?, balance = balance - ? WHERE id = ?', [$shares, $value, $from['id']]);
            DB::query('UPDATE share_accounts SET shares = shares + ?, balance = balance + ? WHERE id = ?', [$shares, $value, $to['id']]);

            $out = Ledger::post([
                'branch_id' => $from['branch_id'], 'member_id' => $fromMemberId,
                'account_type' => 'shares', 'account_id' => $from['id'],
                'type' => 'share_transfer_out', 'amount' => $value, 'units' => $shares,
                'balance_after' => DB::value('SELECT balance FROM share_accounts WHERE id = ?', [$from['id']]),
                'flow' => 'none', 'payment_method' => 'internal',
                'narration' => trim("$shares shares to {$to['member_no']} {$to['first_name']} {$to['last_name']}. $narr"),
            ]);
            Ledger::post([
                'branch_id' => $to['branch_id'], 'member_id' => $toMemberId,
                'account_type' => 'shares', 'account_id' => $to['id'],
                'type' => 'share_transfer_in', 'amount' => $value, 'units' => $shares,
                'balance_after' => DB::value('SELECT balance FROM share_accounts WHERE id = ?', [$to['id']]),
                'flow' => 'none', 'payment_method' => 'internal', 'payment_ref' => $out['ref'],
                'narration' => trim("$shares shares from {$from['member_no']} {$from['first_name']} {$from['last_name']}. $narr"),
            ]);

            return ['transaction' => $out, 'value' => $value];
        });
    }
}
