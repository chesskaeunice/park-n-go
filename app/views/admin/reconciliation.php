<?php
require_once BASE_PATH . '/app/views/partials/admin.php';
use ParkNGo\Services\Admin\ReconciliationService;
$data = $pageData; $params = array_filter(['facility_id' => $data['facilityId'] ?: null, 'show' => $data['show'] === 'all' ? 'all' : null]);
?>
<section class="page-head"><span class="eyebrow">Financial control</span><h1 class="h1">Reconciliation queue</h1><p class="small muted">Payments and sessions whose provider and local records need attention. Status changes only through verified Maya evidence. Admins can recheck, annotate, and mark a case reviewed.</p></section>
<?= admin_errors($data['errors']) ?>
<form class="filter-bar" action="index.php" method="get"><input type="hidden" name="page" value="admin-reconciliation"><?= admin_facility_select($data['facilities'], $data['facilityId']) ?>
<label><span>Show</span><select name="show"><option value="open">Open cases</option><option value="all"<?= $data['show'] === 'all' ? ' selected' : '' ?>>Open and reviewed</option></select></label><button class="button secondary" type="submit">Apply</button></form>
<section class="content-card"><h2 class="h4">Recheck any payment</h2><p class="admin-note">Asks Maya for the authoritative status of a payment, including one already recorded as verified. A contradiction is flagged here; it never reverses payment evidence.</p>
<form class="inline-form" action="<?= e(url('admin-reconciliation', $params)) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="recheck_reference"><input name="payment" required maxlength="29" placeholder="PNGO-…" aria-label="Payment reference to recheck" autocomplete="off"><button class="pill-button" type="submit"><?= ui_icon('refresh', 14) ?>Recheck with Maya</button></form></section>
<section class="content-card"><div class="section-head"><h2 class="h4"><?= number_format($data['total']) ?> case<?= $data['total'] === 1 ? '' : 's' ?></h2><a class="pill-button" href="<?= e(url('admin-reconciliation', $params)) ?>"><?= ui_icon('refresh', 16) ?>Refresh</a></div>
<div class="table-scroll"><table><thead><tr><th scope="col">Case</th><th scope="col">Payment</th><th scope="col" class="num">Amount</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody>
<?php foreach ($data['cases'] as $case): ?>
<tr><td><?php foreach ($case['kinds'] as $kind): ?><span class="tag"><?= e(ReconciliationService::KINDS[$kind]) ?></span> <?php endforeach; ?><br><small><?= e((string) $case['reason']) ?></small>
<?php if ($case['reviewed']): ?><br><?= ui_status('reviewed') ?><?php endif; ?></td>
<td><b><?= e($case['reference']) ?></b><br><small><?= e(admin_type((string) $case['payment_type'])) ?> · <?= e($case['facility_name']) ?><br>
Reservation <?= e((string) $case['reservation_reference']) ?><?= $case['parking_session_id'] ? ' · session #' . (int) $case['parking_session_id'] : '' ?><br>
Maya <?= e($case['maya_checkout_id'] ? substr((string) $case['maya_checkout_id'], 0, 12) . '…' : 'none') ?><br>Created <?= e(admin_time($case['created_at'])) ?> · updated <?= e(admin_time($case['updated_at'])) ?></small></td>
<td class="num"><?= $case['amount_centavos'] === null ? '—' : e(ui_peso((int) $case['amount_centavos'])) ?><br><small><?= e((string) $case['currency']) ?></small></td>
<td><?= ui_status((string) $case['local_status']) ?><br><small>Provider: <?= e((string) ($case['provider_status'] ?? 'not verified')) ?></small></td>
<td><?php if ($case['can_recheck']): ?><form action="<?= e(url('admin-reconciliation', $params)) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="recheck"><input type="hidden" name="case" value="<?= e($case['key']) ?>"><button class="pill-button" type="submit"><?= ui_icon('refresh', 14) ?>Recheck Maya</button></form><?php endif; ?>
<details class="case-details"><summary>Note or review…</summary><form class="inline-form" action="<?= e(url('admin-reconciliation', $params)) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="case" value="<?= e($case['key']) ?>"><input type="hidden" name="expected" value="<?= e($case['fingerprint']) ?>">
<input name="note" required minlength="10" maxlength="1000" placeholder="Investigation note or review reason" aria-label="Note for <?= e($case['reference']) ?>">
<select name="action" aria-label="Action for <?= e($case['reference']) ?>"><option value="note">Add note</option><option value="review">Mark reviewed</option></select><button class="pill-button" type="submit">Save</button></form>
<?php if ($case['history']): ?><ul class="case-history"><?php foreach (array_slice(array_reverse($case['history']), 0, 5) as $item): ?><li><?= e(admin_time($item['created_at'], 'M j g:i A') . ' · ' . $item['actor_name'] . ' · ' . $item['action'] . ': ' . $item['note']) ?></li><?php endforeach; ?></ul><?php endif; ?>
</details>
<?php if ($case['source'] === 'payment' && in_array($case['local_status'], ['verified', 'reconciliation'], true) && in_array($case['provider_status'], ['PAYMENT_SUCCESS', 'CAPTURED'], true)): ?><a class="text-link" href="<?= e(url('admin-refunds', ['payment' => $case['reference']])) ?>">Request refund…</a><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$data['cases']): ?><tr><td colspan="5">No <?= $data['show'] === 'all' ? '' : 'open ' ?>reconciliation cases for this view.</td></tr><?php endif; ?>
</tbody></table></div>
<?= admin_pager('admin-reconciliation', $params, $data['pageNumber'], $data['total'], 20) ?>
<p class="admin-note">Reviewing a case records your reason against the exact record state you saw. If the payment changes later, the case reopens. There is no action that marks an unverified payment as paid.</p></section>
