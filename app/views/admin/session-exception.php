<?php
require_once BASE_PATH . '/app/views/partials/admin.php';
$data = $pageData; $session = $data['session']; $overtime = $session['overtime']; $preview = $data['preview']; $allowed = $data['permissions']['active_session_exception'];
?>
<section class="page-head"><span class="eyebrow">Sensitive operation</span><h1 class="h1">Active session exception</h1><p class="small muted">For a legitimate session that cannot finish through the Exit Kiosk, a confirmed data inconsistency, or an emergency closure. This is not a refund and does not waive any charge.</p></section>
<?= admin_errors($data['errors']) ?>
<section class="content-card"><h2 class="h4">Session <?= e($session['reference']) ?></h2>
<dl class="kv"><div><dt>Facility</dt><dd><?= e($session['facility_name']) ?> · slot <?= e($session['slot_label']) ?></dd></div><div><dt>Driver</dt><dd><?= e($session['driver_name']) ?></dd></div>
<div><dt>Status</dt><dd><?= ui_status($session['status']) ?></dd></div><div><dt>Entered</dt><dd><?= e(admin_time($session['entry_at'])) ?></dd></div>
<div><dt>Paid until</dt><dd><?= e(admin_time($overtime['chargeable_from'])) ?></dd></div>
<div><dt>Overtime now</dt><dd><?= $overtime['payment_required'] ? e($overtime['overtime_minutes'] . ' min · ' . ($overtime['estimated_centavos'] === null ? 'no overtime rate configured' : ui_peso((int) $overtime['estimated_centavos']))) : 'None' ?></dd></div></dl>
<?php if ($session['pending_payments']): ?><p class="admin-note">Pending checkouts stay untouched: <?= e(implode(', ', array_map(static fn (array $p): string => $p['reference'] . ' (' . admin_type($p['payment_type']) . ')', $session['pending_payments']))) ?>. If one is paid later, it enters reconciliation.</p><?php endif; ?>
</section>
<?php if ($session['status'] !== 'active'): ?><div class="status-note"><?= ui_icon('info', 20) ?><span>This session is no longer active.</span></div>
<?php elseif (!$allowed): ?><div class="status-note"><?= ui_icon('lock', 20) ?><span>Your account does not have the Active session exceptions permission. Ask an administrator who manages permissions.</span></div>
<?php elseif ($preview): ?>
<section class="content-card"><h2 class="h4">Confirm the exception</h2>
<p class="small">Closing records the exit now, completes the reservation and ticket, and releases the slot. Payment records are preserved.<?php if ($preview['session']['overtime']['payment_required']): ?> <b>Unpaid overtime of <?= e($preview['session']['overtime']['estimated_centavos'] === null ? $preview['session']['overtime']['overtime_minutes'] . ' minutes (unpriced)' : ui_peso((int) $preview['session']['overtime']['estimated_centavos'])) ?> will remain recorded as an unsettled obligation in reconciliation.</b><?php endif; ?></p>
<p class="small"><b>Category:</b> <?= e($data['categories'][$preview['category']]) ?><br><b>Reason:</b> <?= e($preview['reason']) ?></p>
<form class="account-form" action="<?= e(url('admin-session-exception')) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>"><input type="hidden" name="token" value="<?= e($preview['token']) ?>">
<label class="checkbox-label"><input type="checkbox" name="confirm" value="yes" required> I verified this session and facility and confirm closing it by exception.</label>
<button class="button primary block" type="submit">Close session by exception</button></form></section>
<?php else: ?>
<section class="content-card"><h2 class="h4">Describe the exception</h2>
<form class="account-form" action="<?= e(url('admin-session-exception')) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="preview"><input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
<label for="exception-facility">Confirm facility</label><select id="exception-facility" name="facility_id" required><option value="">Choose the session's facility</option><option value="<?= (int) $session['facility_id'] ?>"><?= e($session['facility_name']) ?></option></select>
<label for="exception-category">Category</label><select id="exception-category" name="category" required><option value="">Choose a category</option><?php foreach ($data['categories'] as $value => $label): ?><option value="<?= e($value) ?>"<?= $data['old']['category'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
<label for="exception-reason">Reason</label><textarea id="exception-reason" name="reason" required minlength="20" maxlength="1000"><?= e($data['old']['reason']) ?></textarea><small>At least 20 characters. Recorded in the audit trail.</small>
<button class="button primary block" type="submit">Review exception</button></form></section>
<?php endif; ?>
