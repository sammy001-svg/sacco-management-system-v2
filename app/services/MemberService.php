<?php
defined('SACCO') or exit;

final class MemberService
{
    public const STATUSES = ['pending', 'active', 'dormant', 'suspended', 'exited'];

    /**
     * Register a member: creates the member, their default savings account,
     * their share account and (optionally) posts the registration fee.
     */
    public static function create(array $data, array $opts = []): int
    {
        return DB::transaction(function () use ($data, $opts) {
            if (empty($data['member_no'])) {
                $data['member_no'] = self::nextNumber();
            }
            $data['created_by'] = Auth::id();
            if (!empty($opts['at'])) {
                $data['created_at'] = $opts['at'];
            }
            $id = DB::insert('members', $data);

            $productId = (int) DB::value("SELECT id FROM savings_products WHERE is_default = 1 AND status = 'active' ORDER BY id LIMIT 1")
                ?: (int) DB::value('SELECT id FROM savings_products ORDER BY id LIMIT 1');
            if ($productId) {
                SavingsService::openAccount($id, $productId, $data['joined_at'] ?? today());
            }
            ShareService::ensureAccount($id);

            $fee = (float) ($opts['registration_fee'] ?? 0);
            if ($fee > 0) {
                Ledger::post([
                    'branch_id'      => $data['branch_id'],
                    'member_id'      => $id,
                    'account_type'   => 'income',
                    'type'           => 'registration_fee',
                    'amount'         => $fee,
                    'flow'           => 'in',
                    'payment_method' => $opts['payment_method'] ?? 'cash',
                    'payment_ref'    => $opts['payment_ref'] ?? null,
                    'narration'      => 'Membership registration fee',
                    'at'             => $opts['at'] ?? null,
                ]);
            }
            return $id;
        });
    }

    public static function nextNumber(): string
    {
        $prefix = (string) setting('member_no_prefix', 'MBR');
        do {
            $no = $prefix . str_pad((string) next_seq('member'), 6, '0', STR_PAD_LEFT);
        } while (DB::value('SELECT 1 FROM members WHERE member_no = ?', [$no]));
        return $no;
    }

    public static function find(int $id): ?array
    {
        return DB::row(
            'SELECT m.*, b.name AS branch_name FROM members m JOIN branches b ON b.id = m.branch_id WHERE m.id = ?',
            [$id]
        );
    }

    /** True if the member is visible to the current user's branch scope. */
    public static function inScope(array $member): bool
    {
        $own = Auth::user()['branch_id'] ?? null;
        return !$own || (int) $own === (int) $member['branch_id'];
    }

    public static function findOrFail(int $id): array
    {
        $m = self::find($id);
        if (!$m || !self::inScope($m)) {
            throw new AppError('Member not found.', 404);
        }
        return $m;
    }
}
