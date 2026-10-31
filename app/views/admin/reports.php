<?php
require_once BASE_PATH . '/app/views/partials/admin.php';
use ParkNGo\Services\Admin\ReportService;
$data = $pageData; $report = $data['report']; $filters = $data['filters'];
$params = ['type' => $data['type'], 'from' => $filters['from'], 'to' => $filters['to']] + ($filters['facility_id'] ? ['facility_id' => $filters['facility_id']] : []);
?>
<section class="page-head"><span class="eyebrow">Operational reports</span><h1 class="h1"><?= e($report['title']) ?></h1><p class="small muted"><?= e($filters['facility_name']) ?> · <?= e(ReportService::periodLabel($filters)) ?></p>
<nav class="segmented" aria-label="Report type"><?php foreach (ReportService::TYPES as $type => $label): ?><a href="<?= e(url('admin-reports', ['type' => $type] + array_diff_key($params, ['type' => 1]))) ?>"<?= $data['type'] === $type ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?></nav></section>
<?= admin_errors($data['errors']) ?>
<form class="filter-bar" action="index.php" method="get"><input type="hidden" name="page" value="admin-reports"><input type="hidden" name="type" value="<?= e($data['type']) ?>">
<label><span>From</span><input type="date" name="from" value="<?= e($filters['from']) ?>" required></label><label><span>To</span><input type="date" name="to" value="<?= e($filters['to']) ?>" required></label>
<?= admin_facility_select($data['facilities'], $filters['facility_id']) ?><button class="button secondary" type="submit">Run report</button></form>
<section class="content-card"><div class="section-head"><h2 class="h4">Summary</h2><div class="button-row"><a class="pill-button" href="<?= e(url('admin-report-export', $params + ['format' => 'csv'])) ?>">Export CSV</a><a class="pill-button" href="<?= e(url('admin-report-export', $params + ['format' => 'pdf'])) ?>">Export PDF</a></div></div>
<div class="metric-grid"><?php foreach ($report['summary'] as [$label, $value, $kind]): ?><div class="metric"><span class="metric-value"><?= e(ReportService::format($value, $kind)) ?></span><span class="metric-label"><?= e($label) ?></span></div><?php endforeach; ?></div>
<?php foreach ($report['notes'] as $note): ?><p class="admin-note"><?= e($note) ?></p><?php endforeach; ?>
<p class="admin-note">Generated <?= e(ReportService::format($report['generated_at'], 'datetime')) ?> Manila time.</p></section>
<section class="content-card"><h2 class="h4">Details</h2><div class="table-scroll"><table><thead><tr><?php foreach ($report['columns'] as [, $label, $kind]): ?><th scope="col"<?= in_array($kind, ['money', 'int', 'hours', 'percent'], true) ? ' class="num"' : '' ?>><?= e($label) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($report['rows'] as $row): ?><tr><?php foreach ($report['columns'] as [$key, , $kind]): ?><td<?= in_array($kind, ['money', 'int', 'hours', 'percent'], true) ? ' class="num"' : '' ?>><?= e(ReportService::format($row[$key] ?? null, $kind)) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
<?php if (!$report['rows']): ?><tr><td colspan="<?= count($report['columns']) ?>">No rows for these filters.</td></tr><?php endif; ?>
</tbody></table></div><?= admin_pager('admin-reports', $params, (int) $report['page'], $report['total_rows'], ReportService::PAGE_SIZE) ?></section>
