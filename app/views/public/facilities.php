<?php
$window = $pageData['window'];
$facilityCount = count($pageData['facilities']);
?>
<section class="page-head">
    <span class="eyebrow">Find your next stop</span>
    <h1 class="h1">Find Parking</h1>
    <p class="small muted">Explore facilities and check parking spaces for your arrival time.</p>
</section>
<section class="facilities-section">
    <?php require BASE_PATH . '/app/views/public/parking-search.php'; ?>
    <?php if ($facilityCount): ?><p class="result-meta"><b><?= $facilityCount ?> facilit<?= $facilityCount === 1 ? 'y' : 'ies' ?></b><?= $pageData['query'] !== '' ? ' for “' . e($pageData['query']) . '”' : '' ?></p><?php endif; ?>
    <div class="facility-list">
    <?php foreach ($pageData['facilities'] as $facility): ?>
        <a class="facility-card" href="<?= e(url('facility', ['facility_id' => $facility['id']] + $window->query())) ?>">
            <?= ui_lot((int) $facility['id'], 'facility-thumb', true) ?>
            <div class="facility-info">
                <h2 class="h4"><?= e($facility['name']) ?></h2>
                <p class="meta"><?= ui_icon('pin', 14) ?><span><?= e($facility['address']) ?> · <?= e($facility['city']) ?></span></p>
                <div class="tags"><span class="tag"><?= ui_icon('layers', 13) ?><?= (int) $facility['floor_count'] ?> active floor<?= (int) $facility['floor_count'] === 1 ? '' : 's' ?></span></div>
                <div class="facility-bottom">
                    <?php if ($facility['initial_centavos'] !== null): ?><span class="price-sm"><?= e(ui_peso((int) $facility['initial_centavos'])) ?> <small>/ first <?= (int) $facility['initial_minutes'] ?> min</small></span><?php else: ?><span class="small muted">Rate not configured</span><?php endif; ?>
                    <span class="map-link">View facility<?= ui_icon('chevron-right', 16) ?></span>
                </div>
            </div>
        </a>
    <?php endforeach; ?>
    </div>
    <?php if (!$pageData['facilities']): ?><div class="empty-state"><span class="state-icon"><?= ui_icon('search', 28) ?></span><h2 class="h3">No parking facilities found</h2><p>Try another location, or check again after a facility is added.</p><a class="button secondary" href="<?= e(url('facilities')) ?>">Clear search</a></div><?php endif; ?>
    <div class="notice"><?= ui_icon('info', 18) ?><p>Development inventory: seeded locations are examples, not operating partner facilities. Drivers can create temporary holds and use Maya Sandbox payment verification locally.</p></div>
</section>
