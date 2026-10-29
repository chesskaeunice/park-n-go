<?php
$window = $pageData['window'];
?>
<section class="shell page-intro"><span class="eyebrow">Find your next stop</span><h1>Room for <span class="gradient-text">your plans.</span></h1><p>Explore facilities and check parking spaces for your arrival time.</p><div class="notice">Development inventory: seeded locations are examples, not operating partner facilities. Drivers can create temporary unpaid holds; payments and confirmed entry are not enabled yet.</div></section>
<section class="shell facilities-section">
    <?php require BASE_PATH . '/app/views/public/parking-search.php'; ?>
    <div class="facility-grid">
    <?php foreach ($pageData['facilities'] as $facility): ?>
        <article class="facility-card"><div class="image-placeholder facility-art"><span>Parking Facility Image Placeholder</span></div><div class="facility-body"><span class="muted"><?= e($facility['city']) ?></span><h2><?= e($facility['name']) ?></h2><p><?= e($facility['address']) ?><br><?= (int) $facility['floor_count'] ?> active floor<?= (int) $facility['floor_count'] === 1 ? '' : 's' ?></p><div class="facility-bottom"><span><?php if ($facility['initial_centavos'] !== null): ?><strong>₱<?= number_format((int) $facility['initial_centavos'] / 100, 2) ?></strong><small> / first <?= (int) $facility['initial_minutes'] ?> min</small><?php else: ?><small>Rate not configured</small><?php endif; ?></span><a class="map-link" href="<?= e(url('facility', ['facility_id' => $facility['id']] + $window->query())) ?>">View facility</a></div></div></article>
    <?php endforeach; ?>
    </div>
    <?php if (!$pageData['facilities']): ?><div class="empty-state"><h2>No parking facilities found</h2><p>Try another location, or check again after a facility is added.</p><a class="text-link" href="<?= e(url('facilities')) ?>">Clear search</a></div><?php endif; ?>
</section>
