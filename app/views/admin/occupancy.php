<?php
require_once BASE_PATH . '/app/views/partials/admin.php';
$data = $pageData; $params = ['facility_id' => $data['facilityId']] + ($data['state'] !== '' ? ['state' => $data['state']] : []);
$states = ['' => 'All slots', 'attention' => 'Needs attention', 'available' => 'Available', 'occupied' => 'Occupied', 'reserved' => 'Reserved', 'held' => 'Held', 'maintenance' => 'Maintenance', 'disabled' => 'Disabled'];
?>
<section class="page-head"><span class="eyebrow">Facility operations</span><h1 class="h1">Slot occupancy</h1><p class="small muted">Occupancy is derived from parking sessions, confirmed reservations, and live holds. Inventory status never overrides them.</p></section>
<?= admin_errors($data['errors']) ?>
<form class="filter-bar" action="index.php" method="get"><input type="hidden" name="page" value="admin-occupancy"><?= admin_facility_select($data['facilities'], $data['facilityId'], false) ?>
<label><span>Show</span><select name="state"><?php foreach ($states as $value => $label): ?><option value="<?= e($value) ?>"<?= $data['state'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><button class="button secondary" type="submit">Apply</button></form>
<?php if (!$data['facilityId']): ?><div class="empty-state"><p>Create a facility to monitor its slots.</p></div>
<?php else: ?>
<section class="content-card"><h2 class="h4"><?= number_format($data['total']) ?> slot<?= $data['total'] === 1 ? '' : 's' ?></h2><div class="table-scroll"><table><thead><tr><th scope="col">Slot</th><th scope="col">State</th><th scope="col">Session / hold / next booking</th><th scope="col">Last event</th><th scope="col">Correct</th></tr></thead><tbody>
<?php foreach ($data['slots'] as $slot): ?>
<tr id="slot-<?= (int) $slot['id'] ?>"><td><b><?= e($slot['label']) ?></b><br><small><?= e($slot['floor_label']) ?> · inventory <?= e($slot['inventory_status']) ?></small></td>
<td><?= ui_status($slot['state']) ?><?php if ($slot['overdue']): ?><span class="status-pill warn">Overdue</span><?php endif; ?></td>
<td><?php foreach ($slot['sessions'] as $session): ?><div><b>Session</b> <?= e($session['reference']) ?><br><small>Entered <?= e(admin_time($session['entry_at'])) ?> · paid until <?= e(admin_time($session['overtime']['chargeable_from'])) ?></small>
    <br><a class="text-link" href="<?= e(url('admin-session-exception', ['session_id' => $session['id']])) ?>">Session exception…</a></div><?php endforeach; ?>
<?php foreach ($slot['holds'] as $hold): ?><div><b>Hold</b> <?= e((string) ($hold['reference'] ?? 'no reservation')) ?> <small>(<?= e((string) ($hold['reservation_status'] ?? 'none')) ?>) until <?= e(admin_time($hold['expires_at'], 'g:i A')) ?></small></div><?php endforeach; ?>
<?php if ($next = $slot['confirmed'][0] ?? null): ?><div><b>Next</b> <?= e($next['reference']) ?> <small><?= e(admin_time($next['starts_at'])) ?>–<?= e(admin_time($next['ends_at'], 'g:i A')) ?></small></div><?php endif; ?>
<?php if (!$slot['sessions'] && !$slot['holds'] && !$slot['confirmed']): ?><small>None</small><?php endif; ?></td>
<td><small><?= $slot['last_event'] ? e($slot['last_event'][0] . ' · ' . admin_time($slot['last_event'][1])) : '—' ?></small></td>
<td><details class="case-details"><summary>Correct…</summary><form class="inline-form" action="<?= e(url('admin-occupancy', $params)) ?>" method="post"><?= csrf_field() ?>
<input type="hidden" name="slot_id" value="<?= (int) $slot['id'] ?>"><input type="hidden" name="expected" value="<?= e($slot['fingerprint']) ?>">
<select name="action" aria-label="Correction for <?= e($slot['label']) ?>"><?php if ($slot['stale_holds']): ?><option value="release_stale_holds">Release stale holds</option><?php endif; ?>
<?php foreach (['available' => 'Mark available', 'maintenance' => 'Mark maintenance', 'disabled' => 'Mark disabled'] as $status => $label): if ($status !== $slot['inventory_status']): ?><option value="mark_<?= $status ?>"><?= $label ?></option><?php endif; endforeach; ?></select>
<input name="reason" required minlength="10" maxlength="1000" placeholder="Reason (required)" aria-label="Reason for correcting <?= e($slot['label']) ?>"><button class="pill-button" type="submit">Apply</button></form></details></td></tr>
<?php endforeach; ?>
<?php if (!$data['slots']): ?><tr><td colspan="5">No slots match this view.</td></tr><?php endif; ?>
</tbody></table></div>
<?= admin_pager('admin-occupancy', $params, $data['pageNumber'], $data['total'], 25) ?>
<p class="admin-note">Corrections lock the slot, re-check the records you reviewed, and refuse if anything changed. Closing an active paid session needs the separate active-session exception permission.</p></section>
<section class="content-card"><h2 class="h4">Recent session exceptions</h2><div class="table-scroll"><table><thead><tr><th scope="col">Closed</th><th scope="col">Reservation</th><th scope="col">Category</th><th scope="col">Obligation</th><th scope="col">By</th></tr></thead><tbody>
<?php foreach ($data['exceptions'] as $exception): ?><tr><td><?= e(admin_time($exception['closed_at'])) ?></td><td><?= e($exception['reference']) ?></td><td><?= e(\ParkNGo\Services\Admin\SessionExceptionService::CATEGORIES[$exception['category']]) ?></td>
<td><?= $exception['obligation_status'] === 'unsettled' ? ui_status('unsettled') . ' ' . e($exception['outstanding_centavos'] === null ? 'unpriced' : ui_peso((int) $exception['outstanding_centavos'])) : 'None' ?></td><td><?= e($exception['closed_by_name']) ?></td></tr><?php endforeach; ?>
<?php if (!$data['exceptions']): ?><tr><td colspan="5">No session exceptions recorded.</td></tr><?php endif; ?></tbody></table></div></section>
<?php endif; ?>
