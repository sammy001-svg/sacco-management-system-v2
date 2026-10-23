<?php
defined('SACCO') or exit;

$ownBranch = Auth::user()['branch_id'] ?? null;
$branches = DB::all("SELECT id, name FROM branches WHERE status = 'active' ORDER BY id");
$savingsProducts = DB::all("SELECT id, name, min_balance, allow_withdrawal FROM savings_products WHERE status = 'active' ORDER BY is_default DESC, name");
$loanProducts = DB::all("SELECT * FROM loan_products WHERE status = 'active' ORDER BY name");
$cur = e(currency());

/** Reusable payment-method segmented control. */
$methods = function (string $name = 'payment_method', array $opts = ['cash' => ['Cash', 'cash'], 'mobile_money' => ['Mobile', 'phone'], 'bank' => ['Bank', 'bank'], 'cheque' => ['Cheque', 'card-text']], string $checked = 'cash') {
    $html = '<div class="segmented" role="radiogroup">';
    foreach ($opts as $val => [$text, $icon]) {
        $html .= '<label><input type="radio" name="' . e($name) . '" value="' . e($val) . '"' . ($val === $checked ? ' checked data-default-check' : '') . '><span><i class="bi bi-' . e($icon) . '"></i>' . e($text) . '</span></label>';
    }
    return $html . '</div>';
};
$head = fn(string $icon, string $title, string $sub, string $tone = '', ?string $edit = null) =>
    '<div class="modal-head"><div class="modal-icon ' . $tone . '"><i class="bi bi-' . $icon . '"></i></div>'
    . '<div class="modal-title"><h3 data-title data-create="' . e($title) . '"' . ($edit ? ' data-edit="' . e($edit) . '"' : '') . '>' . e($title) . '</h3><p>' . e($sub) . '</p></div>'
    . '<button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>';
?>

<?php if (Auth::can('members.manage')): ?>
<!-- ============ Member (wizard) ============ -->
<div class="modal modal-lg" id="memberModal" role="dialog" aria-modal="true" aria-hidden="true" data-static>
  <form class="modal-dialog wizard" data-api="members/save" novalidate>
    <?= $head('person-plus', 'Register new member', 'Capture KYC, contact and membership details.', '', 'Edit member details') ?>
    <div class="wizard-steps"></div>
    <div class="modal-body">
      <input type="hidden" name="id">
      <div class="wizard-pane" data-title="Personal">
        <div class="form-grid">
          <div class="field"><label>First name <span class="req">*</span></label><input class="input" name="first_name" required maxlength="80" placeholder="e.g. Grace"></div>
          <div class="field"><label>Last name <span class="req">*</span></label><input class="input" name="last_name" required maxlength="80" placeholder="e.g. Wanjiru"></div>
          <div class="field"><label>Other names</label><input class="input" name="other_names" maxlength="80"></div>
          <div class="field"><label>Gender</label><select class="select" name="gender"><option value="">Select…</option><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option></select></div>
          <div class="field"><label>National ID / Passport <span class="req">*</span></label><input class="input" name="national_id" required maxlength="40"></div>
          <div class="field"><label>Date of birth</label><input class="input" type="date" name="date_of_birth" max="<?= date('Y-m-d', strtotime('-16 years')) ?>"></div>
          <div class="field"><label>Tax PIN</label><input class="input" name="tax_pin" maxlength="30" placeholder="e.g. A012345678Z"></div>
          <div class="field"><label>Member number</label><input class="input" name="member_no" maxlength="30" placeholder="Auto-generated if blank"><div class="field-hint">Leave blank to auto-number.</div></div>
        </div>
      </div>
      <div class="wizard-pane" data-title="Contact &amp; work">
        <div class="form-grid">
          <div class="field"><label>Phone <span class="req">*</span></label><div class="input-icon"><i class="bi bi-telephone"></i><input class="input" name="phone" required placeholder="07XX XXX XXX" pattern="^\+?[0-9][0-9\s\-()]{6,19}$"></div></div>
          <div class="field"><label>Email</label><div class="input-icon"><i class="bi bi-envelope"></i><input class="input" type="email" name="email" placeholder="name@example.com"></div></div>
          <div class="field span-2"><label>Physical / postal address</label><input class="input" name="address" maxlength="255"></div>
          <div class="field"><label>Occupation</label><input class="input" name="occupation" maxlength="120"></div>
          <div class="field"><label>Employer / business</label><input class="input" name="employer" maxlength="160"></div>
          <div class="field"><label>Monthly income</label><div class="input-group"><span class="addon"><?= $cur ?></span><input class="input" type="number" name="monthly_income" min="0" step="0.01"></div></div>
        </div>
      </div>
      <div class="wizard-pane" data-title="Next of kin">
        <div class="form-grid">
          <div class="field span-2"><label>Full name</label><input class="input" name="next_of_kin_name" maxlength="160"></div>
          <div class="field"><label>Phone</label><input class="input" name="next_of_kin_phone" pattern="^\+?[0-9][0-9\s\-()]{6,19}$"></div>
          <div class="field"><label>Relationship</label><select class="select" name="next_of_kin_relationship"><option value="">Select…</option><?php foreach (['Spouse', 'Parent', 'Child', 'Sibling', 'Relative', 'Friend', 'Other'] as $r): ?><option><?= $r ?></option><?php endforeach; ?></select></div>
        </div>
      </div>
      <div class="wizard-pane" data-title="Membership">
        <div class="form-grid">
          <?php if ($ownBranch): ?>
            <input type="hidden" name="branch_id" value="<?= (int) $ownBranch ?>" data-keep>
          <?php else: ?>
            <div class="field"><label>Branch <span class="req">*</span></label><select class="select" name="branch_id" required><?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
          <?php endif; ?>
          <div class="field"><label>Date joined <span class="req">*</span></label><input class="input" type="date" name="joined_at" required data-default="today" max="<?= today() ?>"></div>
          <div class="field"><label>Status</label><select class="select" name="status"><?php foreach (MemberService::STATUSES as $s): ?><option value="<?= $s ?>" <?= $s === 'active' ? 'selected' : '' ?>><?= label($s) ?></option><?php endforeach; ?></select></div>
        </div>
        <div data-mode-show="create">
          <div class="form-section">Registration fee</div>
          <label class="switch"><input type="checkbox" name="pay_registration" value="1" checked><span class="track"></span>Registration fee paid now</label>
          <div class="form-grid" data-show-when="pay_registration:1">
            <div class="field"><label>Amount</label><div class="input-group"><span class="addon"><?= $cur ?></span><input class="input" type="number" name="registration_amount" min="0" step="0.01" data-default="<?= e((string) (float) setting('registration_fee', 0)) ?>" value="<?= e((string) (float) setting('registration_fee', 0)) ?>"></div></div>
            <div class="field"><label>Reference</label><input class="input" name="payment_ref" maxlength="80" placeholder="M-Pesa / receipt no."></div>
            <div class="field span-2"><label>Paid via</label><?= $methods() ?></div>
          </div>
          <div class="info-strip"><i class="bi bi-stars"></i><span>A default savings account and a share account are opened automatically.</span></div>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost left" data-wizard-prev hidden><i class="bi bi-arrow-left"></i><span>Back</span></button>
      <button type="button" class="btn btn-ghost" data-close>Cancel</button>
      <button type="submit" class="btn btn-primary" data-submit-label="Save member"><span>Continue</span><i class="bi bi-arrow-right"></i></button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if (Auth::can('savings.transact')): ?>
<!-- ============ Deposit ============ -->
<div class="modal" id="depositModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="savings/deposit" novalidate>
    <?= $head('arrow-down-left-circle', 'Receive deposit', 'Credit cash, mobile money or bank funds to a savings account.') ?>
    <div class="modal-body">
      <div class="field"><label>Member <span class="req">*</span></label><div class="picker" data-name="member_id" data-required data-active-only></div></div>
      <div class="field"><label>Savings account <span class="req">*</span></label><select class="select" name="account_id" required data-accounts><option value="">Select a member first</option></select><div class="field-hint" data-account-hint></div></div>
      <div class="field"><label>Amount <span class="req">*</span></label><div class="input-group"><span class="addon"><?= $cur ?></span><input class="input input-amount" type="number" name="amount" min="1" step="0.01" required placeholder="0.00"></div></div>
      <div class="field"><label>Payment method</label><?= $methods() ?></div>
      <div class="form-grid">
        <div class="field"><label>Reference</label><input class="input" name="payment_ref" maxlength="80" placeholder="M-Pesa code / slip no."></div>
        <div class="field"><label>Narration</label><input class="input" name="narration" maxlength="255" placeholder="Optional note"></div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i><span>Receive deposit</span></button></div>
  </form>
</div>

<!-- ============ Withdrawal ============ -->
<div class="modal" id="withdrawModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="savings/withdraw" novalidate>
    <?= $head('arrow-up-right-circle', 'Withdraw savings', 'Pay out funds from a withdrawable savings account.', 'warning') ?>
    <div class="modal-body">
      <div class="field"><label>Member <span class="req">*</span></label><div class="picker" data-name="member_id" data-required data-active-only></div></div>
      <div class="id-check" data-id-check hidden></div>
      <div class="field"><label>Savings account <span class="req">*</span></label><select class="select" name="account_id" required data-accounts="withdrawable"><option value="">Select a member first</option></select><div class="field-hint" data-account-hint></div></div>
      <div class="field"><label>Amount <span class="req">*</span></label><div class="input-group"><span class="addon"><?= $cur ?></span><input class="input input-amount" type="number" name="amount" min="1" step="0.01" required placeholder="0.00"></div></div>
      <div class="field"><label>Pay out via</label><?= $methods() ?></div>
      <div class="form-grid">
        <div class="field"><label>Reference</label><input class="input" name="payment_ref" maxlength="80"></div>
        <div class="field"><label>Narration</label><input class="input" name="narration" maxlength="255"></div>
      </div>
      <?php if ((string) setting('require_id_check', '1') === '1'): ?>
      <label class="check verify-check"><input type="checkbox" name="id_verified" value="1" required>
        <span>I have checked the member's ID and signature against the records above.</span></label>
      <div class="field-error" data-error-for="id_verified"></div>
      <?php endif; ?>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-warning"><i class="bi bi-box-arrow-up-right"></i><span>Process withdrawal</span></button></div>
  </form>
</div>

<!-- ============ Open savings account ============ -->
<div class="modal modal-sm" id="openAccountModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="savings/open" novalidate>
    <?= $head('wallet2', 'Open savings account', 'Add another savings product for a member.') ?>
    <div class="modal-body">
      <div class="field"><label>Member <span class="req">*</span></label><div class="picker" data-name="member_id" data-required data-active-only></div></div>
      <div class="field"><label>Product <span class="req">*</span></label><select class="select" name="product_id" required><?php foreach ($savingsProducts as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?><?= $p['allow_withdrawal'] ? '' : ' (locked)' ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><span>Open account</span></button></div>
  </form>
</div>
<?php endif; ?>

<?php if (Auth::can('shares.transact')): ?>
<!-- ============ Buy shares ============ -->
<div class="modal" id="sharesModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="shares/buy" novalidate>
    <?= $head('pie-chart', 'Buy shares', 'Grow a member\'s share capital at ' . money(ShareService::price()) . ' per share.') ?>
    <div class="modal-body">
      <div class="field"><label>Member <span class="req">*</span></label><div class="picker" data-name="member_id" data-required data-active-only></div></div>
      <div class="field"><label>Number of shares <span class="req">*</span></label><input class="input input-amount" type="number" name="shares" min="1" step="1" required placeholder="0"></div>
      <div class="summary-box" data-shares-summary>
        <div class="summary-row"><span>Price per share</span><strong><?= e(money(ShareService::price())) ?></strong></div>
        <div class="summary-row"><span>Current holding</span><strong data-holding>—</strong></div>
        <div class="summary-row total"><span>Total cost</span><strong data-total>—</strong></div>
      </div>
      <div class="field"><label>Pay from</label><?= $methods('source', ['cash' => ['Cash / Mobile / Bank', 'cash-stack'], 'savings' => ['Member savings', 'piggy-bank']]) ?></div>
      <div data-show-when="source:cash">
        <div class="field"><label>Payment method</label><?= $methods() ?></div>
      </div>
      <div class="field" data-show-when="source:savings"><label>Savings account <span class="req">*</span></label><select class="select" name="savings_account_id" required data-accounts="withdrawable"><option value="">Select a member first</option></select><div class="field-hint" data-account-hint></div></div>
      <div class="field"><label>Reference</label><input class="input" name="payment_ref" maxlength="80"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i><span>Buy shares</span></button></div>
  </form>
</div>

<!-- ============ Transfer shares ============ -->
<div class="modal" id="transferSharesModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="shares/transfer" novalidate>
    <?= $head('arrow-left-right', 'Transfer shares', 'Move shares between two members at book value.', 'navy') ?>
    <div class="modal-body">
      <div class="field"><label>From member <span class="req">*</span></label><div class="picker" id="shareFromPicker" data-name="from_member_id" data-required></div><div class="field-hint" data-from-holding></div></div>
      <div class="field"><label>To member <span class="req">*</span></label><div class="picker" data-name="to_member_id" data-required data-active-only data-exclude-from="#shareFromPicker"></div></div>
      <div class="field"><label>Shares to transfer <span class="req">*</span></label><input class="input input-amount" type="number" name="shares" min="1" step="1" required></div>
      <div class="field"><label>Reason / note</label><input class="input" name="narration" maxlength="200" placeholder="e.g. Member exit, family transfer"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-navy"><span>Transfer shares</span></button></div>
  </form>
</div>
<?php endif; ?>

<?php if (Auth::can('loans.apply')): ?>
<!-- ============ Loan application ============ -->
<div class="modal modal-xl" id="loanApplyModal" role="dialog" aria-modal="true" aria-hidden="true" data-static>
  <form class="modal-dialog" data-api="loans/apply" novalidate>
    <?= $head('file-earmark-plus', 'New loan application', 'Live eligibility check, repayment schedule and guarantor validation.') ?>
    <div class="modal-body">
      <div class="apply-grid">
        <div>
          <div class="field"><label>Member <span class="req">*</span></label><div class="picker" id="loanMemberPicker" data-name="member_id" data-required data-active-only></div></div>
          <div class="info-strip" data-eligibility hidden></div>
          <div class="form-grid">
            <div class="field span-2"><label>Loan product <span class="req">*</span></label>
              <select class="select" name="product_id" required>
                <option value="">Select a product…</option>
                <?php foreach ($loanProducts as $p): ?>
                  <option value="<?= (int) $p['id'] ?>" data-min="<?= e($p['min_amount']) ?>" data-max="<?= e($p['max_amount']) ?>" data-min-term="<?= (int) $p['min_term'] ?>" data-max-term="<?= (int) $p['max_term'] ?>" data-guarantors="<?= (int) $p['min_guarantors'] ?>"
                    data-desc="<?= e(rtrim(rtrim($p['interest_rate'], '0'), '.') . '% ' . ($p['rate_period'] === 'monthly' ? 'per month' : 'p.a.') . ' · ' . label($p['interest_method']) . ' balance · ' . (float) $p['processing_fee_rate'] . '% fee' . ((float) $p['savings_multiplier'] > 0 ? ' · up to ' . (float) $p['savings_multiplier'] . '× savings' : '')) ?>"><?= e($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="field-hint" data-product-desc></div>
            </div>
            <div class="field"><label>Amount <span class="req">*</span></label><div class="input-group"><span class="addon"><?= $cur ?></span><input class="input input-amount" type="number" name="amount" min="1" step="0.01" required></div><div class="field-hint" data-amount-hint></div></div>
            <div class="field"><label>Term (months) <span class="req">*</span></label><input class="input input-amount" type="number" name="term_months" min="1" step="1" required><div class="field-hint" data-term-hint></div></div>
            <div class="field span-2"><label>Purpose <span class="req">*</span></label><input class="input" name="purpose" required maxlength="255" placeholder="e.g. School fees, business stock, farm inputs"></div>
          </div>
          <div class="form-section">Guarantors <span class="badge no-dot" data-guarantor-req>Optional</span></div>
          <div data-guarantors></div>
          <div class="field-error mb-1" data-error-for="guarantors"></div>
          <button type="button" class="btn btn-soft btn-sm" data-add-guarantor><i class="bi bi-person-plus"></i><span>Add guarantor</span></button>
        </div>
        <div>
          <div class="hero-quote">
            <div class="lbl">Estimated monthly installment</div>
            <div class="big" data-q="installment"><?= $cur ?> 0.00</div>
            <div class="cols">
              <div>Total interest<strong data-q="total_interest">—</strong></div>
              <div>Total repayable<strong data-q="total_payable">—</strong></div>
              <div>Processing fee<strong data-q="processing_fee">—</strong></div>
              <div>Net disbursement<strong data-q="net_disbursement">—</strong></div>
            </div>
          </div>
          <div class="card" style="box-shadow:none">
            <div class="card-head"><h3 class="text-sm">Repayment schedule preview</h3><span class="muted text-xs" data-q="maturity"></span></div>
            <div class="card-body flush"><div class="table-wrap" style="max-height:280px;overflow-y:auto">
              <table class="table compact"><thead><tr><th>#</th><th>Due</th><th class="num">Principal</th><th class="num">Interest</th><th class="num">Installment</th></tr></thead>
              <tbody data-schedule><tr><td colspan="5"><div class="empty" style="padding:24px"><div class="empty-icon"><i class="bi bi-calculator"></i></div>Choose a product, amount and term to preview.</div></td></tr></tbody></table>
            </div></div>
          </div>
        </div>
      </div>
    </div>
    <div class="modal-foot"><span class="left muted text-sm"><i class="bi bi-info-circle"></i> The final schedule is generated at disbursement.</span><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-send"></i><span>Submit application</span></button></div>
  </form>
</div>
<?php endif; ?>

<?php if (Auth::can('loans.view')): ?>
<!-- ============ Loan details ============ -->
<div class="modal modal-xl" id="loanViewModal" role="dialog" aria-modal="true" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-head"><div class="modal-icon navy"><i class="bi bi-cash-stack"></i></div>
      <div class="modal-title"><h3 data-loan-title>Loan details</h3><p data-loan-sub></p></div>
      <button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body" data-loan-body></div>
    <div class="modal-foot" data-loan-actions></div>
  </div>
</div>
<?php endif; ?>

<?php if (Auth::can('loans.approve')): ?>
<div class="modal modal-sm" id="loanApproveModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="loans/approve" novalidate>
    <?= $head('patch-check', 'Approve loan', 'Confirm the credit decision for this application.') ?>
    <div class="modal-body">
      <input type="hidden" name="loan_id">
      <div class="summary-box" data-loan-summary></div>
      <div class="field"><label>Approval notes</label><textarea class="textarea" name="notes" rows="3" maxlength="255" placeholder="e.g. Approved by credit committee, minute 12/2026"></textarea></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i><span>Approve loan</span></button></div>
  </form>
</div>
<div class="modal modal-sm" id="loanRejectModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="loans/reject" novalidate>
    <?= $head('x-octagon', 'Reject application', 'The member and guarantors will be released.', 'danger') ?>
    <div class="modal-body">
      <input type="hidden" name="loan_id">
      <div class="summary-box" data-loan-summary></div>
      <div class="field"><label>Reason <span class="req">*</span></label><textarea class="textarea" name="reason" rows="3" required minlength="3" maxlength="255"></textarea></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-danger"><span>Reject loan</span></button></div>
  </form>
</div>
<?php endif; ?>

<?php if (Auth::can('loans.disburse')): ?>
<div class="modal" id="loanDisburseModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="loans/disburse" novalidate>
    <?= $head('send-check', 'Disburse loan', 'Release funds and generate the repayment schedule.') ?>
    <div class="modal-body">
      <input type="hidden" name="loan_id">
      <div class="summary-box" data-loan-summary></div>
      <div class="field"><label>Disburse to</label><?= $methods('method', ['savings' => ['Savings', 'piggy-bank'], 'mobile_money' => ['Mobile', 'phone'], 'bank' => ['Bank', 'bank'], 'cash' => ['Cash', 'cash']], 'savings') ?></div>
      <div class="form-grid">
        <div class="field"><label>Disbursement date <span class="req">*</span></label><input class="input" type="date" name="date" required data-default="today" max="<?= today() ?>"></div>
        <div class="field"><label>Reference</label><input class="input" name="payment_ref" maxlength="80" placeholder="Transfer / M-Pesa ref"></div>
      </div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-send"></i><span>Disburse funds</span></button></div>
  </form>
</div>
<?php endif; ?>

<?php if (Auth::can('loans.repay')): ?>
<div class="modal" id="repayModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="loans/repay" novalidate>
    <?= $head('cash-coin', 'Record loan repayment', 'Allocated to penalties, interest then principal — oldest installment first.') ?>
    <div class="modal-body">
      <div class="field" data-repay-member><label>Member <span class="req">*</span></label><div class="picker" data-name="member_id" data-required></div></div>
      <div class="field"><label>Loan <span class="req">*</span></label><select class="select" name="loan_id" required data-loans><option value="">Select a member first</option></select></div>
      <div class="summary-box" data-repay-summary hidden></div>
      <div class="field"><label>Amount <span class="req">*</span></label><div class="input-group"><span class="addon"><?= $cur ?></span><input class="input input-amount" type="number" name="amount" min="1" step="0.01" required></div><div class="field-hint" data-repay-hint></div></div>
      <div class="field"><label>Paid from</label><?= $methods('source', ['cash' => ['Cash / Mobile / Bank', 'cash-stack'], 'savings' => ['Member savings', 'piggy-bank']]) ?></div>
      <div data-show-when="source:cash"><div class="field"><label>Payment method</label><?= $methods() ?></div></div>
      <div class="field" data-show-when="source:savings"><label>Savings account <span class="req">*</span></label><select class="select" name="savings_account_id" required data-accounts="withdrawable"><option value="">Select a member first</option></select><div class="field-hint" data-account-hint></div></div>
      <div class="field"><label>Reference</label><input class="input" name="payment_ref" maxlength="80" placeholder="M-Pesa code / receipt no."></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle"></i><span>Post repayment</span></button></div>
  </form>
</div>
<?php endif; ?>

<!-- ============ My profile ============ -->
<div class="modal modal-sm" id="profileModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="account/profile" novalidate>
    <?= $head('person-circle', 'My profile', 'Update your details or change your password.') ?>
    <div class="modal-body">
      <div class="field"><label>Full name <span class="req">*</span></label><input class="input" name="name" required maxlength="120"></div>
      <div class="field"><label>Phone</label><input class="input" name="phone" maxlength="30"></div>
      <div class="form-section">Change password</div>
      <div class="field"><label>Current password</label><div class="input-icon"><i class="bi bi-lock"></i><input class="input" type="password" name="current_password" autocomplete="current-password"><button type="button" class="input-action" data-toggle-password><i class="bi bi-eye"></i></button></div></div>
      <div class="field"><label>New password</label><div class="input-icon"><i class="bi bi-key"></i><input class="input" type="password" name="new_password" minlength="8" autocomplete="new-password"><button type="button" class="input-action" data-toggle-password><i class="bi bi-eye"></i></button></div><div class="field-hint">At least 8 characters with letters and numbers.</div></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><span>Save profile</span></button></div>
  </form>
</div>

<!-- ============ Two-step login ============ -->
<div class="modal modal-sm" id="securityModal" role="dialog" aria-modal="true" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-head"><div class="modal-icon"><i class="bi bi-shield-lock"></i></div><div class="modal-title"><h3>Two-step login</h3><p>A code from your phone is needed on top of your password.</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body" data-sec-body></div>
    <div class="modal-foot" data-sec-actions></div>
  </div>
</div>
