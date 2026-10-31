<?php
require_once BASE_PATH . '/app/views/partials/admin.php';
use ParkNGo\Services\Admin\PricingService;
$data = $pageData; $overview = $data['overview']; $current = $overview['current'] ?? null; $allowed = $data['permissions']['manage_pricing'];
$peso = static fn ($centavos): string => $centavos === null ? '' : number_format((int) $centavos / 100, 2, '.', '');
$value = static fn (string $key, string $default) => is_string($data['old'][$key] ?? null) ? $data['old'][$key] : $default;
$base = $overview['scheduled'][0] ?? $current;
?>
<section class="page-head"><span class="eyebrow">Facility pricing</span><h1 class="h1">Rates</h1><p class="small muted">Rate changes are prospective. Each change adds a new versioned rate; existing reservations, active sessions, settled overtime, purchased extensions, and payments keep the rate they were created with.</p></section>
<?= admin_errors($data['errors']) ?>
<form class="filter-bar" action="index.php" method="get"><input type="hidden" name="page" value="admin-pricing"><?= admin_facility_select($data['facilities'], $data['facilityId'], false) ?><button class="button secondary" type="submit">View</button></form>
<?php if (!$overview): ?><div class="empty-state"><p>Create a facility before configuring rates.</p></div><?php else: ?>
<section class="content-card"><h2 class="h4">Current rate</h2>
<?php if ($current): ?><dl class="kv"><div><dt>Initial period</dt><dd><?= e(ui_peso((int) $current['initial_centavos'])) ?> for <?= (int) $current['initial_minutes'] ?> min</dd></div>
<div><dt>Succeeding hour / extension hour</dt><dd><?= e(ui_peso((int) $current['succeeding_hour_centavos'])) ?></dd></div>
<div><dt>Overtime hour</dt><dd><?= $current['overtime_hour_centavos'] === null ? 'Not configured (overdue exits stay blocked)' : e(ui_peso((int) $current['overtime_hour_centavos'])) ?></dd></div>
<div><dt>Daily maximum</dt><dd><?= $current['daily_max_centavos'] === null ? 'None' : e(ui_peso((int) $current['daily_max_centavos'])) ?></dd></div>
<div><dt>Additional charge</dt><dd><?= e(ui_peso((int) $current['additional_centavos'])) ?></dd></div>
<div><dt>Effective since</dt><dd><?= e(admin_time($current['effective_from'])) ?></dd></div></dl>
<?php else: ?><p class="small">No rate is in effect. Drivers see “Rate not configured” and cannot reserve here.</p><?php endif; ?>
<?php foreach ($overview['scheduled'] as $scheduled): ?><div class="status-note"><?= ui_icon('clock', 20) ?><span>Scheduled: <?= e(ui_peso((int) $scheduled['initial_centavos'])) ?> initial, <?= e(ui_peso((int) $scheduled['succeeding_hour_centavos'])) ?> per succeeding hour from <?= e(admin_time($scheduled['effective_from'])) ?>.</span></div><?php endforeach; ?>
</section>
<?php if ($allowed): ?>
<section class="content-card"><h2 class="h4">Schedule a new rate</h2>
<form class="account-form" action="<?= e(url('admin-pricing', ['facility_id' => $data['facilityId']])) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="expected_latest" value="<?= (int) $overview['latest_id'] ?>">
<label for="rate-minutes">Initial period (minutes)</label><input id="rate-minutes" name="initial_minutes" inputmode="numeric" required pattern="\d{1,4}" value="<?= e($value('initial_minutes', (string) ($base['initial_minutes'] ?? 60))) ?>">
<?php foreach (PricingService::FIELDS as $field => $label): ?><label for="rate-<?= e($field) ?>"><?= e($label) ?> (₱)</label><input id="rate-<?= e($field) ?>" name="<?= e($field) ?>" inputmode="decimal" pattern="\d{1,7}(\.\d{1,2})?"<?= $field === 'daily_max_centavos' ? '' : ' required' ?> value="<?= e($value($field, $peso($base[$field] ?? null))) ?>"><?php endforeach; ?>
<label for="rate-effective">Effective from (Manila time, blank for now)</label><input id="rate-effective" name="effective_from" type="datetime-local" value="<?= e($value('effective_from', '')) ?>">
<label for="rate-reason">Reason for change</label><textarea id="rate-reason" name="reason" required minlength="10" maxlength="1000"><?= e($value('reason', '')) ?></textarea>
<small>Amounts are stored in integer centavos. Grace (<?= (int) ($base['grace_minutes'] ?? 15) ?> min) and cancellation cutoff (<?= (int) ($base['cancellation_cutoff_minutes'] ?? 60) ?> min) carry forward unchanged.</small>
<button class="button primary block" type="submit">Schedule rate</button></form></section>
<?php else: ?><div class="status-note"><?= ui_icon('lock', 20) ?><span>Changing rates needs the Manage pricing permission.</span></div><?php endif; ?>
<section class="content-card"><h2 class="h4">Rate history</h2><div class="table-scroll"><table><thead><tr><th scope="col">Effective</th><th scope="col" class="num">Initial</th><th scope="col" class="num">Hour</th><th scope="col" class="num">Overtime</th><th scope="col" class="num">Bookings</th><th scope="col">Changed by</th></tr></thead><tbody>
<?php foreach ($overview['history'] as $rate): ?><tr><td><?= e(admin_time($rate['effective_from'])) ?></td><td class="num"><?= e(ui_peso((int) $rate['initial_centavos'])) ?></td><td class="num"><?= e(ui_peso((int) $rate['succeeding_hour_centavos'])) ?></td><td class="num"><?= $rate['overtime_hour_centavos'] === null ? '—' : e(ui_peso((int) $rate['overtime_hour_centavos'])) ?></td><td class="num"><?= (int) $rate['reservation_count'] ?></td><td><small><?= e((string) ($rate['created_by_name'] ?? 'Setup')) ?><?= $rate['change_reason'] ? '<br>' . e($rate['change_reason']) : '' ?></small></td></tr><?php endforeach; ?>
<?php if (!$overview['history']): ?><tr><td colspan="6">No rates recorded.</td></tr><?php endif; ?></tbody></table></div></section>
<?php endif; ?>
