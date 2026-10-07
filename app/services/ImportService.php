<?php
defined('SACCO') or exit;

/**
 * CSV imports.
 *
 *  members  – migrate members from another system, with opening savings and
 *             share balances (posted against Opening Balance Equity).
 *  checkoff – an employer's payroll deduction remittance: each row splits
 *             into savings, shares and loan repayments, received by bank.
 *
 * Files are validated row by row on upload (nothing is posted), reviewed,
 * then processed in resumable chunks; one bad row never blocks the rest.
 */
final class ImportService
{
    public const MAX_ROWS = 50000;
    public const CHUNK = 200;

    public const COLUMNS = [
        'members'  => ['first_name', 'last_name', 'national_id', 'phone', 'other_names', 'gender', 'date_of_birth', 'email', 'address',
                       'occupation', 'employer', 'member_no', 'branch_code', 'joined_at', 'opening_savings', 'opening_shares'],
        'checkoff' => ['member_no', 'savings', 'shares', 'loan', 'loan_no'],
    ];
    public const REQUIRED = [
        'members'  => ['first_name', 'last_name', 'national_id', 'phone'],
        'checkoff' => ['member_no'],
    ];

    public static function template(string $kind): string
    {
        $rows = $kind === 'members'
            ? [self::COLUMNS['members'], ['Jane', 'Akinyi', '30111222', '0712345678', 'Wanjiku', 'female', '1990-04-12', 'jane@example.com', 'Kisumu', 'Teacher', 'TSC', '', 'HQ', '2019-01-15', '45000', '5000']]
            : [self::COLUMNS['checkoff'], ['MBR000001', '3000', '500', '4500', ''], ['MBR000002', '2000', '0', '0', '']];
        $out = fopen('php://temp', 'w+');
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        rewind($out);
        return "\xEF\xBB\xBF" . stream_get_contents($out);
    }

    /** @return array{0:array, 1:array} headers and rows (assoc) */
    public static function readCsv(string $path, string $kind): array
    {
        $fh = fopen($path, 'r');
        if (!$fh) {
            throw new AppError('Could not read the uploaded file.');
        }
        $first = (string) fgets($fh);
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $headers = array_map(fn($h) => trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($h))), '_'), str_getcsv($first, $delim));
        $missing = array_diff(self::REQUIRED[$kind], $headers);
        if ($missing) {
            throw new AppError('The file is missing required column(s): ' . implode(', ', $missing) . '. Download the template for the expected layout.');
        }
        $rows = [];
        while (($cells = fgetcsv($fh, 0, $delim)) !== false) {
            if (count(array_filter($cells, fn($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw new AppError('Files are limited to ' . number_format(self::MAX_ROWS) . ' rows. Split the file and import it in parts.');
            }
            $row = [];
            foreach ($headers as $i => $h) {
                if (in_array($h, self::COLUMNS[$kind], true)) {
                    $row[$h] = trim((string) ($cells[$i] ?? ''));
                }
            }
            $rows[] = $row;
        }
        fclose($fh);
        if (!$rows) {
            throw new AppError('The file has no data rows.');
        }
        return [$headers, $rows];
    }

    private static function date(string $v): ?string
    {
        if ($v === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d'] as $f) {
            $d = DateTime::createFromFormat("!$f", $v);
            if ($d && $d->format($f) === $v) {
                return $d->format('Y-m-d');
            }
        }
        return 'invalid';
    }

    private static function amount(string $v): ?float
    {
        $v = str_replace([',', ' '], '', $v);
        if ($v === '') {
            return 0.0;
        }
        return is_numeric($v) && (float) $v >= 0 ? round((float) $v, 2) : null;
    }

    /** Validate and stage a member migration file. */
    public static function stageMembers(string $path, string $filename): int
    {
        [, $rows] = self::readCsv($path, 'members');
        $branches = array_change_key_case(DB::pairs('SELECT code, id FROM branches'), CASE_UPPER);
        $own = Auth::user()['branch_id'] ?? null;
        $defaultBranch = $own ?: (int) DB::value('SELECT MIN(id) FROM branches');

        // Duplicates against the database, checked in bulk.
        $ids = array_filter(array_column($rows, 'national_id'));
        $nos = array_filter(array_column($rows, 'member_no'));
        $takenIds = self::existing('national_id', $ids);
        $takenNos = self::existing('member_no', $nos);

        $seenIds = $seenNos = [];
        $staged = [];
        foreach ($rows as $i => $r) {
            $errors = [];
            foreach (self::REQUIRED['members'] as $req) {
                if (($r[$req] ?? '') === '') {
                    $errors[] = label($req) . ' is required';
                }
            }
            $genders = ['m' => 'male', 'male' => 'male', 'f' => 'female', 'female' => 'female', 'other' => 'other', '' => null];
            $g = strtolower($r['gender'] ?? '');
            $gender = array_key_exists($g, $genders) ? $genders[$g] : 'invalid';
            if ($gender === 'invalid') {
                $errors[] = 'Gender must be male, female or other';
            }
            $dob = self::date($r['date_of_birth'] ?? '');
            $joined = self::date($r['joined_at'] ?? '') ?? today();
            if ($dob === 'invalid' || $joined === 'invalid') {
                $errors[] = 'Dates must be YYYY-MM-DD or DD/MM/YYYY';
            }
            if (($r['phone'] ?? '') !== '' && !preg_match('/^\+?[0-9][0-9\s\-()]{6,19}$/', $r['phone'])) {
                $errors[] = 'Invalid phone number';
            }
            if (($r['email'] ?? '') !== '' && !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email';
            }
            $nid = $r['national_id'] ?? '';
            if ($nid !== '' && (isset($takenIds[$nid]) || isset($seenIds[$nid]))) {
                $errors[] = isset($takenIds[$nid]) ? 'National ID already registered' : 'National ID repeated in file';
            }
            $no = $r['member_no'] ?? '';
            if ($no !== '' && (isset($takenNos[$no]) || isset($seenNos[$no]))) {
                $errors[] = isset($takenNos[$no]) ? 'Member number already in use' : 'Member number repeated in file';
            }
            $branch = $defaultBranch;
            if (($r['branch_code'] ?? '') !== '' && !$own) {
                $branch = $branches[strtoupper($r['branch_code'])] ?? null;
                if (!$branch) {
                    $errors[] = "Unknown branch code {$r['branch_code']}";
                }
            }
            $sav = self::amount($r['opening_savings'] ?? '');
            $sh = self::amount($r['opening_shares'] ?? '');
            if ($sav === null || $sh === null) {
                $errors[] = 'Opening balances must be positive numbers';
            }
            $seenIds[$nid] = true;
            if ($no !== '') {
                $seenNos[$no] = true;
            }
            $data = [
                'member_no' => $no ?: null, 'branch_id' => $branch, 'first_name' => $r['first_name'] ?? '', 'last_name' => $r['last_name'] ?? '',
                'other_names' => ($r['other_names'] ?? '') ?: null, 'gender' => $gender === 'invalid' ? null : $gender,
                'date_of_birth' => $dob === 'invalid' ? null : $dob, 'national_id' => $nid, 'phone' => $r['phone'] ?? '',
                'email' => ($r['email'] ?? '') ?: null, 'address' => ($r['address'] ?? '') ?: null, 'occupation' => ($r['occupation'] ?? '') ?: null,
                'employer' => ($r['employer'] ?? '') ?: null, 'joined_at' => $joined === 'invalid' ? today() : $joined,
                'opening_savings' => (float) $sav, 'opening_shares' => (float) $sh,
            ];
            $staged[] = [$i + 2, $data, $errors];
        }
        return self::save('members', $filename, [], $staged, fn($d) => $d['opening_savings'] + $d['opening_shares']);
    }

    /** Validate and stage a payroll check-off file. */
    public static function stageCheckoff(string $path, string $filename, array $meta): int
    {
        [, $rows] = self::readCsv($path, 'checkoff');
        $nos = array_values(array_unique(array_filter(array_column($rows, 'member_no'))));
        $members = [];
        foreach (array_chunk($nos, 1000) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (DB::all("SELECT id, member_no, national_id, first_name, last_name, status, branch_id FROM members WHERE member_no IN ($in) OR national_id IN ($in)", [...$chunk, ...$chunk]) as $m) {
                $members[$m['member_no']] = $m;
                $members[$m['national_id']] = $m;
            }
        }
        $own = Auth::user()['branch_id'] ?? null;
        $staged = [];
        foreach ($rows as $i => $r) {
            $errors = [];
            $m = $members[$r['member_no'] ?? ''] ?? null;
            if (!$m) {
                $errors[] = 'Member not found';
            } elseif ($m['status'] === 'exited') {
                $errors[] = 'Member has exited';
            } elseif ($own && (int) $m['branch_id'] !== (int) $own) {
                $errors[] = 'Member belongs to another branch';
            }
            $sav = self::amount($r['savings'] ?? '');
            $sh = self::amount($r['shares'] ?? '');
            $loan = self::amount($r['loan'] ?? '');
            if ($sav === null || $sh === null || $loan === null) {
                $errors[] = 'Amounts must be positive numbers';
            } elseif ($sav + $sh + $loan <= 0) {
                $errors[] = 'No amount to post';
            }
            $loanId = null;
            if ($m && ($r['loan_no'] ?? '') !== '') {
                $loanId = DB::value("SELECT id FROM loans WHERE loan_no = ? AND member_id = ? AND status = 'active'", [$r['loan_no'], $m['id']]);
                if (!$loanId) {
                    $errors[] = "Loan {$r['loan_no']} is not an active loan of this member";
                }
            }
            $staged[] = [$i + 2, [
                'member_id' => $m['id'] ?? null, 'member_no' => $r['member_no'] ?? '', 'name' => $m ? "{$m['first_name']} {$m['last_name']}" : '',
                'savings' => (float) $sav, 'shares' => (float) $sh, 'loan' => (float) $loan, 'loan_id' => $loanId ? (int) $loanId : null,
            ], $errors];
        }
        return self::save('checkoff', $filename, $meta, $staged, fn($d) => $d['savings'] + $d['shares'] + $d['loan']);
    }

    private static function existing(string $col, array $values): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($values)), 1000) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (DB::all("SELECT $col FROM members WHERE $col IN ($in)", $chunk) as $r) {
                $out[$r[$col]] = true;
            }
        }
        return $out;
    }

    private static function save(string $kind, string $filename, array $meta, array $staged, callable $amountOf): int
    {
        return DB::transaction(function () use ($kind, $filename, $meta, $staged, $amountOf) {
            $valid = count(array_filter($staged, fn($s) => !$s[2]));
            $amount = array_sum(array_map(fn($s) => $s[2] ? 0 : $amountOf($s[1]), $staged));
            $id = DB::insert('imports', [
                'kind' => $kind, 'filename' => mb_substr($filename, 0, 190), 'meta' => json_encode($meta), 'total_rows' => count($staged),
                'valid_rows' => $valid, 'error_rows' => count($staged) - $valid, 'amount' => round($amount, 2), 'created_by' => Auth::id(),
            ]);
            foreach (array_chunk($staged, 500) as $chunk) {
                $values = [];
                $params = [];
                foreach ($chunk as [$rowNo, $data, $errors]) {
                    $values[] = '(?,?,?,?,?)';
                    array_push($params, $id, $rowNo, json_encode($data), $errors ? 'error' : 'valid', $errors ? mb_substr(implode('; ', $errors), 0, 255) : null);
                }
                DB::query('INSERT INTO import_rows (import_id, row_no, data, status, message) VALUES ' . implode(',', $values), $params);
            }
            return $id;
        });
    }

    /** Process the next chunk of valid rows. Returns the updated import. */
    public static function process(int $id): array
    {
        $imp = DB::row('SELECT * FROM imports WHERE id = ?', [$id]);
        if (!$imp) {
            throw new AppError('Import not found.', 404);
        }
        if (!in_array($imp['status'], ['validated', 'processing'], true)) {
            return $imp;
        }
        DB::update('imports', ['status' => 'processing'], 'id = ?', [$id]);
        $meta = json_decode((string) $imp['meta'], true) ?: [];
        $rows = DB::all("SELECT * FROM import_rows WHERE import_id = ? AND status = 'valid' ORDER BY id LIMIT " . self::CHUNK, [$id]);
        $done = $failed = 0;
        foreach ($rows as $row) {
            $d = json_decode($row['data'], true);
            try {
                $memberId = DB::transaction(fn() => $imp['kind'] === 'members' ? self::importMember($d) : self::postCheckoff($d, $meta));
                DB::query("UPDATE import_rows SET status = 'done', member_id = ?, message = NULL WHERE id = ?", [$memberId, $row['id']]);
                $done++;
            } catch (Throwable $e) {
                $msg = $e instanceof AppError ? $e->getMessage() : 'Unexpected error';
                if ($e instanceof ValidationError) {
                    $msg = implode('; ', $e->errors);
                }
                if (!$e instanceof AppError) {
                    error_log('[import] ' . $e);
                }
                DB::query("UPDATE import_rows SET status = 'failed', message = ? WHERE id = ?", [mb_substr($msg, 0, 255), $row['id']]);
                $failed++;
            }
        }
        $remaining = (int) DB::value("SELECT COUNT(*) FROM import_rows WHERE import_id = ? AND status = 'valid'", [$id]);
        DB::query(
            'UPDATE imports SET processed_rows = processed_rows + ?, failed_rows = failed_rows + ?, status = ?, completed_at = ? WHERE id = ?',
            [$done, $failed, $remaining ? 'processing' : 'completed', $remaining ? null : now(), $id]
        );
        return DB::row('SELECT * FROM imports WHERE id = ?', [$id]);
    }

    private static function importMember(array $d): int
    {
        $savings = (float) $d['opening_savings'];
        $shares = (float) $d['opening_shares'];
        unset($d['opening_savings'], $d['opening_shares']);
        $d['status'] = 'active';
        $id = MemberService::create($d);
        if ($savings > 0) {
            SavingsService::deposit((int) SavingsService::defaultAccountId($id), $savings, [
                'flow' => 'none', 'payment_method' => 'internal', 'narration' => 'Opening balance (migrated)', 'gl' => ['contra' => 'opening_equity'],
            ]);
        }
        if ($shares > 0) {
            ShareService::openingBalance($id, $shares);
        }
        return $id;
    }

    private static function postCheckoff(array $d, array $meta): int
    {
        $memberId = (int) $d['member_id'];
        $date = $meta['posting_date'] ?? today();
        $at = $date === today() ? now() : "$date 12:00:00";
        $ref = $meta['payment_ref'] ?? null;
        $note = trim('Check-off ' . ($meta['period'] ?? '') . ' — ' . ($meta['source'] ?? 'employer'));
        $toSavings = (float) $d['savings'];

        if ((float) $d['shares'] > 0) {
            $price = ShareService::price();
            $units = (int) floor((float) $d['shares'] / $price + 1e-9);
            if ($units > 0) {
                ShareService::purchase($memberId, $units, ['payment_method' => 'bank', 'payment_ref' => $ref, 'narration' => "$note (shares)", 'at' => $at]);
            }
            $toSavings += round((float) $d['shares'] - $units * $price, 2);
        }

        $loanLeft = (float) $d['loan'];
        if ($loanLeft > 0) {
            $loans = $d['loan_id']
                ? [['id' => $d['loan_id']]]
                : DB::all("SELECT id FROM loans WHERE member_id = ? AND status = 'active' ORDER BY id", [$memberId]);
            foreach ($loans as $l) {
                $bal = (float) DB::value('SELECT balance FROM loans WHERE id = ?', [$l['id']]);
                $pay = round(min($bal, $loanLeft), 2);
                if ($pay > 0) {
                    LoanService::repay((int) $l['id'], $pay, ['source' => 'cash', 'payment_method' => 'bank', 'payment_ref' => $ref, 'at' => $at]);
                    $loanLeft = round($loanLeft - $pay, 2);
                }
                if ($loanLeft <= 0) {
                    break;
                }
            }
            $toSavings += $loanLeft; // overpayment or no active loan: keep it for the member
        }

        if ($toSavings > 0) {
            $acc = SavingsService::defaultAccountId($memberId);
            if (!$acc) {
                throw new AppError('Member has no active savings account.');
            }
            SavingsService::deposit($acc, round($toSavings, 2), ['payment_method' => 'bank', 'payment_ref' => $ref, 'narration' => $note, 'at' => $at]);
        }
        return $memberId;
    }
}
