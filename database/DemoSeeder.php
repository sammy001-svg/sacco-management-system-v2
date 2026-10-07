<?php
defined('SACCO') or exit;

/**
 * Generates realistic, internally consistent demo data by driving the real
 * services (so every balance, schedule and ledger row is correct).
 *
 *   php database/seed_demo.php --members=500
 */
final class DemoSeeder
{
    private const FIRST = ['James', 'Mary', 'John', 'Grace', 'Peter', 'Faith', 'David', 'Mercy', 'Joseph', 'Esther', 'Daniel', 'Ann',
        'Samuel', 'Joyce', 'Brian', 'Lucy', 'Kevin', 'Caroline', 'Dennis', 'Purity', 'Collins', 'Diana', 'Victor', 'Sharon', 'Felix',
        'Winnie', 'Moses', 'Beatrice', 'Eric', 'Naomi', 'George', 'Agnes', 'Stephen', 'Rose', 'Paul', 'Catherine', 'Martin', 'Irene',
        'Charles', 'Janet', 'Francis', 'Lilian', 'Kennedy', 'Doris', 'Evans', 'Gladys', 'Isaac', 'Ruth', 'Wilson', 'Susan'];
    private const LAST = ['Mwangi', 'Otieno', 'Kamau', 'Wanjiru', 'Ochieng', 'Njoroge', 'Kiprono', 'Akinyi', 'Mutua', 'Wambui', 'Kariuki',
        'Achieng', 'Kiplagat', 'Nyambura', 'Omondi', 'Chebet', 'Maina', 'Atieno', 'Kimani', 'Wairimu', 'Odhiambo', 'Jeptoo', 'Gitau',
        'Muthoni', 'Kibet', 'Adhiambo', 'Njeri', 'Rotich', 'Owino', 'Waweru', 'Cheruiyot', 'Nduta', 'Kiprotich', 'Auma', 'Ngugi', 'Korir'];
    private const JOBS = [['Teacher', 'Teachers Service Commission'], ['Nurse', 'County Referral Hospital'], ['Farmer', 'Self-employed'],
        ['Accountant', 'Safari Logistics Ltd'], ['Engineer', 'Kenya Power'], ['Trader', 'Self-employed'], ['Driver', 'City Shuttle Co.'],
        ['Civil Servant', 'Ministry of Agriculture'], ['Software Developer', 'Savannah Tech'], ['Police Officer', 'National Police Service'],
        ['Mechanic', 'Self-employed'], ['Banker', 'Equity Bank'], ['Lecturer', 'University of Nairobi'], ['Tailor', 'Self-employed']];

    private array $branches = [];
    private array $memberIds = [];
    private int $nid = 21000000;
    private $log;

    public function __construct(?callable $log = null)
    {
        $this->log = $log ?? fn(string $m) => null;
    }

    public function run(int $members = 200): array
    {
        mt_srand(20261005);
        $admin = (int) DB::value("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        Auth::actAs($admin ?: null);
        $this->nid += (int) DB::value('SELECT COUNT(*) FROM members') * 13;

        $this->seedBranchesAndStaff();
        ($this->log)("Creating $members members with 18 months of history…");

        $start = microtime(true);
        for ($i = 1; $i <= $members; $i++) {
            DB::transaction(fn() => $this->seedMember());
            if ($i % 25 === 0 || $i === $members) {
                ($this->log)(sprintf('  %d/%d members (%.1fs)', $i, $members, microtime(true) - $start));
            }
        }

        $this->seedExpenses($members);
        $this->seedPipeline();

        $jobs = LoanService::runDailyJobs();
        cache_flush();
        ($this->log)("Daily jobs: {$jobs['overdue_marked']} installments overdue, {$jobs['penalties_applied']} penalties.");

        return ['members' => $members, 'seconds' => round(microtime(true) - $start, 1)];
    }

    private function seedBranchesAndStaff(): void
    {
        $defs = [['WST', 'Westlands Branch', 'Westlands, Nairobi'], ['MSA', 'Mombasa Branch', 'Moi Avenue, Mombasa'], ['KSM', 'Kisumu Branch', 'Oginga Odinga St, Kisumu']];
        foreach ($defs as [$code, $name, $loc]) {
            DB::query('INSERT IGNORE INTO branches (code, name, location, phone) VALUES (?,?,?,?)', [$code, $name, $loc, '+254 7' . mt_rand(10, 99) . ' ' . mt_rand(100, 999) . ' ' . mt_rand(100, 999)]);
        }
        $ids = array_column(DB::all("SELECT id FROM branches WHERE status = 'active' ORDER BY id"), 'id');
        $this->branches = $ids ? array_map('intval', $ids) : [1];

        $hash = password_hash('Password@123', PASSWORD_DEFAULT);
        $staff = [
            ['Wanjiku Manager', 'manager@sacco.local', 'manager', null],
            ['Otieno Teller', 'teller@sacco.local', 'teller', $this->branches[0]],
            ['Achieng Loans', 'loans@sacco.local', 'loan_officer', null],
            ['Kamau Accounts', 'accounts@sacco.local', 'accountant', null],
        ];
        foreach ($staff as [$name, $email, $role, $branch]) {
            DB::query('INSERT IGNORE INTO users (name, email, password_hash, role, branch_id, phone) VALUES (?,?,?,?,?,?)',
                [$name, $email, $hash, $role, $branch, '07' . mt_rand(10000000, 99999999)]);
        }
        ($this->log)('Branches and staff accounts ready (staff password: Password@123).');
    }

    private function pick(array $a): mixed
    {
        return $a[array_rand($a)];
    }

    private function chance(int $pct): bool
    {
        return mt_rand(1, 100) <= $pct;
    }

    private function seedMember(): void
    {
        $now = time();
        // Skew joins toward recent months for a visible growth curve.
        $monthsAgo = (int) floor(18 * (1 - sqrt(mt_rand(0, 1000) / 1000)));
        $joined = strtotime("-$monthsAgo months -" . mt_rand(0, 27) . ' days', $now);
        $joinDate = date('Y-m-d', $joined);

        $first = $this->pick(self::FIRST);
        $last = $this->pick(self::LAST);
        [$job, $employer] = $this->pick(self::JOBS);
        $this->nid += mt_rand(3, 29);
        $gender = in_array($first, ['Mary', 'Grace', 'Faith', 'Mercy', 'Esther', 'Ann', 'Joyce', 'Lucy', 'Caroline', 'Purity', 'Diana', 'Sharon', 'Winnie', 'Beatrice', 'Naomi', 'Agnes', 'Rose', 'Catherine', 'Irene', 'Janet', 'Lilian', 'Doris', 'Gladys', 'Ruth', 'Susan'], true) ? 'female' : 'male';

        $memberId = MemberService::create([
            'branch_id'                => $this->pick($this->branches),
            'first_name'               => $first,
            'last_name'                => $last,
            'gender'                   => $gender,
            'date_of_birth'            => date('Y-m-d', strtotime('-' . mt_rand(22, 60) . ' years -' . mt_rand(0, 360) . ' days')),
            'national_id'              => (string) $this->nid,
            'phone'                    => '07' . mt_rand(10, 99) . mt_rand(100000, 999999),
            'email'                    => strtolower($first . '.' . $last . mt_rand(1, 999)) . '@example.com',
            'address'                  => $this->pick(['Nairobi', 'Thika', 'Nakuru', 'Mombasa', 'Kisumu', 'Eldoret', 'Machakos', 'Nyeri']),
            'occupation'               => $job,
            'employer'                 => $employer,
            'monthly_income'           => mt_rand(25, 250) * 1000,
            'next_of_kin_name'         => $this->pick(self::FIRST) . ' ' . $last,
            'next_of_kin_phone'        => '07' . mt_rand(10000000, 99999999),
            'next_of_kin_relationship' => $this->pick(['Spouse', 'Parent', 'Sibling', 'Child']),
            'status'                   => 'active',
            'joined_at'                => $joinDate,
        ], [
            'registration_fee' => (float) setting('registration_fee', 1000),
            'payment_method'   => $this->pick(['cash', 'mobile_money']),
            'at'               => $joinDate . ' 09:' . str_pad((string) mt_rand(0, 59), 2, '0', STR_PAD_LEFT) . ':00',
        ]);
        $this->memberIds[] = $memberId;
        $account = SavingsService::defaultAccountId($memberId);

        ShareService::purchase($memberId, mt_rand(10, 80), [
            'payment_method' => 'mobile_money', 'payment_ref' => $this->mpesaRef(),
            'at' => $joinDate . ' 10:15:00',
        ]);

        $saver = mt_rand(2, 25) * 500;     // typical monthly contribution
        $loanId = null;
        $loanMonth = $this->chance(55) ? mt_rand(3, 9) : 99;
        $disciplined = $this->chance(78);

        for ($m = 0; $m <= $monthsAgo; $m++) {
            $day = strtotime("+$m months", $joined);
            if ($day > $now) {
                break;
            }
            $at = date('Y-m-d', $day) . ' ' . sprintf('%02d:%02d:00', mt_rand(8, 17), mt_rand(0, 59));

            if ($this->chance(88)) {
                SavingsService::deposit($account, $saver + mt_rand(0, 4) * 250, [
                    'payment_method' => $this->pick(['mobile_money', 'mobile_money', 'cash', 'bank']),
                    'payment_ref' => $this->mpesaRef(), 'narration' => 'Monthly contribution', 'at' => $at,
                ]);
            }
            if ($m > 2 && $this->chance(12)) {
                try {
                    SavingsService::withdraw($account, mt_rand(1, 6) * 1000, ['payment_method' => 'mobile_money', 'at' => $at]);
                } catch (AppError) {
                }
            }

            // Loan lifecycle
            if ($m === $loanMonth && !$loanId) {
                $loanId = $this->tryLoan($memberId, $at);
            } elseif ($loanId && $m > $loanMonth) {
                $loan = DB::row('SELECT status, balance, installment_amount FROM loans WHERE id = ?', [$loanId]);
                if ($loan['status'] === 'active' && ($disciplined ? $this->chance(95) : $this->chance(55))) {
                    $amt = min((float) $loan['balance'], (float) $loan['installment_amount'] * ($this->chance(10) ? 2 : 1));
                    LoanService::repay($loanId, $amt, ['payment_method' => $this->pick(['mobile_money', 'bank', 'cash']), 'payment_ref' => $this->mpesaRef(), 'at' => $at]);
                }
            }
        }
    }

    private function tryLoan(int $memberId, string $at): ?int
    {
        $productId = $this->chance(60) ? 2 : 1; // emergency (no guarantors) or normal
        $product = DB::row('SELECT * FROM loan_products WHERE id = ?', [$productId]);
        if (!$product) {
            return null;
        }
        $elig = LoanService::eligibility($memberId, $product);
        $max = min($elig['max_eligible'], (float) $product['max_amount']);
        if ($max < (float) $product['min_amount']) {
            return null;
        }
        $amount = round(mt_rand((int) $product['min_amount'], (int) $max) / 500) * 500;
        $amount = max((float) $product['min_amount'], $amount);

        $guarantors = [];
        if ((int) $product['min_guarantors'] > 0) {
            $pool = array_slice($this->memberIds, 0, -1);
            shuffle($pool);
            foreach ($pool as $gid) {
                $free = LoanService::eligibility($gid)['free_savings'];
                if ($free >= 2000) {
                    $guarantors[] = ['member_id' => $gid, 'amount' => min($free, round($amount / 2, 2))];
                }
                if (count($guarantors) >= (int) $product['min_guarantors']) {
                    break;
                }
            }
            if (count($guarantors) < (int) $product['min_guarantors']) {
                return null;
            }
        }

        try {
            $term = mt_rand((int) $product['min_term'], min(24, (int) $product['max_term']));
            $id = LoanService::apply([
                'member_id' => $memberId, 'product_id' => $productId, 'amount' => $amount, 'term_months' => $term,
                'purpose' => $this->pick(['School fees', 'Business stock', 'Medical bills', 'Home improvement', 'Farm inputs', 'Motorbike purchase']),
                'guarantors' => $guarantors, 'at' => $at,
            ]);
            LoanService::approve($id, 'Meets policy requirements', $at);
            LoanService::disburse($id, ['method' => $this->pick(['savings', 'mobile_money', 'bank']), 'date' => substr($at, 0, 10), 'at' => $at, 'payment_ref' => $this->mpesaRef()]);
            return $id;
        } catch (AppError) {
            return null;
        }
    }

    /** A few loans in each pipeline state so every screen has something to show. */
    private function seedPipeline(): void
    {
        $candidates = DB::all("SELECT m.id FROM members m WHERE m.status = 'active'
            AND NOT EXISTS (SELECT 1 FROM loans l WHERE l.member_id = m.id) ORDER BY RAND() LIMIT 12");
        foreach ($candidates as $i => $c) {
            try {
                $id = LoanService::apply([
                    'member_id' => $c['id'], 'product_id' => 2, 'amount' => mt_rand(4, 30) * 1000, 'term_months' => mt_rand(3, 12),
                    'purpose' => $this->pick(['Emergency medical', 'Rent arrears', 'Stock top-up']), 'guarantors' => [],
                    'at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(0, 6) . ' days')),
                ]);
                if ($i % 3 === 1) {
                    LoanService::approve($id, 'Approved by credit committee');
                } elseif ($i % 3 === 2) {
                    LoanService::reject($id, 'Insufficient repayment capacity');
                }
            } catch (AppError) {
            }
        }
    }

    private function seedExpenses(int $members): void
    {
        // Scale running costs to the size of the SACCO so reports stay realistic.
        $scale = max(0.04, min(1.0, $members / 1500));
        $cats = [['Salaries', 180000, 260000], ['Rent', 45000, 90000], ['Utilities', 6000, 18000], ['Stationery', 2000, 9000],
            ['Transport', 3000, 15000], ['Marketing', 5000, 30000], ['ICT & Software', 8000, 25000], ['Bank Charges', 500, 3500]];
        foreach ($this->branches as $b) {
            for ($m = 17; $m >= 0; $m--) {
                foreach ($cats as [$cat, $min, $max]) {
                    if ($cat !== 'Salaries' && $cat !== 'Rent' && !$this->chance(70)) {
                        continue;
                    }
                    $date = date('Y-m-d', strtotime("-$m months", strtotime(date('Y-m-05'))) + mt_rand(0, 20) * 86400);
                    if ($date > today()) {
                        continue;
                    }
                    $amount = max(100, round(mt_rand($min, $max) * $scale / 100) * 100);
                    $glId = Accounting::expenseAccount(null, $cat);
                    $tx = Ledger::post([
                        'branch_id' => $b, 'account_type' => 'expense', 'type' => 'expense', 'amount' => $amount,
                        'flow' => 'out', 'payment_method' => 'bank', 'narration' => "$cat expense", 'at' => "$date 12:00:00",
                        'gl' => ['gl_account_id' => $glId],
                    ]);
                    DB::insert('expenses', [
                        'branch_id' => $b, 'category' => $cat, 'gl_account_id' => $glId, 'payee' => $cat === 'Salaries' ? 'Staff payroll' : 'Supplier',
                        'amount' => $amount, 'description' => "$cat for " . date('F Y', strtotime($date)), 'payment_method' => 'bank',
                        'expense_date' => $date, 'transaction_id' => $tx['id'], 'created_by' => Auth::id(), 'created_at' => "$date 12:00:00",
                    ]);
                }
            }
        }
    }

    private function mpesaRef(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789';
        $s = 'S';
        for ($i = 0; $i < 9; $i++) {
            $s .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        return $s;
    }
}
