<?php
defined('SACCO') or exit;
/** Teller cash requests shared by the counter and the tills page. Expects the form helpers below. */
if (!Auth::can('tills.use')) {
    return;
}
$denomFields = function (): string {
    $h = '<div class="denoms mb-2">';
    foreach (TillService::DENOMINATIONS as $d) {
        $h .= '<label class="denom"><span>' . number_format($d) . ' ×</span><input class="input input-sm" type="number" min="0" step="1" name="denoms[' . $d . ']" data-denom="' . $d . '" placeholder="0"></label>';
    }
    return $h . '</div>';
};
$modalHead = fn(string $icon, string $title, string $sub, string $tone = '') => '<div class="modal-head"><div class="modal-icon ' . $tone . '"><i class="bi bi-' . $icon . '"></i></div><div class="modal-title"><h3>' . $title . '</h3><p>' . $sub . '</p></div><button type="button" class="modal-close" data-close aria-label="Close"><i class="bi bi-x-lg"></i></button></div>';
$amountField = fn(string $label, string $name = 'amount', string $min = '1') => '<div class="field"><label>' . $label . ' <span class="req">*</span></label><div class="input-group"><span class="addon">' . e(currency()) . '</span><input class="input input-amount" type="number" name="' . $name . '" min="' . $min . '" step="0.01" required placeholder="0.00"></div></div>';
$foot = fn(string $btn, string $icon, string $tone = 'primary') => '<div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-' . $tone . '"><i class="bi bi-' . $icon . '"></i><span>' . $btn . '</span></button></div>';
?>
<!-- Teller requests -->
<div class="modal modal-sm" id="floatModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="tills/request_float" novalidate>
    <?= $modalHead('safe2', 'Request opening float', 'A supervisor releases the cash from the vault and your till opens.') ?>
    <div class="modal-body">
      <?= $amountField('Float', 'amount', '0') ?>
      <div class="field-hint mb-2" data-vault-hint></div>
      <div class="field"><label>Note</label><input class="input" name="note" maxlength="255" placeholder="Optional"></div>
    </div>
    <?= $foot('Request float', 'send') ?>
  </form>
</div>

<div class="modal modal-sm" id="topupModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="tills/request_topup" novalidate>
    <?= $modalHead('plus-circle', 'Request a top-up', 'More cash from the vault for withdrawals.') ?>
    <div class="modal-body">
      <?= $amountField('Amount') ?>
      <div class="field"><label>Reason</label><input class="input" name="note" maxlength="255" placeholder="e.g. large withdrawals queued"></div>
    </div>
    <?= $foot('Request top-up', 'send') ?>
  </form>
</div>

<div class="modal modal-lg" id="dropModal" role="dialog" aria-modal="true" aria-hidden="true">
  <form class="modal-dialog" data-api="tills/drop" novalidate>
    <?= $modalHead('box-arrow-in-down', 'Cash drop to vault', 'Move excess cash from your drawer to the vault. Count it with the custodian.') ?>
    <div class="modal-body">
      <div class="form-section">Notes handed over</div>
      <?= $denomFields() ?>
      <?= str_replace('name="amount"', 'name="amount" data-denom-total', $amountField('Amount dropped')) ?>
      <div class="field"><label>Note</label><input class="input" name="note" maxlength="255" placeholder="Optional"></div>
    </div>
    <?= $foot('Record drop', 'box-arrow-in-down') ?>
  </form>
</div>

