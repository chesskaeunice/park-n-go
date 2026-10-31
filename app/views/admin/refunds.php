<?php
require_once BASE_PATH . '/app/views/partials/admin.php';
use ParkNGo\Services\Admin\RefundService;
$data = $pageData; $params = array_filter(['facility_id' => $data['facilityId'] ?: null, 'status' => $data['status'] === 'open' ? null : $data['status']]);
$approver = $data['permissions']['approve_refunds']; $lookup = $data['lookup'];
?>
<section class="page-head"><span class="eyebrow">Financial control</span><h1 class="h1">Refund requests</h1><p class="small muted">Refunds are requested against a provider-verified payment, decided by an administrator with refund approval, submitted in Maya Manager, and counted as complete only when Maya confirms them.</p></section>
<?= admin_errors($data['errors']) ?>
<div class="status-note"><?= ui_icon('info', 20) ?><span>Park n Go does not submit refunds to Maya automatically. Approval reserves the amount; it is not proof that money was returned. No-shows, early exits, and late payments are never refunded automatically.</span></div>
<section class="content-card"><h2 class="h4">Request a refund</h2>
<form class="filter-bar" action="index.php" method="get"><input type="hidden" name="page" value="admin-refunds"><label><span>Payment reference</span><input name="payment" value="<?= e($data['paymentReference']) ?>" placeholder="PNGO-…" maxlength="29" autocomplete="off"></label><button class="button secondary" type="submit">Find payment</button></form>
<?php if ($lookup): ?>
<dl class="kv"><div><dt>Payment</dt><dd><?= e($lookup['reference']) ?> · <?= e(admin_type($lookup['payment_type'])) ?></dd></div><div><dt>Facility</dt><dd><?= e($lookup['facility_name']) ?></dd></div>
<div><dt>Verified amount</dt><dd><?= e(ui_peso((int) $lookup['amount_centavos'])) ?></dd></div><div><dt>Local status</dt><dd><?= ui_status($lookup['local_status']) ?></dd></div>
<div><dt>Remaining refundable</dt><dd><?= e(ui_peso((int) $lookup['refundable_centavos'])) ?></dd></div></dl>
<?php if ($lookup['refundable_centavos'] > 0): ?>
<form class="account-form" action="<?= e(url('admin-refunds', $params)) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="request"><input type="hidden" name="payment" value="<?= e($lookup['reference']) ?>"><input type="hidden" name="request_key" value="<?= e($data['requestKey']) ?>">
<label for="refund-amount">Amount (₱)</label><input id="refund-amount" name="amount" inputmode="decimal" required pattern="\d{1,7}(\.\d{1,2})?" value="<?= e(number_format($lookup['refundable_centavos'] / 100, 2, '.', '')) ?>">
<label for="refund-reason">Reason</label><textarea id="refund-reason" name="reason" required minlength="10" maxlength="1000"></textarea>
<button class="button primary block" type="submit">Submit refund request</button></form>
<?php else: ?><p class="small">This payment has no refundable balance left.</p><?php endif; ?>
<?php endif; ?></section>
<form class="filter-bar" action="index.php" method="get"><input type="hidden" name="page" value="admin-refunds"><?= admin_facility_select($data['facilities'], $data['facilityId']) ?>
<label><span>Status</span><select name="status"><option value="open">Open</option><option value="all"<?= $data['status'] === 'all' ? ' selected' : '' ?>>All</option><?php foreach (RefundService::LABELS as $value => $label): ?><option value="<?= e($value) ?>"<?= $data['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><button class="button secondary" type="submit">Apply</button></form>
<section class="content-card"><h2 class="h4"><?= number_format($data['list']['total']) ?> refund request<?= $data['list']['total'] === 1 ? '' : 's' ?></h2>
<div class="table-scroll"><table><thead><tr><th scope="col">Refund</th><th scope="col" class="num">Amount</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>
<?php foreach ($data['list']['rows'] as $refund): $form = '<form class="inline-form" action="' . e(url('admin-refunds', $params)) . '" method="post">' . csrf_field() . '<input type="hidden" name="refund_id" value="' . (int) $refund['id'] . '">'; ?>
<tr><td><b><?= e($refund['reference']) ?></b><br><small>Payment <?= e($refund['payment_reference']) ?> · <?= e(admin_type($refund['payment_type'])) ?> · <?= e($refund['facility_name']) ?><br>Requested by <?= e($refund['requested_by_name']) ?> <?= e(admin_time($refund['created_at'])) ?><br><?= e($refund['request_reason']) ?>
<?php if ($refund['decided_by_name']): ?><br>Decided by <?= e($refund['decided_by_name']) ?>: <?= e((string) $refund['decision_reason']) ?><?php endif; ?>
<?php if ($refund['provider_refund_id']): ?><br>Maya refund <?= e($refund['provider_refund_id']) ?><?= $refund['provider_status'] ? ' · ' . e($refund['provider_status']) : '' ?><?php endif; ?></small></td>
<td class="num"><?= e(ui_peso((int) $refund['amount_centavos'])) ?><br><small>of <?= e(ui_peso((int) $refund['payment_centavos'])) ?></small></td>
<td><span class="status-pill <?= $refund['status'] === 'provider_confirmed' ? 'ok' : (in_array($refund['status'], ['rejected', 'provider_failed'], true) ? 'off' : 'warn') ?>"><?= e(RefundService::LABELS[$refund['status']]) ?></span></td>
<td><?php if (!$approver): ?><small>Refund approval permission required.</small>
<?php elseif ($refund['status'] === 'requested' || $refund['status'] === 'under_review'): ?>
<?php if ($refund['status'] === 'requested'): ?><?= $form ?><input type="hidden" name="action" value="review"><button class="pill-button" type="submit">Start review</button></form><?php endif; ?>
<?= $form ?><input name="reason" required minlength="10" maxlength="1000" placeholder="Decision reason" aria-label="Decision reason for <?= e($refund['reference']) ?>"><select name="action" aria-label="Decision for <?= e($refund['reference']) ?>"><option value="approve">Approve</option><option value="reject">Reject</option></select><button class="pill-button" type="submit">Decide</button></form>
<?php elseif ($refund['status'] === 'approved'): ?><?= $form ?><input type="hidden" name="action" value="provider"><input name="provider_refund_id" required maxlength="191" placeholder="Maya refund ID" aria-label="Maya refund ID for <?= e($refund['reference']) ?>"><button class="pill-button" type="submit">Record Maya refund</button></form>
<?php elseif ($refund['status'] === 'provider_submitted'): ?><?= $form ?><input type="hidden" name="action" value="check"><button class="pill-button" type="submit"><?= ui_icon('refresh', 14) ?>Check with Maya</button></form>
<?php else: ?><small>No further action.</small><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$data['list']['rows']): ?><tr><td colspan="4">No refund requests for this view.</td></tr><?php endif; ?>
</tbody></table></div>
<?= admin_pager('admin-refunds', $params, $data['pageNumber'], $data['list']['total'], 25) ?></section>
