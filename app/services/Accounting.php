<?php
defined('SACCO') or exit;

/**
 * Double-entry general ledger.
 *
 * Every money movement in `transactions` is turned into one balanced journal
 * entry by postForTransaction(). Compound operations (loan paid into savings,
 * shares bought from savings, repayments from savings) are made of several
 * transactions that meet in the Internal Transfers Clearing account, which
 * always nets back to zero.
 *
 * Loan interest and penalties are recognised when they are paid (cash basis),
 * so Loans Receivable always equals outstanding principal.
 */
final class Accounting
{
    public const TYPES = [
        'asset'     => 'Assets',
        'liability' => 'Liabilities',
        'equity'    => 'Equity',
        'income'    => 'Income',
        'expense'   => 'Expenses',
    ];

    /** Moved only by member sub-ledger transactions, never by manual journals. */
    public const CONTROL = ['member_savings', 'share_capital', 'loans_receivable', 'clearing'];

    public const METHOD_ACCOUNTS = ['cash' => 'cash', 'bank' => 'bank', 'mobile_money' => 'mobile_money', 'cheque' => 'cheque', 'internal' => 'clearing'];

    public const SOURCES = ['transaction' => 'Transaction', 'manual' => 'Manual', 'opening' => 'Opening balance', 'interest' => 'Savings interest', 'dividend' => 'Dividend', 'closing' => 'Year-end close'];

    private static array $ids = [];

    /** Default SACCO chart of accounts: [code, name, type, system_key, description]. */
    public static function chart(): array
    {
        return [
            ['1000', 'Cash on Hand', 'asset', 'cash', 'Teller and vault cash'],
            ['1010', 'Bank Accounts', 'asset', 'bank', 'Balances held with banks'],
            ['1020', 'Mobile Money Float', 'asset', 'mobile_money', 'M-Pesa / mobile money paybill and float'],
            ['1030', 'Cheques in Clearing', 'asset', 'cheque', 'Cheques received, not yet cleared'],
            ['1100', 'Loans to Members', 'asset', 'loans_receivable', 'Outstanding loan principal (control account)'],
            ['1200', 'Property & Equipment', 'asset', null, 'Furniture, computers, vehicles and buildings'],
            ['1210', 'Accumulated Depreciation', 'asset', null, 'Contra-asset for depreciation'],
            ['1300', 'Prepayments & Other Receivables', 'asset', null, null],
            ['1900', 'Internal Transfers Clearing', 'asset', 'clearing', 'Transit account for member-to-member and account-to-account movements; should be zero'],
            ['2000', 'Member Savings Deposits', 'liability', 'member_savings', 'All member savings accounts (control account)'],
            ['2100', 'Withholding Tax Payable', 'liability', 'wht_payable', 'Tax withheld on interest and dividends'],
            ['2200', 'Accrued Expenses & Payables', 'liability', null, null],
            ['2300', 'External Borrowings', 'liability', null, 'Loans from banks and other lenders'],
            ['3000', 'Member Share Capital', 'equity', 'share_capital', 'All member share accounts (control account)'],
            ['3100', 'Retained Earnings', 'equity', 'retained_earnings', 'Accumulated surpluses after dividends'],
            ['3200', 'Statutory Reserve', 'equity', 'statutory_reserve', 'Appropriations to the statutory reserve'],
            ['4000', 'Loan Interest Income', 'income', 'interest_income', null],
            ['4010', 'Loan Penalty Income', 'income', 'penalty_income', null],
            ['4020', 'Loan Processing Fees', 'income', 'processing_fee_income', null],
            ['4030', 'Registration Fees', 'income', 'registration_fee_income', null],
            ['4040', 'Withdrawal Fees', 'income', 'withdrawal_fee_income', null],
            ['4900', 'Other Income', 'income', null, null],
            ['5000', 'Interest on Member Savings', 'expense', 'savings_interest_expense', null],
            ['5010', 'Loan Loss Expense', 'expense', 'loan_loss_expense', 'Loans written off'],
            ['5100', 'Salaries', 'expense', null, null],
            ['5110', 'Rent', 'expense', null, null],
            ['5120', 'Utilities', 'expense', null, null],
            ['5130', 'Stationery', 'expense', null, null],
            ['5140', 'Transport', 'expense', null, null],
            ['5150', 'Marketing', 'expense', null, null],
            ['5160', 'ICT & Software', 'expense', null, null],
            ['5170', 'Bank Charges', 'expense', null, null],
            ['5180', 'Repairs & Maintenance', 'expense', null, null],
            ['5190', 'Insurance', 'expense', null, null],
            ['5200', 'Professional Fees', 'expense', null, null],
            ['5210', 'Meetings & AGM', 'expense', null, null],
            ['5220', 'Training', 'expense', null, null],
            ['5230', 'Security', 'expense', null, null],
            ['5300', 'Depreciation', 'expense', null, null],
            ['5900', 'General Expenses', 'expense', 'general_expense', 'Fallback for uncategorised expenses'],
        ];
    }

    public static function seedChart(): void
    {
        foreach (self::chart() as [$code, $name, $type, $key, $desc]) {
            DB::query('INSERT IGNORE INTO gl_accounts (code, name, type, system_key, description) VALUES (?,?,?,?,?)', [$code, $name, $type, $key, $desc]);
        }
        self::$ids = [];
    }

    public static function account(string $key): int
    {
        if (!self::$ids) {
            self::$ids = DB::pairs('SELECT system_key, id FROM gl_accounts WHERE system_key IS NOT NULL');
        }
        if (!isset(self::$ids[$key])) {
            throw new AppError("The ledger account “{$key}” is missing. Run: php database/migrate.php", 500);
        }
        return (int) self::$ids[$key];
    }

    public static function methodAccount(string $method): int
    {
        return self::account(self::METHOD_ACCOUNTS[$method] ?? 'cash');
    }

    public static function expenseAccount(?int $id, ?string $category): int
    {
        if ($id && DB::value("SELECT 1 FROM gl_accounts WHERE id = ? AND type = 'expense'", [$id])) {
            return $id;
        }
        if ($category) {
            $found = DB::value("SELECT id FROM gl_accounts WHERE type = 'expense' AND name = ? LIMIT 1", [$category]);
            if ($found) {
                return (int) $found;
            }
        }
        return self::account('general_expense');
    }

    /** Debit-normal for assets and expenses, credit-normal otherwise. */
    public static function natural(string $type, float $dr, float $cr): float
    {
        return round(in_array($type, ['asset', 'expense'], true) ? $dr - $cr : $cr - $dr, 2);
    }

    /* -----------------------------------------------------------------
     | Period lock
     * --------------------------------------------------------------- */

    public static function lockedThrough(): ?string
    {
        $d = setting('books_closed_through');
        return $d ? (string) $d : null;
    }

    public static function assertOpen(string $date): void
    {
        $lock = self::lockedThrough();
        if ($lock && substr($date, 0, 10) <= $lock) {
            throw new AppError('The books are closed up to ' . fmt_date($lock) . '. Use a later date, or reopen the financial year first.');
        }
    }

    /* -----------------------------------------------------------------
     | Posting
     * --------------------------------------------------------------- */

    /**
     * Post a balanced journal entry.
     *  $h: date, source, transaction_id, branch_id, reference, narration, reversal_of, closing, skip_lock
     *  $lines: list of [account_id, debit, credit, memo?]
     */
    public static function post(array $h, array $lines): int
    {
        $date = substr((string) $h['date'], 0, 10);
        if (empty($h['skip_lock'])) {
            self::assertOpen($date);
        }

        $clean = [];
        $dr = $cr = 0.0;
        foreach ($lines as $l) {
            $d = round((float) ($l[1] ?? 0), 2);
            $c = round((float) ($l[2] ?? 0), 2);
            if ($d < 0) { $c -= $d; $d = 0.0; }
            if ($c < 0) { $d -= $c; $c = 0.0; }
            if ($d == 0.0 && $c == 0.0) {
                continue;
            }
            $clean[] = [(int) $l[0], $d, $c, isset($l[3]) ? mb_substr((string) $l[3], 0, 255) : null];
            $dr += $d;
            $cr += $c;
        }
        if (count($clean) < 2) {
            throw new AppError('A journal entry needs at least one debit and one credit line.');
        }
        if (abs(round($dr - $cr, 2)) >= 0.01) {
            throw new AppError('The journal does not balance: debits ' . money($dr) . ', credits ' . money($cr) . '.');
        }

        $id = DB::insert('journal_entries', [
            'entry_date'     => $date,
            'source'         => $h['source'] ?? 'transaction',
            'transaction_id' => $h['transaction_id'] ?? null,
            'branch_id'      => $h['branch_id'] ?? null,
            'reference'      => ($h['reference'] ?? '') !== '' ? mb_substr((string) $h['reference'], 0, 80) : null,
            'narration'      => ($h['narration'] ?? '') !== '' ? mb_substr((string) $h['narration'], 0, 255) : null,
            'total'          => round($dr, 2),
            'reversal_of'    => $h['reversal_of'] ?? null,
            'created_by'     => $h['created_by'] ?? Auth::id(),
            'created_at'     => $h['created_at'] ?? now(),
        ]);
        DB::query('UPDATE journal_entries SET entry_no = ? WHERE id = ?', ['JE' . str_pad((string) $id, 9, '0', STR_PAD_LEFT), $id]);

        $values = [];
        $params = [];
        $closing = !empty($h['closing']) ? 1 : 0;
        foreach ($clean as [$acc, $d, $c, $memo]) {
            $values[] = '(?,?,?,?,?,?,?,?)';
            array_push($params, $id, $acc, $date, $h['branch_id'] ?? null, $d, $c, $closing, $memo);
        }
        DB::query('INSERT INTO journal_lines (entry_id, account_id, entry_date, branch_id, debit, credit, is_closing, memo) VALUES ' . implode(',', $values), $params);

        return $id;
    }

    /** Post the reversing entry of $entryId (debits and credits swapped). */
    public static function reverseEntry(int $entryId, string $date, string $narration, array $h = []): int
    {
        $orig = DB::row('SELECT * FROM journal_entries WHERE id = ? FOR UPDATE', [$entryId]);
        if (!$orig) {
            throw new AppError('Journal entry not found.', 404);
        }
        if ($orig['status'] === 'reversed') {
            throw new AppError("Journal {$orig['entry_no']} has already been reversed.");
        }
        $lines = DB::all('SELECT account_id, debit, credit, memo, is_closing FROM journal_lines WHERE entry_id = ?', [$entryId]);
        $id = self::post($h + [
            'date'        => $date,
            'source'      => $orig['source'],
            'branch_id'   => $orig['branch_id'],
            'reference'   => $orig['entry_no'],
            'narration'   => $narration,
            'reversal_of' => $entryId,
            'closing'     => (int) ($lines[0]['is_closing'] ?? 0) === 1,
        ], array_map(fn($l) => [$l['account_id'], $l['credit'], $l['debit'], $l['memo']], $lines));
        DB::query("UPDATE journal_entries SET status = 'reversed' WHERE id = ?", [$entryId]);
        return $id;
    }

    /**
     * The posting rules: turn one ledger transaction into a journal entry.
     * $ctx may carry: allocation (repayments), gl_account_id/category (expenses),
     * gross/tax (interest and dividends).
     */
    public static function postForTransaction(array $t, array $ctx = [], bool $skipLock = true): ?int
    {
        $amount = round((float) $t['amount'], 2);
        $date = substr((string) $t['created_at'], 0, 10);
        $head = [
            'date'           => $date,
            'source'         => in_array($t['type'], ['interest', 'dividend'], true) ? $t['type'] : 'transaction',
            'transaction_id' => $t['id'],
            'branch_id'      => $t['branch_id'] ?? null,
            'reference'      => $t['txn_ref'] ?? null,
            'narration'      => (Ledger::TYPES[$t['type']] ?? label($t['type'])) . (($t['narration'] ?? '') !== '' ? ' — ' . $t['narration'] : ''),
            'created_by'     => $t['created_by'] ?? null,
            'created_at'     => $t['created_at'],
            'skip_lock'      => $skipLock,
        ];

        if ($t['type'] === 'reversal') {
            if (empty($t['reversal_of'])) {
                return null;
            }
            $orig = DB::value("SELECT id FROM journal_entries WHERE transaction_id = ? AND status = 'posted' ORDER BY id LIMIT 1", [$t['reversal_of']]);
            if (!$orig) {
                return null; // e.g. reversing something that never had a journal
            }
            return self::reverseEntry((int) $orig, $date, $head['narration'], [
                'transaction_id' => $t['id'], 'source' => 'transaction', 'skip_lock' => $skipLock,
                'created_by' => $head['created_by'], 'created_at' => $head['created_at'],
            ]);
        }

        $k = fn(string $key) => self::account($key);
        $m = fn() => self::methodAccount((string) $t['payment_method']);

        switch ($t['type']) {
            case 'deposit':            $lines = [[$m(), $amount, 0], [$k('member_savings'), 0, $amount]]; break;
            case 'withdrawal':         $lines = [[$k('member_savings'), $amount, 0], [$m(), 0, $amount]]; break;
            case 'withdrawal_fee':     $lines = [[$k('member_savings'), $amount, 0], [$k('withdrawal_fee_income'), 0, $amount]]; break;
            case 'share_purchase':     $lines = [[$m(), $amount, 0], [$k('share_capital'), 0, $amount]]; break;
            case 'share_transfer_out': $lines = [[$k('share_capital'), $amount, 0], [$k('clearing'), 0, $amount]]; break;
            case 'share_transfer_in':  $lines = [[$k('clearing'), $amount, 0], [$k('share_capital'), 0, $amount]]; break;
            case 'loan_disbursement':  $lines = [[$k('loans_receivable'), $amount, 0], [$m(), 0, $amount]]; break;
            case 'processing_fee':     $lines = [[$m(), $amount, 0], [$k('processing_fee_income'), 0, $amount]]; break;
            case 'registration_fee':   $lines = [[$m(), $amount, 0], [$k('registration_fee_income'), 0, $amount]]; break;
            case 'write_off':          $lines = [[$k('loan_loss_expense'), $amount, 0], [$k('loans_receivable'), 0, $amount]]; break;

            case 'loan_repayment':
                $a = $ctx['allocation'] ?? DB::row(
                    'SELECT principal_paid AS principal, interest_paid AS interest, penalty_paid AS penalty FROM loan_repayments WHERE transaction_id = ?',
                    [$t['id']]
                ) ?? ['principal' => $amount, 'interest' => 0, 'penalty' => 0];
                $interest = round((float) $a['interest'], 2);
                $penalty = round((float) $a['penalty'], 2);
                $principal = round($amount - $interest - $penalty, 2); // absorbs any rounding
                $lines = [
                    [$m(), $amount, 0],
                    [$k('loans_receivable'), 0, $principal],
                    [$k('interest_income'), 0, $interest],
                    [$k('penalty_income'), 0, $penalty],
                ];
                break;

            case 'expense':
                if (!isset($ctx['gl_account_id']) && !isset($ctx['category'])) {
                    $e = DB::row('SELECT gl_account_id, category FROM expenses WHERE transaction_id = ?', [$t['id']]);
                    $ctx += ['gl_account_id' => $e['gl_account_id'] ?? null, 'category' => $e['category'] ?? null];
                }
                $lines = [[self::expenseAccount($ctx['gl_account_id'] ? (int) $ctx['gl_account_id'] : null, $ctx['category'] ?? null), $amount, 0], [$m(), 0, $amount]];
                break;

            case 'interest':
            case 'dividend':
                $tax = round((float) ($ctx['tax'] ?? 0), 2);
                $gross = round((float) ($ctx['gross'] ?? $amount + $tax), 2);
                $lines = [
                    [$k($t['type'] === 'interest' ? 'savings_interest_expense' : 'retained_earnings'), $gross, 0],
                    [$k('member_savings'), 0, $amount],
                    [$k('wht_payable'), 0, $tax],
                ];
                break;

            default:
                return null; // penalties are memo items: income is recognised when paid
        }

        return self::post($head, $lines);
    }

    /** Journal every historical transaction that has none yet (idempotent). */
    public static function backfill(?callable $log = null): int
    {
        $log ??= fn() => null;
        $last = 0;
        $done = 0;
        do {
            $rows = DB::all(
                "SELECT t.* FROM transactions t
                 LEFT JOIN journal_entries j ON j.transaction_id = t.id
                 WHERE j.id IS NULL AND t.id > ? AND t.type <> 'penalty'
                 ORDER BY t.id LIMIT 1000",
                [$last]
            );
            DB::transaction(function () use ($rows, &$last, &$done) {
                foreach ($rows as $t) {
                    $last = (int) $t['id'];
                    if (self::postForTransaction($t, [], true)) {
                        $done++;
                    }
                }
            });
            if ($rows) {
                $log("  journaled up to transaction #$last ($done entries)");
            }
        } while (count($rows) === 1000);
        return $done;
    }

    /* -----------------------------------------------------------------
     | Reporting
     * --------------------------------------------------------------- */

    /**
     * Debit/credit totals per account.
     * @return array<int, array> keyed by account id
     */
    public static function totals(?string $from, string $to, ?int $branch = null, bool $excludeClosing = false): array
    {
        $cond = 'l.account_id = a.id AND l.entry_date <= ?';
        $params = [$to];
        if ($from) {
            $cond .= ' AND l.entry_date >= ?';
            $params[] = $from;
        }
        if ($excludeClosing) {
            $cond .= ' AND l.is_closing = 0';
        }
        if ($branch) {
            $cond .= ' AND l.branch_id = ?';
            $params[] = $branch;
        }
        $rows = DB::all(
            "SELECT a.id, a.code, a.name, a.type, a.system_key, a.status,
                    COALESCE(SUM(l.debit), 0) AS dr, COALESCE(SUM(l.credit), 0) AS cr
             FROM gl_accounts a LEFT JOIN journal_lines l ON $cond
             GROUP BY a.id ORDER BY a.code",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $r['dr'] = (float) $r['dr'];
            $r['cr'] = (float) $r['cr'];
            $r['balance'] = self::natural($r['type'], $r['dr'], $r['cr']);
            $out[(int) $r['id']] = $r;
        }
        return $out;
    }

    public static function trialBalance(string $asAt, ?int $branch = null): array
    {
        $rows = [];
        $dr = $cr = 0.0;
        foreach (self::totals(null, $asAt, $branch) as $r) {
            $net = round($r['dr'] - $r['cr'], 2);
            if ($net == 0.0) {
                continue;
            }
            $r['debit'] = $net > 0 ? $net : 0.0;
            $r['credit'] = $net < 0 ? -$net : 0.0;
            $dr += $r['debit'];
            $cr += $r['credit'];
            $rows[] = $r;
        }
        return ['rows' => $rows, 'debit' => round($dr, 2), 'credit' => round($cr, 2), 'balanced' => abs($dr - $cr) < 0.01];
    }

    public static function incomeStatement(string $from, string $to, ?int $branch = null): array
    {
        $income = $expense = [];
        $ti = $te = 0.0;
        foreach (self::totals($from, $to, $branch, true) as $r) {
            if ($r['balance'] == 0.0) {
                continue;
            }
            if ($r['type'] === 'income') {
                $income[] = $r;
                $ti += $r['balance'];
            } elseif ($r['type'] === 'expense') {
                $expense[] = $r;
                $te += $r['balance'];
            }
        }
        return ['income' => $income, 'expenses' => $expense, 'total_income' => round($ti, 2), 'total_expenses' => round($te, 2), 'surplus' => round($ti - $te, 2)];
    }

    public static function balanceSheet(string $asAt, ?int $branch = null): array
    {
        $groups = ['asset' => [], 'liability' => [], 'equity' => []];
        $tot = ['asset' => 0.0, 'liability' => 0.0, 'equity' => 0.0];
        $surplus = 0.0;
        foreach (self::totals(null, $asAt, $branch) as $r) {
            if ($r['type'] === 'income') {
                $surplus += $r['balance'];
            } elseif ($r['type'] === 'expense') {
                $surplus -= $r['balance'];
            } elseif ($r['balance'] != 0.0) {
                $groups[$r['type']][] = $r;
                $tot[$r['type']] += $r['balance'];
            }
        }
        $surplus = round($surplus, 2);
        $equity = round($tot['equity'] + $surplus, 2);
        return [
            'assets'            => $groups['asset'],
            'liabilities'       => $groups['liability'],
            'equity'            => $groups['equity'],
            'total_assets'      => round($tot['asset'], 2),
            'total_liabilities' => round($tot['liability'], 2),
            'current_surplus'   => $surplus,
            'total_equity'      => $equity,
            'balanced'          => abs($tot['asset'] - $tot['liability'] - $equity) < 0.01,
        ];
    }

    /** Proves the books agree with the member sub-ledgers. */
    public static function reconciliation(): array
    {
        $t = self::totals(null, '9999-12-31');
        $bal = function (string $key) use ($t) {
            $id = self::account($key);
            return $t[$id]['balance'] ?? 0.0;
        };
        $dr = array_sum(array_column($t, 'dr'));
        $cr = array_sum(array_column($t, 'cr'));
        $checks = [
            ['label' => 'Trial balance (total debits = total credits)', 'ledger' => round($dr, 2), 'subledger' => round($cr, 2)],
            ['label' => 'Member savings vs savings accounts', 'ledger' => $bal('member_savings'), 'subledger' => (float) DB::value('SELECT COALESCE(SUM(balance),0) FROM savings_accounts')],
            ['label' => 'Share capital vs share register', 'ledger' => $bal('share_capital'), 'subledger' => (float) DB::value('SELECT COALESCE(SUM(balance),0) FROM share_accounts')],
            ['label' => 'Loans to members vs outstanding principal', 'ledger' => $bal('loans_receivable'), 'subledger' => (float) DB::value(
                "SELECT COALESCE(SUM(s.principal_due - s.principal_paid),0) FROM loan_schedules s JOIN loans l ON l.id = s.loan_id WHERE l.status = 'active'"
            )],
            ['label' => 'Internal transfers clearing (should be zero)', 'ledger' => $bal('clearing'), 'subledger' => 0.0],
        ];
        foreach ($checks as &$c) {
            $c['difference'] = round($c['ledger'] - $c['subledger'], 2);
            $c['ok'] = abs($c['difference']) < 0.01;
        }
        unset($c);
        $unposted = (int) DB::value(
            "SELECT COUNT(*) FROM transactions t LEFT JOIN journal_entries j ON j.transaction_id = t.id WHERE j.id IS NULL AND t.type <> 'penalty'"
        );
        $checks[] = ['label' => 'Transactions without a journal entry', 'ledger' => $unposted, 'subledger' => 0, 'difference' => $unposted, 'ok' => $unposted === 0, 'count' => true];
        return $checks;
    }

    /* -----------------------------------------------------------------
     | Financial years
     * --------------------------------------------------------------- */

    public static function years(): array
    {
        $first = DB::value('SELECT MIN(entry_date) FROM journal_entries');
        $start = $first ? (int) substr($first, 0, 4) : (int) date('Y');
        $saved = [];
        foreach (DB::all('SELECT * FROM financial_years') as $y) {
            $saved[(int) $y['year']] = $y;
        }
        $out = [];
        for ($y = (int) date('Y'); $y >= min($start, ...array_keys($saved ?: [(int) date('Y') => 1])); $y--) {
            $row = $saved[$y] ?? ['year' => $y, 'status' => 'open'];
            if ($row['status'] !== 'closed') {
                $is = self::incomeStatement("$y-01-01", "$y-12-31");
                $row['income'] = $is['total_income'];
                $row['expenses'] = $is['total_expenses'];
                $row['surplus'] = $is['surplus'];
            }
            $row['ended'] = "$y-12-31" < today();
            $out[] = $row;
        }
        return $out;
    }

    public static function closeYear(int $year): array
    {
        return DB::transaction(function () use ($year) {
            $end = "$year-12-31";
            if ($end >= today()) {
                throw new AppError("$year has not ended yet. A year can be closed from 1 January " . ($year + 1) . '.');
            }
            DB::query('INSERT IGNORE INTO financial_years (year) VALUES (?)', [$year]);
            $fy = DB::row('SELECT * FROM financial_years WHERE year = ? FOR UPDATE', [$year]);
            if ($fy['status'] === 'closed') {
                throw new AppError("$year is already closed.");
            }
            $first = DB::value('SELECT MIN(entry_date) FROM journal_entries');
            if ($first) {
                for ($y = (int) substr($first, 0, 4); $y < $year; $y++) {
                    if (DB::value("SELECT status FROM financial_years WHERE year = ?", [$y]) !== 'closed') {
                        throw new AppError("Close $y first — years must be closed in order.");
                    }
                }
            }

            // Balances of every income and expense account up to year end.
            $lines = [];
            $income = $expenses = 0.0;
            foreach (self::totals(null, $end) as $r) {
                if (!in_array($r['type'], ['income', 'expense'], true) || $r['balance'] == 0.0) {
                    continue;
                }
                if ($r['type'] === 'income') {
                    $income += $r['balance'];
                    $lines[] = [$r['id'], $r['balance'], 0, "Close $year income"];
                } else {
                    $expenses += $r['balance'];
                    $lines[] = [$r['id'], 0, $r['balance'], "Close $year expenses"];
                }
            }
            $surplus = round($income - $expenses, 2);
            $entryId = null;
            if ($lines) {
                $lines[] = [self::account('retained_earnings'), $surplus < 0 ? -$surplus : 0, $surplus > 0 ? $surplus : 0, "$year " . ($surplus >= 0 ? 'surplus' : 'deficit')];
                $entryId = self::post([
                    'date' => $end, 'source' => 'closing', 'closing' => true,
                    'reference' => "FY$year", 'narration' => "Year-end close $year: surplus transferred to retained earnings",
                ], $lines);
            }
            DB::update('financial_years', [
                'status' => 'closed', 'closing_entry_id' => $entryId, 'income' => round($income, 2), 'expenses' => round($expenses, 2),
                'surplus' => $surplus, 'closed_by' => Auth::id(), 'closed_at' => now(),
            ], 'year = ?', [$year]);
            DB::query("INSERT INTO settings (`key`, `value`) VALUES ('books_closed_through', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$end]);
            settings_flush();
            return ['surplus' => $surplus, 'entry_id' => $entryId];
        });
    }

    public static function reopenYear(int $year): void
    {
        DB::transaction(function () use ($year) {
            $fy = DB::row('SELECT * FROM financial_years WHERE year = ? FOR UPDATE', [$year]);
            if (!$fy || $fy['status'] !== 'closed') {
                throw new AppError("$year is not closed.");
            }
            $latest = (int) DB::value("SELECT MAX(year) FROM financial_years WHERE status = 'closed'");
            if ($latest !== $year) {
                throw new AppError("Reopen $latest first — only the most recently closed year can be reopened.");
            }
            $prevClosed = DB::value("SELECT status FROM financial_years WHERE year = ?", [$year - 1]) === 'closed';
            DB::query("INSERT INTO settings (`key`, `value`) VALUES ('books_closed_through', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$prevClosed ? ($year - 1) . '-12-31' : '']);
            settings_flush();
            if ($fy['closing_entry_id']) {
                self::reverseEntry((int) $fy['closing_entry_id'], "$year-12-31", "Reopen $year: reverse year-end close", ['skip_lock' => true]);
            }
            DB::update('financial_years', ['status' => 'open', 'closing_entry_id' => null, 'closed_by' => null, 'closed_at' => null], 'year = ?', [$year]);
        });
    }
}
