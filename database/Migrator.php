<?php
defined('SACCO') or exit;

/**
 * Brings any SaccoPro database up to the current version. Safe to run
 * repeatedly: every step checks before it changes anything.
 *
 *   php database/migrate.php
 */
final class Migrator
{
    public const VERSION = APP_DB_VERSION;

    public static function run(?callable $log = null): void
    {
        $log ??= fn() => null;

        self::runSchema();
        $log('Schema: tables present.');

        self::ensureEnum('transactions', 'type', ['interest', 'dividend', 'write_off'],
            "ENUM('deposit','withdrawal','share_purchase','share_transfer_in','share_transfer_out','loan_disbursement','loan_repayment','processing_fee','registration_fee','withdrawal_fee','penalty','expense','reversal','interest','dividend','write_off') NOT NULL");
        self::ensureColumn('loan_repayments', 'status', "ENUM('posted','reversed') NOT NULL DEFAULT 'posted' AFTER payment_ref");
        self::ensureColumn('expenses', 'gl_account_id', 'INT UNSIGNED NULL AFTER category');
        self::ensureIndex('transactions', 'idx_txn_report', '(created_at, status, type, flow, payment_method, branch_id, amount)');
        self::ensureIndex('transactions', 'idx_txn_user_date', '(created_by, created_at)');
        self::ensureColumn('users', 'totp_secret', 'VARCHAR(64) NULL AFTER last_login_at');
        self::ensureColumn('users', 'totp_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret');
        self::ensureColumn('users', 'recovery_codes', 'TEXT NULL AFTER totp_enabled');
        foreach (['uploads', 'backups'] as $dir) {
            if (!is_dir(APP_ROOT . "/storage/$dir")) {
                @mkdir(APP_ROOT . "/storage/$dir", 0775, true);
            }
        }
        $log('Schema: columns and indexes up to date.');

        Accounting::seedChart();
        DB::query(
            "UPDATE expenses e JOIN gl_accounts a ON a.type = 'expense' AND a.name = e.category
             SET e.gl_account_id = a.id WHERE e.gl_account_id IS NULL"
        );
        $log('Chart of accounts ready (' . DB::value('SELECT COUNT(*) FROM gl_accounts') . ' accounts).');

        $r = self::backfillAllocations();
        $log("Repayment breakdowns rebuilt for {$r['loans']} loans ({$r['skipped']} skipped — mismatched history).");

        $n = self::backfillWriteOffs();
        if ($n) {
            $log("Posted $n historical loan write-off(s).");
        }

        $log('Posting historical transactions to the general ledger…');
        $j = Accounting::backfill($log);
        $log("General ledger: $j journal entries created.");

        if (VaultService::initialise()) {
            $log('Branch vaults opened with the cash on hand from the ledger.');
        }

        if (self::ensureCallbackKey()) {
            $log('Generated MPESA_CALLBACK_KEY in .env (protects the M-Pesa callback address).');
        }

        DB::query("INSERT INTO settings (`key`, `value`) VALUES ('db_version', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [(string) self::VERSION]);
        settings_flush();
        $log('Database is at version ' . self::VERSION . '.');
    }

    /** Installations from before v5 have no callback secret; add one to .env. */
    private static function ensureCallbackKey(): bool
    {
        $file = APP_ROOT . '/.env';
        $current = (string) config('mpesa.callback_key');
        if ((strlen($current) >= 24 && !str_starts_with($current, 'change-me')) || !is_file($file) || !is_writable($file)) {
            return false;
        }
        $key = bin2hex(random_bytes(24));
        $env = (string) file_get_contents($file);
        $env = preg_match('/^MPESA_CALLBACK_KEY=.*$/m', $env)
            ? preg_replace('/^MPESA_CALLBACK_KEY=.*$/m', 'MPESA_CALLBACK_KEY=' . $key, $env)
            : rtrim($env) . "\n\n# Protects the M-Pesa callback address (added by the v5 migration).\nMPESA_CALLBACK_KEY=$key\n";
        file_put_contents($file, $env, LOCK_EX);
        $GLOBALS['config']['mpesa']['callback_key'] = $key;
        return true;
    }

    public static function runSchema(): void
    {
        $sql = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $stmt) {
            if (trim($stmt) !== '') {
                DB::pdo()->exec($stmt);
            }
        }
    }

    private static function columnType(string $table, string $column): ?string
    {
        $t = DB::value(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        return $t === null ? null : (string) $t;
    }

    private static function ensureColumn(string $table, string $column, string $definition): void
    {
        if (self::columnType($table, $column) === null) {
            DB::pdo()->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }

    private static function ensureEnum(string $table, string $column, array $needed, string $definition): void
    {
        $type = (string) self::columnType($table, $column);
        foreach ($needed as $v) {
            if (!str_contains($type, "'$v'")) {
                DB::pdo()->exec("ALTER TABLE `$table` MODIFY `$column` $definition");
                return;
            }
        }
    }

    private static function ensureIndex(string $table, string $name, string $columns): void
    {
        $exists = DB::value(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$table, $name]
        );
        if (!$exists) {
            DB::pdo()->exec("ALTER TABLE `$table` ADD KEY `$name` $columns");
        }
    }

    /**
     * Rebuild the per-installment breakdown of historical repayments by
     * replaying them. A loan is only updated when the replay reproduces the
     * recorded totals exactly; otherwise it is left alone.
     */
    private static function backfillAllocations(): array
    {
        $loans = DB::all(
            "SELECT DISTINCT r.loan_id FROM loan_repayments r
             LEFT JOIN loan_repayment_allocations a ON a.repayment_id = r.id
             WHERE a.id IS NULL AND r.status = 'posted'"
        );
        $done = $skipped = 0;
        foreach ($loans as $l) {
            $loanId = (int) $l['loan_id'];
            if (DB::value('SELECT 1 FROM loan_repayment_allocations a JOIN loan_repayments r ON r.id = a.repayment_id WHERE r.loan_id = ? LIMIT 1', [$loanId])) {
                continue; // partially tracked already
            }
            $sched = DB::all('SELECT * FROM loan_schedules WHERE loan_id = ? ORDER BY installment_no', [$loanId]);
            $paid = [];
            foreach ($sched as $s) {
                $paid[$s['id']] = ['principal' => 0.0, 'interest' => 0.0, 'penalty' => 0.0];
            }
            $plan = [];
            $ok = true;
            foreach (DB::all("SELECT * FROM loan_repayments WHERE loan_id = ? AND status = 'posted' ORDER BY id", [$loanId]) as $r) {
                $left = (float) $r['amount'];
                $tot = ['principal' => 0.0, 'interest' => 0.0, 'penalty' => 0.0];
                $rows = [];
                foreach ($sched as $s) {
                    if ($left <= 0.0001) {
                        break;
                    }
                    $took = ['principal' => 0.0, 'interest' => 0.0, 'penalty' => 0.0];
                    foreach (['penalty', 'interest', 'principal'] as $part) {
                        $owed = round((float) $s[$part . '_due'] - $paid[$s['id']][$part], 2);
                        $take = min($left, max(0, $owed));
                        if ($take > 0) {
                            $paid[$s['id']][$part] = round($paid[$s['id']][$part] + $take, 2);
                            $took[$part] = $take;
                            $tot[$part] += $take;
                            $left = round($left - $take, 2);
                        }
                    }
                    if (array_sum($took) > 0) {
                        $rows[] = ['schedule_id' => $s['id']] + $took;
                    }
                }
                foreach (['principal', 'interest', 'penalty'] as $part) {
                    if (abs(round($tot[$part], 2) - (float) $r[$part . '_paid']) >= 0.01) {
                        $ok = false;
                    }
                }
                $plan[(int) $r['id']] = $rows;
            }
            foreach ($sched as $s) {
                foreach (['principal', 'interest', 'penalty'] as $part) {
                    if (abs($paid[$s['id']][$part] - (float) $s[$part . '_paid']) >= 0.01) {
                        $ok = false;
                    }
                }
            }
            if (!$ok) {
                $skipped++;
                continue;
            }
            DB::transaction(function () use ($plan) {
                foreach ($plan as $repaymentId => $rows) {
                    LoanService::saveAllocations($repaymentId, $rows);
                }
            });
            $done++;
        }
        return ['loans' => $done, 'skipped' => $skipped];
    }

    /** Loans written off before the ledger recorded write-offs. */
    private static function backfillWriteOffs(): int
    {
        $n = 0;
        $rows = DB::all(
            "SELECT l.* FROM loans l WHERE l.status = 'written_off'
             AND NOT EXISTS (SELECT 1 FROM transactions t WHERE t.account_type = 'loan' AND t.account_id = l.id AND t.type = 'write_off')"
        );
        foreach ($rows as $loan) {
            $principal = round((float) DB::value('SELECT COALESCE(SUM(principal_due - principal_paid), 0) FROM loan_schedules WHERE loan_id = ?', [$loan['id']]), 2);
            if ($principal <= 0) {
                continue;
            }
            DB::transaction(fn() => Ledger::post([
                'branch_id' => $loan['branch_id'], 'member_id' => $loan['member_id'], 'account_type' => 'loan', 'account_id' => $loan['id'],
                'type' => 'write_off', 'amount' => $principal, 'balance_after' => $loan['balance'], 'flow' => 'none',
                'payment_method' => 'internal', 'narration' => "Write-off of {$loan['loan_no']}", 'at' => $loan['closed_at'] ?? now(),
            ]));
            $n++;
        }
        return $n;
    }
}
