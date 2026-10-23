<?php
defined('SACCO') or exit;

/**
 * Teller cash drawers.
 *
 * Expected cash = opening float + cash received − cash paid out, computed
 * from the ledger rows the teller posted with payment method "cash" while the
 * till was open. On close the teller counts the drawer; any difference is
 * posted to Cash Over / Short so the books match the physical cash.
 */
final class TillService
{
    public const DENOMINATIONS = [1000, 500, 200, 100, 50, 40, 20, 10, 5, 1];

    public static function required(): bool
    {
        return !system_process() && (string) setting('require_till', '1') === '1';
    }

    public static function openFor(int $userId): ?array
    {
        return DB::row("SELECT * FROM till_sessions WHERE user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1", [$userId]);
    }

    /** Called by the ledger before any cash movement is posted. */
    public static function assertOpen(?int $userId): void
    {
        if (!self::required() || !$userId) {
            return;
        }
        if (!self::openFor($userId)) {
            throw new AppError('Open your till before taking or paying out cash (Operations → Teller tills).', 409);
        }
    }

    public static function open(int $userId, float $float): array
    {
        return DB::transaction(function () use ($userId, $float) {
            DB::query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if (self::openFor($userId)) {
                throw new AppError('You already have an open till.');
            }
            if ($float < 0) {
                throw new ValidationError(['opening_float' => 'The float cannot be negative.']);
            }
            $branch = DB::value('SELECT branch_id FROM users WHERE id = ?', [$userId]);
            $id = DB::insert('till_sessions', [
                'user_id' => $userId, 'branch_id' => $branch, 'opened_at' => now(), 'opening_float' => round($float, 2), 'status' => 'open',
            ]);
            return self::summary($id);
        });
    }

    /** Live position of a till session. */
    public static function summary(int $id): array
    {
        $s = DB::row('SELECT t.*, u.name AS user_name, b.name AS branch_name FROM till_sessions t JOIN users u ON u.id = t.user_id LEFT JOIN branches b ON b.id = t.branch_id WHERE t.id = ?', [$id]);
        if (!$s) {
            throw new AppError('Till session not found.', 404);
        }
        $m = DB::row(
            "SELECT COALESCE(SUM(CASE WHEN flow = 'in' THEN amount END), 0) AS cash_in,
                    COALESCE(SUM(CASE WHEN flow = 'out' THEN amount END), 0) AS cash_out,
                    SUM(flow = 'in') AS n_in, SUM(flow = 'out') AS n_out
             FROM transactions WHERE created_by = ? AND payment_method = 'cash' AND created_at >= ? AND created_at <= ?",
            [$s['user_id'], $s['opened_at'], $s['closed_at'] ?? now()]
        );
        $s['cash_in'] = (float) $m['cash_in'];
        $s['cash_out'] = (float) $m['cash_out'];
        $s['count_in'] = (int) $m['n_in'];
        $s['count_out'] = (int) $m['n_out'];
        $s['expected_now'] = round((float) $s['opening_float'] + $s['cash_in'] - $s['cash_out'], 2);
        $s['denominations'] = $s['denominations'] ? json_decode($s['denominations'], true) : null;
        return $s;
    }

    /**
     * Close with a physical count. $denoms = [note => quantity]; if empty the
     * $counted total is used as entered.
     */
    public static function close(int $id, float $counted, array $denoms = [], ?string $notes = null): array
    {
        return DB::transaction(function () use ($id, $counted, $denoms, $notes) {
            $s = DB::row('SELECT * FROM till_sessions WHERE id = ? FOR UPDATE', [$id]);
            if (!$s || $s['status'] !== 'open') {
                throw new AppError('This till is not open.');
            }
            $clean = [];
            foreach ($denoms as $note => $qty) {
                if (in_array((int) $note, self::DENOMINATIONS, true) && (int) $qty > 0) {
                    $clean[(int) $note] = (int) $qty;
                }
            }
            if ($clean) {
                $counted = array_sum(array_map(fn($n, $q) => $n * $q, array_keys($clean), $clean));
            }
            $counted = round($counted, 2);
            $sum = self::summary($id);
            $expected = $sum['expected_now'];
            $variance = round($counted - $expected, 2);

            $entry = null;
            if ($variance != 0.0) {
                $cash = Accounting::account('cash');
                $over = Accounting::account('cash_variance');
                $entry = Accounting::post([
                    'date' => today(), 'source' => 'manual', 'branch_id' => $s['branch_id'],
                    'reference' => 'TILL' . $id,
                    'narration' => ($variance < 0 ? 'Till shortage' : 'Till overage') . " — {$sum['user_name']}, session #$id",
                ], $variance < 0
                    ? [[$over, -$variance, 0], [$cash, 0, -$variance]]
                    : [[$cash, $variance, 0], [$over, 0, $variance]]);
            }
            DB::update('till_sessions', [
                'status' => 'closed', 'closed_at' => now(), 'closed_by' => Auth::id(),
                'expected_cash' => $expected, 'counted_cash' => $counted, 'variance' => $variance,
                'denominations' => $clean ? json_encode($clean) : null, 'notes' => $notes, 'variance_entry_id' => $entry,
            ], 'id = ?', [$id]);
            return self::summary($id);
        });
    }
}
