<?php
defined('SACCO') or exit;

/**
 * Loan lifecycle: quote → apply → approve/reject → disburse → repay → close.
 *
 * Interest methods:
 *   flat      – interest on the original principal for every period.
 *   reducing  – amortised (equal installments), interest on the running balance.
 *
 * Repayments are allocated oldest installment first: penalty → interest → principal.
 */
final class LoanService
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'active', 'closed', 'written_off'];

    /* -----------------------------------------------------------------
     | Calculation
     * --------------------------------------------------------------- */

    public static function periodicRate(float $rate, string $period): float
    {
        return $period === 'monthly' ? $rate / 100 : $rate / 100 / 12;
    }

    /** Add calendar months, clamping to month end (31 Jan + 1 month = 28/29 Feb). */
    public static function addMonths(string $date, int $months): string
    {
        $d = new DateTimeImmutable($date);
        $day = (int) $d->format('j');
        $target = $d->modify('first day of this month')->modify("+$months months");
        return $target->setDate(
            (int) $target->format('Y'),
            (int) $target->format('n'),
            min($day, (int) $target->format('t'))
        )->format('Y-m-d');
    }

    public static function schedule(float $principal, float $rate, string $period, string $method, int $term, string $startDate): array
    {
        $r = self::periodicRate($rate, $period);
        $principal = round($principal, 2);
        $balance = $principal;
        $rows = [];
        $totalInterest = 0.0;

        if ($method === 'flat') {
            $interestTotal = round($principal * $r * $term, 2);
            $pEach = round($principal / $term, 2);
            $iEach = round($interestTotal / $term, 2);
            for ($i = 1; $i <= $term; $i++) {
                $p = $i < $term ? $pEach : round($principal - $pEach * ($term - 1), 2);
                $in = $i < $term ? $iEach : round($interestTotal - $iEach * ($term - 1), 2);
                $balance = round($balance - $p, 2);
                $rows[] = self::row($i, $startDate, $p, $in, $balance);
            }
            $totalInterest = $interestTotal;
        } else {
            $emi = $r > 0 ? $principal * $r / (1 - (1 + $r) ** -$term) : $principal / $term;
            $emi = round($emi, 2);
            for ($i = 1; $i <= $term; $i++) {
                $in = round($balance * $r, 2);
                $p = $i < $term ? min(round($emi - $in, 2), $balance) : $balance;
                $balance = round($balance - $p, 2);
                $rows[] = self::row($i, $startDate, $p, $in, $balance);
                $totalInterest += $in;
            }
        }

        $totalInterest = round($totalInterest, 2);
        return [
            'installments'   => $rows,
            'installment'    => $rows[0]['total'] ?? 0,
            'total_interest' => $totalInterest,
            'total_payable'  => round($principal + $totalInterest, 2),
            'first_due_date' => $rows[0]['due_date'] ?? null,
            'maturity_date'  => $rows ? end($rows)['due_date'] : null,
        ];
    }

    private static function row(int $no, string $start, float $p, float $in, float $balance): array
    {
        return [
            'installment_no' => $no,
            'due_date'       => self::addMonths($start, $no),
            'principal'      => $p,
            'interest'       => $in,
            'total'          => round($p + $in, 2),
            'balance'        => max(0, $balance),
        ];
    }

    public static function quote(array $product, float $amount, int $term, ?string $start = null): array
    {
        $q = self::schedule($amount, (float) $product['interest_rate'], $product['rate_period'], $product['interest_method'], $term, $start ?? today());
        $q['processing_fee'] = round($amount * (float) $product['processing_fee_rate'] / 100, 2);
        $q['net_disbursement'] = round($amount - $q['processing_fee'], 2);
        return $q;
    }

    /* -----------------------------------------------------------------
     | Eligibility
     * --------------------------------------------------------------- */

    public static function eligibility(int $memberId, ?array $product = null): array
    {
        $savings = (float) DB::value("SELECT COALESCE(SUM(balance), 0) FROM savings_accounts WHERE member_id = ? AND status = 'active'", [$memberId]);
        $shares = (float) DB::value('SELECT COALESCE(balance, 0) FROM share_accounts WHERE member_id = ?', [$memberId]);
        $outstanding = (float) DB::value("SELECT COALESCE(SUM(balance), 0) FROM loans WHERE member_id = ? AND status = 'active'", [$memberId]);
        $pending = (float) DB::value("SELECT COALESCE(SUM(principal), 0) FROM loans WHERE member_id = ? AND status IN ('pending','approved')", [$memberId]);
        $guaranteed = (float) DB::value(
            "SELECT COALESCE(SUM(g.amount), 0) FROM loan_guarantors g JOIN loans l ON l.id = g.loan_id
             WHERE g.member_id = ? AND g.status = 'active' AND l.status IN ('pending','approved','active')",
            [$memberId]
        );

        $out = [
            'savings'      => $savings,
            'shares'       => $shares,
            'outstanding'  => $outstanding,
            'pending'      => $pending,
            'guaranteed'   => $guaranteed,
            'free_savings' => max(0, round($savings - $guaranteed, 2)),
        ];

        if ($product) {
            $max = (float) $product['max_amount'];
            $mult = (float) $product['savings_multiplier'];
            if ($mult > 0) {
                $max = min($max, max(0, $savings * $mult - $outstanding - $pending));
            }
            $out['max_eligible'] = round($max, 2);
            $out['multiplier'] = $mult;
        }
        return $out;
    }

    /* -----------------------------------------------------------------
     | Lifecycle
     * --------------------------------------------------------------- */

    /**
     * $d: member_id, product_id, amount, term_months, purpose, guarantors[[member_id, amount]], at?
     */
    public static function apply(array $d): int
    {
        return DB::transaction(function () use ($d) {
            // Locking the member serialises concurrent applications for the same member.
            $member = DB::row('SELECT * FROM members WHERE id = ? FOR UPDATE', [(int) $d['member_id']]);
            if (!$member) {
                throw new ValidationError(['member_id' => 'Select a member.']);
            }
            if ($member['status'] !== 'active') {
                throw new ValidationError(['member_id' => 'Only active members can apply for loans.']);
            }

            $product = DB::row('SELECT * FROM loan_products WHERE id = ?', [(int) $d['product_id']]);
            if (!$product || $product['status'] !== 'active') {
                throw new ValidationError(['product_id' => 'Select an active loan product.']);
            }

            $amount = round((float) $d['amount'], 2);
            $term = (int) $d['term_months'];
            $errors = [];
            if ($amount < (float) $product['min_amount'] || $amount > (float) $product['max_amount']) {
                $errors['amount'] = 'Amount must be between ' . money($product['min_amount']) . ' and ' . money($product['max_amount']) . '.';
            }
            if ($term < (int) $product['min_term'] || $term > (int) $product['max_term']) {
                $errors['term_months'] = "Term must be between {$product['min_term']} and {$product['max_term']} months.";
            }
            if ($errors) {
                throw new ValidationError($errors);
            }

            $elig = self::eligibility((int) $member['id'], $product);
            if ($amount > $elig['max_eligible'] + 0.001) {
                throw new ValidationError(['amount' => 'Maximum eligible amount for this member is ' . money($elig['max_eligible']) . '.']);
            }

            // Guarantors
            $guarantors = [];
            foreach ((array) ($d['guarantors'] ?? []) as $g) {
                $gid = (int) ($g['member_id'] ?? 0);
                $gamt = round((float) ($g['amount'] ?? 0), 2);
                if (!$gid) {
                    continue;
                }
                if ($gid === (int) $member['id']) {
                    throw new ValidationError(['guarantors' => 'A member cannot guarantee their own loan.']);
                }
                if (isset($guarantors[$gid])) {
                    throw new ValidationError(['guarantors' => 'Each guarantor can only be listed once.']);
                }
                if ($gamt <= 0) {
                    throw new ValidationError(['guarantors' => 'Enter the amount each guarantor is securing.']);
                }
                $gm = DB::row('SELECT id, first_name, last_name, status FROM members WHERE id = ? FOR UPDATE', [$gid]);
                if (!$gm || $gm['status'] !== 'active') {
                    throw new ValidationError(['guarantors' => 'All guarantors must be active members.']);
                }
                $free = self::eligibility($gid)['free_savings'];
                if ($gamt > $free + 0.001) {
                    throw new ValidationError(['guarantors' => "{$gm['first_name']} {$gm['last_name']} can guarantee at most " . money($free) . '.']);
                }
                $guarantors[$gid] = $gamt;
            }
            if (count($guarantors) < (int) $product['min_guarantors']) {
                throw new ValidationError(['guarantors' => "{$product['name']} requires at least {$product['min_guarantors']} guarantor(s)."]);
            }

            $at = $d['at'] ?? now();
            $q = self::quote($product, $amount, $term, substr($at, 0, 10));

            $id = DB::insert('loans', [
                'member_id'          => $member['id'],
                'product_id'         => $product['id'],
                'branch_id'          => $member['branch_id'],
                'principal'          => $amount,
                'interest_rate'      => $product['interest_rate'],
                'rate_period'        => $product['rate_period'],
                'interest_method'    => $product['interest_method'],
                'term_months'        => $term,
                'processing_fee'     => $q['processing_fee'],
                'total_interest'     => $q['total_interest'],
                'total_payable'      => $q['total_payable'],
                'installment_amount' => $q['installment'],
                'purpose'            => $d['purpose'] ?? null,
                'status'             => 'pending',
                'applied_at'         => $at,
                'created_by'         => Auth::id(),
                'created_at'         => $at,
            ]);
            DB::query('UPDATE loans SET loan_no = ? WHERE id = ?', ['LN' . str_pad((string) $id, 8, '0', STR_PAD_LEFT), $id]);

            foreach ($guarantors as $gid => $gamt) {
                DB::insert('loan_guarantors', ['loan_id' => $id, 'member_id' => $gid, 'amount' => $gamt, 'created_at' => $at]);
            }
            return $id;
        });
    }

    private static function lock(int $id): array
    {
        $loan = DB::row(
            'SELECT l.*, p.name AS product_name FROM loans l JOIN loan_products p ON p.id = l.product_id WHERE l.id = ? FOR UPDATE',
            [$id]
        );
        if (!$loan) {
            throw new AppError('Loan not found.', 404);
        }
        $own = Auth::user()['branch_id'] ?? null;
        if ($own && (int) $own !== (int) $loan['branch_id']) {
            throw new AppError('Loan not found.', 404);
        }
        return $loan;
    }

    public static function approve(int $id, ?string $notes = null, ?string $at = null): void
    {
        DB::transaction(function () use ($id, $notes, $at) {
            $loan = self::lock($id);
            if ($loan['status'] !== 'pending') {
                throw new AppError("Only pending applications can be approved (this loan is {$loan['status']}).");
            }
            DB::update('loans', [
                'status' => 'approved', 'approved_by' => Auth::id(), 'approved_at' => $at ?? now(), 'approval_notes' => $notes,
            ], 'id = ?', [$id]);
        });
    }

    public static function reject(int $id, string $reason): void
    {
        DB::transaction(function () use ($id, $reason) {
            $loan = self::lock($id);
            if (!in_array($loan['status'], ['pending', 'approved'], true)) {
                throw new AppError("This loan is {$loan['status']} and can no longer be rejected.");
            }
            DB::update('loans', [
                'status' => 'rejected', 'rejected_by' => Auth::id(), 'rejected_at' => now(), 'rejection_reason' => $reason,
            ], 'id = ?', [$id]);
            DB::query("UPDATE loan_guarantors SET status = 'released' WHERE loan_id = ?", [$id]);
        });
    }

    /**
     * $o: method (cash|mobile_money|bank|cheque|savings), payment_ref, date (Y-m-d), at
     */
    public static function disburse(int $id, array $o): array
    {
        return DB::transaction(function () use ($id, $o) {
            $loan = self::lock($id);
            if ($loan['status'] !== 'approved') {
                throw new AppError('Only approved loans can be disbursed.');
            }

            $date = $o['date'] ?? today();
            $at = $o['at'] ?? ($date === today() ? now() : "$date 10:00:00");
            $q = self::schedule((float) $loan['principal'], (float) $loan['interest_rate'], $loan['rate_period'], $loan['interest_method'], (int) $loan['term_months'], $date);

            // Batch insert the schedule.
            $values = [];
            $params = [];
            foreach ($q['installments'] as $row) {
                $values[] = '(?,?,?,?,?)';
                array_push($params, $id, $row['installment_no'], $row['due_date'], $row['principal'], $row['interest']);
            }
            DB::query('DELETE FROM loan_schedules WHERE loan_id = ?', [$id]);
            DB::query('INSERT INTO loan_schedules (loan_id, installment_no, due_date, principal_due, interest_due) VALUES ' . implode(',', $values), $params);

            $toSavings = ($o['method'] ?? 'cash') === 'savings';
            $method = $toSavings ? 'internal' : ($o['method'] ?? 'cash');
            $fee = (float) $loan['processing_fee'];

            DB::update('loans', [
                'status'              => 'active',
                'disbursed_by'        => Auth::id(),
                'disbursed_at'        => $at,
                'disbursement_method' => $o['method'] ?? 'cash',
                'total_interest'      => $q['total_interest'],
                'total_payable'       => $q['total_payable'],
                'installment_amount'  => $q['installment'],
                'balance'             => $q['total_payable'],
                'first_due_date'      => $q['first_due_date'],
                'maturity_date'       => $q['maturity_date'],
            ], 'id = ?', [$id]);

            $tx = Ledger::post([
                'branch_id'      => $loan['branch_id'],
                'member_id'      => $loan['member_id'],
                'account_type'   => 'loan',
                'account_id'     => $id,
                'type'           => 'loan_disbursement',
                'amount'         => $loan['principal'],
                'balance_after'  => $q['total_payable'],
                'flow'           => $toSavings ? 'none' : 'out',
                'payment_method' => $method,
                'payment_ref'    => $o['payment_ref'] ?? null,
                'narration'      => "Disbursement of {$loan['loan_no']} ({$loan['product_name']})",
                'at'             => $at,
            ]);

            if ($fee > 0) {
                Ledger::post([
                    'branch_id'      => $loan['branch_id'],
                    'member_id'      => $loan['member_id'],
                    'account_type'   => 'income',
                    'account_id'     => $id,
                    'type'           => 'processing_fee',
                    'amount'         => $fee,
                    'flow'           => $toSavings ? 'none' : 'in',
                    'payment_method' => $method,
                    'payment_ref'    => $tx['ref'],
                    'narration'      => "Processing fee deducted from {$loan['loan_no']}",
                    'at'             => $at,
                ]);
            }

            if ($toSavings) {
                $acc = SavingsService::defaultAccountId((int) $loan['member_id']);
                if (!$acc) {
                    throw new AppError('The member has no active savings account to receive the funds.');
                }
                SavingsService::deposit($acc, (float) $loan['principal'] - $fee, [
                    'flow' => 'none', 'payment_method' => 'internal', 'payment_ref' => $tx['ref'],
                    'narration' => "Loan proceeds {$loan['loan_no']}", 'at' => $at,
                ]);
            }

            return ['transaction' => $tx, 'net' => round((float) $loan['principal'] - $fee, 2)];
        });
    }

    /**
     * $o: source (cash|savings), payment_method, payment_ref, savings_account_id, at
     */
    public static function repay(int $id, float $amount, array $o = []): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new ValidationError(['amount' => 'Amount must be greater than zero.']);
        }

        return DB::transaction(function () use ($id, $amount, $o) {
            $loan = self::lock($id);
            if ($loan['status'] !== 'active') {
                throw new AppError('Repayments can only be made on active loans.');
            }
            if ($amount > (float) $loan['balance'] + 0.001) {
                throw new ValidationError(['amount' => 'Amount exceeds the outstanding balance of ' . money($loan['balance']) . '.']);
            }

            $at = $o['at'] ?? now();
            $fromSavings = ($o['source'] ?? 'cash') === 'savings';
            if ($fromSavings) {
                $accId = (int) ($o['savings_account_id'] ?? 0) ?: SavingsService::defaultAccountId((int) $loan['member_id']);
                if (!$accId) {
                    throw new AppError('The member has no savings account to pay from.');
                }
                SavingsService::withdraw($accId, $amount, [
                    'member_id' => $loan['member_id'], 'flow' => 'none', 'payment_method' => 'internal', 'no_fee' => true,
                    'narration' => "Repayment of {$loan['loan_no']}", 'at' => $at,
                ]);
            }

            // Allocate across installments, oldest first.
            $left = $amount;
            $alloc = ['principal' => 0.0, 'interest' => 0.0, 'penalty' => 0.0];
            $payDate = substr($at, 0, 10);
            $rows = DB::all("SELECT * FROM loan_schedules WHERE loan_id = ? AND status <> 'paid' ORDER BY installment_no FOR UPDATE", [$id]);

            foreach ($rows as $s) {
                if ($left <= 0.0001) {
                    break;
                }
                $paid = ['penalty' => (float) $s['penalty_paid'], 'interest' => (float) $s['interest_paid'], 'principal' => (float) $s['principal_paid']];
                foreach (['penalty', 'interest', 'principal'] as $part) {
                    $owed = round((float) $s[$part . '_due'] - $paid[$part], 2);
                    $take = min($left, max(0, $owed));
                    if ($take > 0) {
                        $paid[$part] = round($paid[$part] + $take, 2);
                        $alloc[$part] += $take;
                        $left = round($left - $take, 2);
                    }
                }
                $remaining = round((float) $s['penalty_due'] + (float) $s['interest_due'] + (float) $s['principal_due']
                    - $paid['penalty'] - $paid['interest'] - $paid['principal'], 2);
                $status = $remaining <= 0 ? 'paid' : ($s['due_date'] < $payDate ? 'overdue' : 'partial');

                DB::update('loan_schedules', [
                    'penalty_paid'   => $paid['penalty'],
                    'interest_paid'  => $paid['interest'],
                    'principal_paid' => $paid['principal'],
                    'status'         => $status,
                    'paid_at'        => $status === 'paid' ? $at : null,
                ], 'id = ?', [$s['id']]);
            }

            $newBalance = round((float) $loan['balance'] - $amount, 2);
            $closed = $newBalance <= 0.009;
            DB::update('loans', [
                'amount_paid' => round((float) $loan['amount_paid'] + $amount, 2),
                'balance'     => $closed ? 0 : $newBalance,
                'status'      => $closed ? 'closed' : 'active',
                'closed_at'   => $closed ? $at : null,
            ], 'id = ?', [$id]);
            if ($closed) {
                DB::query("UPDATE loan_guarantors SET status = 'released' WHERE loan_id = ?", [$id]);
            }

            $method = $fromSavings ? 'internal' : ($o['payment_method'] ?? 'cash');
            $tx = Ledger::post([
                'branch_id'      => $loan['branch_id'],
                'member_id'      => $loan['member_id'],
                'account_type'   => 'loan',
                'account_id'     => $id,
                'type'           => 'loan_repayment',
                'amount'         => $amount,
                'balance_after'  => max(0, $newBalance),
                'flow'           => $fromSavings ? 'none' : 'in',
                'payment_method' => $method,
                'payment_ref'    => $o['payment_ref'] ?? null,
                'narration'      => "Repayment of {$loan['loan_no']}" . ($closed ? ' — loan cleared' : ''),
                'at'             => $at,
            ]);

            DB::insert('loan_repayments', [
                'loan_id'        => $id,
                'transaction_id' => $tx['id'],
                'amount'         => $amount,
                'principal_paid' => round($alloc['principal'], 2),
                'interest_paid'  => round($alloc['interest'], 2),
                'penalty_paid'   => round($alloc['penalty'], 2),
                'payment_method' => $method,
                'payment_ref'    => $o['payment_ref'] ?? null,
                'created_by'     => Auth::id(),
                'created_at'     => $at,
            ]);

            return ['transaction' => $tx, 'balance' => max(0, $newBalance), 'closed' => $closed, 'allocation' => $alloc];
        });
    }

    public static function writeOff(int $id, string $reason): void
    {
        DB::transaction(function () use ($id, $reason) {
            $loan = self::lock($id);
            if ($loan['status'] !== 'active') {
                throw new AppError('Only active loans can be written off.');
            }
            DB::update('loans', [
                'status' => 'written_off', 'closed_at' => now(),
                'approval_notes' => mb_substr('Written off: ' . $reason, 0, 255),
            ], 'id = ?', [$id]);
        });
    }

    /** Next amount due (arrears + the current installment). */
    public static function dueNow(int $loanId): float
    {
        $today = today();
        $arrears = (float) DB::value(
            "SELECT COALESCE(SUM(principal_due + interest_due + penalty_due - principal_paid - interest_paid - penalty_paid), 0)
             FROM loan_schedules WHERE loan_id = ? AND status <> 'paid' AND due_date < ?",
            [$loanId, $today]
        );
        $next = (float) DB::value(
            "SELECT principal_due + interest_due + penalty_due - principal_paid - interest_paid - penalty_paid
             FROM loan_schedules WHERE loan_id = ? AND status <> 'paid' AND due_date >= ? ORDER BY installment_no LIMIT 1",
            [$loanId, $today]
        );
        return round($arrears + $next, 2);
    }

    /* -----------------------------------------------------------------
     | Daily jobs (run from cron/daily.php or Settings → Run daily jobs)
     * --------------------------------------------------------------- */

    public static function runDailyJobs(?string $today = null): array
    {
        $today ??= today();
        $grace = max(0, (int) setting('penalty_grace_days', 0));
        $cutoff = date('Y-m-d', strtotime("$today -$grace days"));

        $marked = DB::query(
            "UPDATE loan_schedules s JOIN loans l ON l.id = s.loan_id
             SET s.status = 'overdue'
             WHERE s.due_date < ? AND s.status IN ('pending','partial') AND l.status = 'active'",
            [$today]
        )->rowCount();

        $applied = 0;
        $total = 0.0;
        $lastId = 0;
        do {
            $rows = DB::all(
                "SELECT s.id, s.loan_id, s.installment_no, p.penalty_rate,
                        (s.principal_due + s.interest_due - s.principal_paid - s.interest_paid) AS unpaid
                 FROM loan_schedules s
                 JOIN loans l ON l.id = s.loan_id
                 JOIN loan_products p ON p.id = l.product_id
                 WHERE s.status = 'overdue' AND s.penalty_due = 0 AND s.due_date < ?
                   AND l.status = 'active' AND p.penalty_rate > 0 AND s.id > ?
                 ORDER BY s.id LIMIT 500",
                [$cutoff, $lastId]
            );
            foreach ($rows as $r) {
                $lastId = (int) $r['id'];
                $penalty = round((float) $r['unpaid'] * (float) $r['penalty_rate'] / 100, 2);
                if ($penalty <= 0) {
                    continue;
                }
                $done = DB::transaction(function () use ($r, $penalty, $today) {
                    $loan = DB::row('SELECT id, member_id, branch_id, loan_no, balance FROM loans WHERE id = ? FOR UPDATE', [$r['loan_id']]);
                    $claimed = DB::query('UPDATE loan_schedules SET penalty_due = ? WHERE id = ? AND penalty_due = 0', [$penalty, $r['id']])->rowCount();
                    if (!$claimed) {
                        return false; // another run already charged this installment
                    }
                    DB::query('UPDATE loans SET penalty_accrued = penalty_accrued + ?, balance = balance + ? WHERE id = ?', [$penalty, $penalty, $r['loan_id']]);
                    Ledger::post([
                        'branch_id'      => $loan['branch_id'],
                        'member_id'      => $loan['member_id'],
                        'account_type'   => 'loan',
                        'account_id'     => $loan['id'],
                        'type'           => 'penalty',
                        'amount'         => $penalty,
                        'balance_after'  => (float) $loan['balance'] + $penalty,
                        'flow'           => 'none',
                        'payment_method' => 'internal',
                        'narration'      => "Late payment penalty — {$loan['loan_no']} installment #{$r['installment_no']}",
                        'at'             => "$today 00:05:00",
                    ]);
                    return true;
                });
                if ($done) {
                    $applied++;
                    $total += $penalty;
                }
            }
        } while (count($rows) === 500);

        return ['overdue_marked' => $marked, 'penalties_applied' => $applied, 'penalty_total' => round($total, 2)];
    }
}
