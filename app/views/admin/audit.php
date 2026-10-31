<?php
require_once BASE_PATH . '/app/views/partials/admin.php';
$data = $pageData; $params = array_filter(['facility_id' => $data['facilityId'] ?: null, 'action' => $data['actionGroup'] ?: null]);
$state = static function (?string $json): string {
    if ($json === null) return '';
    $decoded = json_decode($json, true);
    return is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
};
?>
<section class="page-head"><span class="eyebrow">Accountability</span><h1 class="h1">Admin audit log</h1><p class="small muted">Append-only record of sensitive admin actions with actor, target, reason, before and after state, and outcome. Entries cannot be edited or deleted.</p></section>
<?= admin_errors($data['errors']) ?>
<form class="filter-bar" action="index.php" method="get"><input type="hidden" name="page" value="admin-audit"><?= admin_facility_select($data['facilities'], $data['facilityId']) ?>
<label><span>Area</span><select name="action"><option value="">All areas</option><?php foreach ($data['groups'] as $group): ?><option value="<?= e($group) ?>"<?= $data['actionGroup'] === $group ? ' selected' : '' ?>><?= e(ucfirst($group)) ?></option><?php endforeach; ?></select></label><button class="button secondary" type="submit">Apply</button></form>
<section class="content-card"><div class="table-scroll"><table><thead><tr><th scope="col">When</th><th scope="col">Action</th><th scope="col">Target</th><th scope="col">Reason and change</th></tr></thead><tbody>
<?php foreach ($data['events'] as $event): ?><tr><td><small><?= e(admin_time($event['created_at'], 'M j, Y g:i:s A')) ?><br><?= e((string) ($event['actor_name'] ?? 'System / CLI')) ?><br>Ref <?= e(substr($event['correlation_id'], 0, 8)) ?></small></td>
<td><?= e($event['action']) ?><br><?= ui_status($event['outcome'] === 'succeeded' ? 'active' : 'failed') ?></td>
<td><small><?= e($event['target_type']) ?><?= $event['target_id'] !== null ? ' #' . (int) $event['target_id'] : '' ?><?= $event['facility_name'] ? '<br>' . e($event['facility_name']) : '' ?></small></td>
<td><small><?= e((string) $event['reason']) ?><?php if ($event['before_state'] !== null): ?><br><b>Before</b> <?= e($state($event['before_state'])) ?><?php endif; ?><?php if ($event['after_state'] !== null): ?><br><b>After</b> <?= e($state($event['after_state'])) ?><?php endif; ?></small></td></tr><?php endforeach; ?>
<?php if (!$data['events']): ?><tr><td colspan="4">No audit events for this view.</td></tr><?php endif; ?></tbody></table></div>
<?= admin_pager('admin-audit', $params, $data['pageNumber'], $data['total'], 30) ?></section>
