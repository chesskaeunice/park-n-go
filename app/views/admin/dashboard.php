<?php
require_once BASE_PATH . '/app/views/partials/admin.php';
$data = $pageData; $m = $data['metrics'] ?? null; $filter = ($data['facilityId'] ?? 0) ? ['facility_id' => $data['facilityId']] : [];
$tile = static fn (string $label, int $value, string $route = '', array $params = [], bool $attention = false): string =>
    '<div class="metric' . ($attention && $value > 0 ? ' attention' : '') . '">' . ($route !== '' ? '<a href="' . e(url($route, $params)) . '">' : '')
    . '<span class="metric-value">' . number_format($value) . '</span><span class="metric-label">' . e($label) . '</span>' . ($route !== '' ? '</a>' : '') . '</div>';
?>
<section class="greeting">
    <span class="avatar"><?= e(ui_initials((string) $currentUser['name'])) ?></span>
    <div><p class="small muted">Administrator workspace</p><p class="h3"><?= e($currentUser['name']) ?></p></div>
</section>
<h1 class="h1 home-title">Manage your<br>parking network.</h1>
<?= admin_errors($data['errors'] ?? []) ?>
<?php if ($m): ?>
<section class="section" aria-labelledby="ops-heading" data-auto-refresh="60">
    <div class="section-head"><h2 class="h3" id="ops-heading">Live operations</h2><a class="pill-button" href="<?= e(url('admin-dashboard', $filter)) ?>"><?= ui_icon('refresh', 16) ?>Refresh</a></div>
    <form class="filter-bar" action="index.php" method="get"><input type="hidden" name="page" value="admin-dashboard"><?= admin_facility_select($data['facilities'], $data['facilityId']) ?><button class="button secondary" type="submit">Apply</button></form>
    <p class="admin-note">Derived from reservations, holds, sessions, and payments as of <?= e(admin_time($m['checked_at'], 'M j, Y g:i:s A')) ?> Manila time. Updates every minute while this page is open.</p>
    <div class="metric-grid">
        <?= $tile('Facilities', $m['facilities']) ?><?= $tile('Active facilities', $m['active_facilities']) ?><?= $tile('Parking slots', $m['slots'], 'admin-occupancy', $filter) ?>
        <?= $tile('Available now', $m['available'], 'admin-occupancy', $filter + ['state' => 'available']) ?><?= $tile('Occupied now', $m['occupied'], 'admin-occupancy', $filter + ['state' => 'occupied']) ?>
        <?= $tile('Reserved now', $m['reserved'], 'admin-occupancy', $filter + ['state' => 'reserved']) ?><?= $tile('Out of service', $m['out_of_service']) ?>
        <?= $tile('Unpaid holds', $m['unpaid_holds'], 'admin-occupancy', $filter + ['state' => 'held']) ?><?= $tile('Upcoming confirmed', $m['upcoming_confirmed']) ?>
        <?= $tile('Active sessions', $m['active_sessions'], 'admin-occupancy', $filter + ['state' => 'occupied']) ?><?= $tile('Overdue sessions', $m['overdue_sessions'], 'admin-occupancy', $filter + ['state' => 'attention'], true) ?>
        <?= $tile('Pending payments', $m['pending_payments']) ?><?= $tile('Reconciliation cases', $m['reconciliation_open'], 'admin-reconciliation', $filter, true) ?>
        <?= $tile('Refunds awaiting approval', $m['refunds_awaiting'], 'admin-refunds', $filter, true) ?>
    </div>
</section>
<?php endif; ?>
<h2 class="h3">Operations</h2>
<div class="action-tiles three">
    <a class="action-tile primary" href="<?= e(url('admin-occupancy', $filter)) ?>"><span class="tile-icon"><?= ui_icon('car', 24) ?></span><span class="h4">Occupancy</span></a>
    <a class="action-tile" href="<?= e(url('admin-reconciliation', $filter)) ?>"><span class="tile-icon"><?= ui_icon('alert', 24) ?></span><span class="h4">Reconciliation</span></a>
    <a class="action-tile" href="<?= e(url('admin-refunds', $filter)) ?>"><span class="tile-icon"><?= ui_icon('wallet', 24) ?></span><span class="h4">Refunds</span></a>
    <a class="action-tile" href="<?= e(url('admin-pricing', $filter)) ?>"><span class="tile-icon"><?= ui_icon('sliders', 24) ?></span><span class="h4">Pricing</span></a>
    <a class="action-tile" href="<?= e(url('admin-reports', $filter)) ?>"><span class="tile-icon"><?= ui_icon('book', 24) ?></span><span class="h4">Reports</span></a>
    <a class="action-tile" href="<?= e(url('admin-audit', $filter)) ?>"><span class="tile-icon"><?= ui_icon('shield', 24) ?></span><span class="h4">Audit log</span></a>
    <a class="action-tile" href="<?= e(url('admin-kiosks')) ?>"><span class="tile-icon"><?= ui_icon('scan-out', 24) ?></span><span class="h4">Exit kiosks</span></a>
    <a class="action-tile" href="<?= e(url('admin-permissions')) ?>"><span class="tile-icon"><?= ui_icon('lock', 24) ?></span><span class="h4">Permissions</span></a>
</div>
<h2 class="h3">Inventory</h2>
<div class="action-tiles three">
    <a class="action-tile" href="<?= e(url('admin-facilities')) ?>"><span class="tile-icon"><?= ui_icon('building', 24) ?></span><span class="h4">Facilities</span></a>
    <a class="action-tile" href="<?= e(url('admin-floors')) ?>"><span class="tile-icon"><?= ui_icon('layers', 24) ?></span><span class="h4">Floors</span></a>
    <a class="action-tile" href="<?= e(url('admin-slots')) ?>"><span class="tile-icon"><?= ui_icon('car', 24) ?></span><span class="h4">Slots</span></a>
</div>
<article class="card"><h2 class="h4">Your sensitive permissions</h2><p class="small"><?php $held = array_keys(array_filter($data['permissions'] ?? [])); ?><?= $held ? e(implode(', ', array_map([\ParkNGo\Services\Admin\AdminPermissions::class, 'label'], $held))) : 'None. Pricing changes, refund approvals, and active-session exceptions each need an explicit permission granted by another administrator.' ?></p></article>
