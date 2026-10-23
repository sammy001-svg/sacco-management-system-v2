<?php
defined('SACCO') or exit;

/**
 * Branch vaults and the physical movement of cash.
 *
 *   bank ⇄ vault ⇄ teller till ⇄ members
 *
 * The ledger's Cash on Hand always equals the sum of all vault balances plus
 * the expected cash in every open till:
 *   - bank ⇄ vault moves post a journal (Cash ⇄ Bank),
 *   - vault ⇄ till moves (float, top-up, drop, surrender) post nothing — the
 *     cash stays "on hand", it only changes location,
 *   - counter cash taken without a till goes straight to the vault,
 *   - count differences post to Cash Over / Short.
 */
final class VaultService
{
    public const TYPES = [
        'float'     => 'Float issued to teller',
        'top_up'    => 'Till top-up',
        'drop'      => 'Cash drop to vault',
        'surrender' => 'End-of-day surrender',
        'from_bank' => 'Cash withdrawn from bank',
        'to_bank'   => 'Cash banked',
        'adjust'    => 'Vault count adjustment',
        'direct'    => 'Counter cash (no till)',
    ];

    public static function defaultBranch(): int
    {
        static $id = null;
        return $id ??= ((int) DB::value('SELECT MIN(id) FROM branches') ?: 1);
    }

    public static function branchOfUser(int $userId): int
    {
        $b = DB::value('SELECT branch_id FROM users WHERE id = ?', [$userId]);
        return $b ? (int) $b : self::defaultBranch();
    }

    /** Create vaults the first time, holding the cash on hand recorded in the ledger. */
    public static function initialise(): bool
    {
        if (DB::value('SELECT 1 FROM vaults LIMIT 1')) {
            return false;
        }
        $def = self::defaultBranch();
        $per = [];
        foreach (DB::all('SELECT branch_id, SUM(debit - credit) AS bal FROM journal_lines WHERE account_id = ? GROUP BY branch_id', [Accounting::account('cash')]) as $r) {
            $b = $r['branch_id'] ? (int) $r['branch_id'] : $def;
            $per[$b] = ($per[$b] ?? 0) + (float) $r['bal'];
        }
        foreach (DB::all("SELECT id, branch_id FROM till_sessions WHERE status = 'open'") as $s) {
            $b = $s['branch_id'] ? (int) $s['branch_id'] : $def;
            $per[$b] = ($per[$b] ?? 0) - TillService::summary((int) $s['id'])['expected_now'];
        }
        foreach (DB::all('SELECT id FROM branches') as $br) {
            $per[(int) $br['id']] ??= 0.0;
        }
        // Older journals without a branch (e.g. head-office banking of branch
        // cash) can leave one branch negative. A vault cannot hold negative
        // cash, so spread the shortfall over the branches holding the cash;
        // the total still equals Cash on Hand in the ledger.
        $deficit = -array_sum(array_filter($per, fn($v) => $v < 0));
        $surplus = array_sum(array_filter($per, fn($v) => $v > 0));
        if ($deficit > 0 && $surplus >= $deficit) {
            foreach ($per as $b => $v) {
                $per[$b] = $v < 0 ? 0.0 : $v - $deficit * $v / $surplus;
            }
        }
        $total = array_sum($per);
        $per = array_map(fn($v) => round($v, 2), $per);
        $per[array_search(max($per), $per, true)] += round($total - array_sum($per), 2);
        foreach ($per as $b => $v) {
            DB::insert('vaults', ['branch_id' => $b, 'balance' => round($v, 2)]);
        }
        return true;
    }

    private static function lockVault(int $branch): array
    {
        DB::query('INSERT IGNORE INTO vaults (branch_id, balance) VALUES (?, 0)', [$branch]);
        return DB::row('SELECT * FROM vaults WHERE branch_id = ? FOR UPDATE', [$branch]);
    }

    public static function balance(int $branch): float
    {
        return (float) (DB::value('SELECT balance FROM vaults WHERE branch_id = ?', [$branch]) ?? 0);
    }

    /**
     * Record a movement. Completed movements change the vault at once;
     * pending ones wait for approve().
     */
    public static function record(int $branch, string $type, float $amount, float $effect, array $o = []): int
    {
        $status = $o['status'] ?? 'completed';
        $after = null;
        if ($status === 'completed') {
            $v = self::lockVault($branch);
            $after = round((float) $v['balance'] + $effect, 2);
            if ($effect < 0 && $after < -0.004 && !system_process()) {
                throw new AppError('Not enough cash in the vault. Available: ' . money($v['balance']) . '.');
            }
            DB::query('UPDATE vaults SET balance = ? WHERE id = ?', [$after, $v['id']]);
        }
        $at = $o['at'] ?? now();
        return DB::insert('cash_movements', [
            'branch_id'       => $branch,
            'type'            => $type,
            'amount'          => round($amount, 2),
            'vault_effect'    => round($effect, 2),
            'vault_after'     => $after,
            'till_session_id' => $o['till_session_id'] ?? null,
            'user_id'         => $o['user_id'] ?? null,
            'status'          => $status,
            'requested_by'    => $o['requested_by'] ?? Auth::id(),
            'approved_by'     => $o['approved_by'] ?? null,
            'transaction_id'  => $o['transaction_id'] ?? null,
            'journal_id'      => $o['journal_id'] ?? null,
            'reference'       => $o['reference'] ?? null,
            'denominations'   => !empty($o['denominations']) ? json_encode($o['denominations']) : null,
            'note'            => isset($o['note']) && $o['note'] !== '' ? mb_substr((string) $o['note'], 0, 255) : null,
            'created_at'      => $at,
            'completed_at'    => $status === 'completed' ? $at : null,
        ]);
    }

    /** Counter cash posted while the poster has no open till lands in the vault. */
    public static function direct(array $txn): void
    {
        $branch = $txn['branch_id'] ? (int) $txn['branch_id'] : self::defaultBranch();
        $effect = $txn['flow'] === 'in' ? (float) $txn['amount'] : -(float) $txn['amount'];
        self::record($branch, 'direct', (float) $txn['amount'], $effect, [
            'transaction_id' => $txn['id'], 'user_id' => $txn['created_by'], 'requested_by' => $txn['created_by'],
            'reference' => $txn['txn_ref'], 'at' => $txn['created_at'],
        ]);
    }

    /* -----------------------------------------------------------------
     | Teller requests
     * --------------------------------------------------------------- */

    /** Teller asks for an opening float. Supervisors serving themselves get it at once. */
    public static function requestFloat(int $userId, float $amount, ?string $note = null): array
    {
        return DB::transaction(function () use ($userId, $amount, $note) {
            DB::query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if (TillService::openFor($userId)) {
                throw new AppError('You already have an open till.');
            }
            if (DB::value("SELECT 1 FROM cash_movements WHERE user_id = ? AND type = 'float' AND status = 'pending'", [$userId])) {
                throw new AppError('Your float request is already waiting for a supervisor.');
            }
            if ($amount < 0) {
                throw new ValidationError(['amount' => 'The float cannot be negative.']);
            }
            $branch = self::branchOfUser($userId);
            if ($amount == 0.0) {
                return ['status' => 'open', 'session' => TillService::openSession($userId, $branch, 0)];
            }
            if (Auth::can('tills.supervise') && Auth::id() === $userId) {
                $sid = TillService::openSession($userId, $branch, $amount);
                self::record($branch, 'float', $amount, -$amount, ['till_session_id' => $sid, 'user_id' => $userId, 'approved_by' => $userId, 'note' => $note]);
                return ['status' => 'open', 'session' => $sid];
            }
            if ($amount > self::balance($branch)) {
                throw new ValidationError(['amount' => 'The vault only holds ' . money(self::balance($branch)) . '.']);
            }
            $id = self::record($branch, 'float', $amount, -$amount, ['status' => 'pending', 'user_id' => $userId, 'note' => $note]);
            return ['status' => 'pending', 'movement' => $id];
        });
    }

    public static function requestTopUp(int $userId, float $amount, ?string $note = null): array
    {
        return DB::transaction(function () use ($userId, $amount, $note) {
            $till = TillService::openFor($userId);
            if (!$till) {
                throw new AppError('Open your till first.');
            }
            if ($amount <= 0) {
                throw new ValidationError(['amount' => 'Enter the amount you need.']);
            }
            if (DB::value("SELECT 1 FROM cash_movements WHERE user_id = ? AND type = 'top_up' AND status = 'pending'", [$userId])) {
                throw new AppError('You already have a top-up waiting for a supervisor.');
            }
            $branch = $till['branch_id'] ? (int) $till['branch_id'] : self::defaultBranch();
            if (Auth::can('tills.supervise') && Auth::id() === $userId) {
                self::record($branch, 'top_up', $amount, -$amount, ['till_session_id' => $till['id'], 'user_id' => $userId, 'approved_by' => $userId, 'note' => $note]);
                return ['status' => 'completed'];
            }
            $id = self::record($branch, 'top_up', $amount, -$amount, ['status' => 'pending', 'till_session_id' => $till['id'], 'user_id' => $userId, 'note' => $note]);
            return ['status' => 'pending', 'movement' => $id];
        });
    }

    /** Teller hands surplus cash to the vault during the day. */
    public static function drop(int $userId, float $amount, array $denoms = [], ?string $note = null): void
    {
        DB::transaction(function () use ($userId, $amount, $denoms, $note) {
            $till = TillService::openFor($userId);
            if (!$till) {
                throw new AppError('You have no open till.');
            }
            $expected = TillService::summary((int) $till['id'])['expected_now'];
            if ($amount <= 0 || $amount > $expected + 0.001) {
                throw new ValidationError(['amount' => 'You can drop up to ' . money($expected) . ' — the cash expected in your drawer.']);
            }
            $branch = $till['branch_id'] ? (int) $till['branch_id'] : self::defaultBranch();
            self::record($branch, 'drop', $amount, $amount, ['till_session_id' => $till['id'], 'user_id' => $userId, 'denominations' => $denoms, 'note' => $note]);
        });
    }

    /* -----------------------------------------------------------------
     | Supervisor decisions
     * --------------------------------------------------------------- */

    private static function lockMovement(int $id): array
    {
        $m = DB::row('SELECT * FROM cash_movements WHERE id = ? FOR UPDATE', [$id]);
        $own = Auth::user()['branch_id'] ?? null;
        if (!$m || ($own && (int) $own !== (int) $m['branch_id'])) {
            throw new AppError('Request not found.', 404);
        }
        if ($m['status'] !== 'pending') {
            throw new AppError("This request is already {$m['status']}.");
        }
        return $m;
    }

    public static function approve(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $m = self::lockMovement($id);
            if ((int) $m['requested_by'] === Auth::id()) {
                throw new AppError('Another supervisor must approve your own request.', 403);
            }
            $v = self::lockVault((int) $m['branch_id']);
            if ((float) $m['amount'] > (float) $v['balance'] + 0.001) {
                throw new AppError('Not enough cash in the vault. Available: ' . money($v['balance']) . '.');
            }
            $session = $m['till_session_id'];
            if ($m['type'] === 'float') {
                if (TillService::openFor((int) $m['user_id'])) {
                    throw new AppError('This teller already has an open till.');
                }
                $session = TillService::openSession((int) $m['user_id'], (int) $m['branch_id'], (float) $m['amount']);
            } elseif ($m['type'] === 'top_up') {
                $till = TillService::openFor((int) $m['user_id']);
                if (!$till) {
                    throw new AppError('The teller\'s till is no longer open.');
                }
                $session = $till['id'];
            }
            $after = round((float) $v['balance'] + (float) $m['vault_effect'], 2);
            DB::query('UPDATE vaults SET balance = ? WHERE id = ?', [$after, $v['id']]);
            DB::update('cash_movements', [
                'status' => 'completed', 'approved_by' => Auth::id(), 'completed_at' => now(), 'vault_after' => $after, 'till_session_id' => $session,
            ], 'id = ?', [$id]);
            return $m;
        });
    }

    public static function reject(int $id, string $note): array
    {
        return DB::transaction(function () use ($id, $note) {
            $m = self::lockMovement($id);
            DB::update('cash_movements', ['status' => 'rejected', 'approved_by' => Auth::id(), 'completed_at' => now(), 'note' => mb_substr($note, 0, 255)], 'id = ?', [$id]);
            return $m;
        });
    }

    public static function cancel(int $id): void
    {
        DB::transaction(function () use ($id) {
            $m = DB::row('SELECT * FROM cash_movements WHERE id = ? FOR UPDATE', [$id]);
            if (!$m || $m['status'] !== 'pending' || (int) $m['requested_by'] !== Auth::id()) {
                throw new AppError('Request not found.', 404);
            }
            DB::update('cash_movements', ['status' => 'cancelled', 'completed_at' => now()], 'id = ?', [$id]);
        });
    }

    /* -----------------------------------------------------------------
     | Vault ⇄ bank, vault count
     * --------------------------------------------------------------- */

    public static function bank(int $branch, string $direction, float $amount, ?string $ref = null, ?string $note = null, ?string $at = null): int
    {
        if ($amount <= 0) {
            throw new ValidationError(['amount' => 'Enter an amount.']);
        }
        return DB::transaction(function () use ($branch, $direction, $amount, $ref, $note, $at) {
            $cash = Accounting::account('cash');
            $bank = Accounting::account('bank');
            $toBank = $direction === 'to';
            if ($toBank && !system_process() && $amount > self::balance($branch) + 0.001) {
                throw new ValidationError(['amount' => 'The vault only holds ' . money(self::balance($branch)) . '.']);
            }
            $date = $at ? substr($at, 0, 10) : today();
            $journal = Accounting::post([
                'date' => $date, 'source' => 'manual', 'branch_id' => $branch, 'reference' => $ref,
                'narration' => ($toBank ? 'Vault cash banked' : 'Cash withdrawn from bank to vault') . ($note ? " — $note" : ''),
                'created_at' => $at ?? now(),
            ], $toBank ? [[$bank, $amount, 0], [$cash, 0, $amount]] : [[$cash, $amount, 0], [$bank, 0, $amount]]);
            return self::record($branch, $toBank ? 'to_bank' : 'from_bank', $amount, $toBank ? -$amount : $amount, [
                'journal_id' => $journal, 'reference' => $ref, 'note' => $note, 'at' => $at, 'approved_by' => Auth::id(),
            ]);
        });
    }

    /** Physical count of the vault; differences post to Cash Over / Short. */
    public static function count(int $branch, float $counted, array $denoms = [], ?string $note = null): array
    {
        return DB::transaction(function () use ($branch, $counted, $denoms, $note) {
            $v = self::lockVault($branch);
            $variance = round($counted - (float) $v['balance'], 2);
            $journal = null;
            if ($variance != 0.0) {
                $cash = Accounting::account('cash');
                $over = Accounting::account('cash_variance');
                $journal = Accounting::post([
                    'date' => today(), 'source' => 'manual', 'branch_id' => $branch, 'reference' => 'VAULT' . $branch,
                    'narration' => ($variance < 0 ? 'Vault shortage' : 'Vault overage') . ' on count' . ($note ? " — $note" : ''),
                ], $variance < 0 ? [[$over, -$variance, 0], [$cash, 0, -$variance]] : [[$cash, $variance, 0], [$over, 0, $variance]]);
            }
            self::record($branch, 'adjust', abs($variance), $variance, ['journal_id' => $journal, 'denominations' => $denoms, 'note' => trim('Counted ' . money($counted) . '. ' . ($note ?? '')), 'approved_by' => Auth::id()]);
            return ['variance' => $variance, 'balance' => round($counted, 2)];
        });
    }

    /** Ledger Cash on Hand vs vaults + open tills (for the reconciliation report). */
    public static function cashCheck(): array
    {
        $vaults = (float) DB::value('SELECT COALESCE(SUM(balance), 0) FROM vaults');
        $tills = 0.0;
        foreach (DB::all("SELECT id FROM till_sessions WHERE status = 'open'") as $s) {
            $tills += TillService::summary((int) $s['id'])['expected_now'];
        }
        return ['vaults' => round($vaults, 2), 'tills' => round($tills, 2), 'total' => round($vaults + $tills, 2)];
    }
}
