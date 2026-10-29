<?php $window = $pageData['window']; $facility = $pageData['facility']; $availability = $pageData['availability']; $selection = $pageData['selection']; ?>
<section class="shell page-intro"><a class="text-link" href="<?= e(url('facilities', $window->query())) ?>">Back to parking facilities</a><h1><?= e($facility['name']) ?></h1><p><?= e($facility['address']) ?> · <?= e($facility['city']) ?></p><?php if ($facility['description']): ?><p><?= e($facility['description']) ?></p><?php endif; ?><div class="notice">Select an available space, review the estimate, then start a temporary checkout hold. Payment and QR entry are not enabled yet.</div></section>
<section class="shell section facility-detail" id="parking-map">
    <?php require BASE_PATH . '/app/views/public/parking-search.php'; ?>
    <?php if (!$pageData['floors']): ?><div class="empty-state"><h2>No active floors yet</h2><p>This facility is not ready for slot selection. Please check another facility.</p></div>
    <?php elseif ($availability): ?>
    <div class="section-heading"><div><h2>Choose a parking space</h2><p id="map-window"><?= e($availability['window']['label']) ?></p></div><button type="button" class="button secondary" id="refresh-map" hidden>Refresh availability</button></div>
    <p id="map-feedback" role="status" aria-live="polite" class="form-note"><?= (int) $availability['counts']['available'] ?> of <?= count($availability['slots']) ?> spaces available. <?= e($availability['layout_note'] ?? '') ?></p>
    <div class="map-layout" id="parking-data" data-facility-id="<?= (int) $facility['id'] ?>">
        <div class="map-panel"><div class="map-legend"><span><i class="available"></i>Available</span><span><i class="held"></i>Temporarily held</span><span><i class="reserved"></i>Reserved</span><span><i class="occupied"></i>Occupied</span><span><i class="maintenance"></i>Maintenance</span><span><i class="disabled"></i>Disabled</span></div>
            <div class="map-scroll" tabindex="0" role="region" aria-label="Parking layout, scroll horizontally to view all spaces"><div class="interactive-map"><div class="map-direction">↘ ENTRANCE <span>EXIT ↗</span></div><div id="map-rows">
            <?php
            $rows = []; $columns = 1;
            foreach ($availability['slots'] as $slot) { $rows[$slot['position_y']][] = $slot; $columns = max($columns, min(12, $slot['position_x'] + 1)); }
            foreach ($rows as $rowIndex => $slots): ?>
                <?php if ($rowIndex !== array_key_first($rows)): ?><div class="drive-lane">→ <span>DRIVE LANE · ONE WAY</span> →</div><?php endif; ?>
                <div class="slot-row cols-<?= $columns ?>">
                <?php $byColumn = []; foreach ($slots as $slot) $byColumn[$slot['position_x']] = $slot;
                for ($column = 0; $column < $columns; $column++): $slot = $byColumn[$column] ?? null; if (!$slot): ?><span class="slot-gap" aria-hidden="true"></span><?php continue; endif; ?>
                    <button class="slot <?= e($slot['status']) ?> <?= ($selection['id'] ?? null) === $slot['id'] ? 'selected' : '' ?>" type="submit" form="map-query" name="slot_id" value="<?= $slot['id'] ?>" data-slot-id="<?= $slot['id'] ?>" data-label="<?= e($slot['label']) ?>" aria-label="<?= e($slot['label'] . ', ' . $slot['status']) ?>" <?= $slot['status'] !== 'available' ? 'disabled' : 'aria-pressed="' . (($selection['id'] ?? null) === $slot['id'] ? 'true' : 'false') . '"' ?>>
                        <span class="<?= $slot['status'] === 'occupied' ? 'top-car' : 'slot-symbol' ?>" aria-hidden="true"><?= $slot['status'] === 'occupied' ? '' : ($slot['status'] === 'available' ? 'P' : '−') ?></span><span><?= e($slot['label']) ?></span>
                    </button>
                <?php endfor; ?></div>
            <?php endforeach; ?>
            <?php if (!$rows): ?><p>No slots have been added to this floor.</p><?php endif; ?>
            </div><div class="map-bottom">PEDESTRIAN WALKWAY <span>LIFT & STAIRS</span></div></div></div><p class="map-tip">Choose an available space. On small screens, scroll inside the layout.</p>
        </div>
        <aside class="selection-panel" aria-live="polite"><span class="eyebrow">Your selected space</span><div id="selection-empty" <?= $selection ? 'hidden' : '' ?>><h3>Find your space.</h3><p>Select an available slot to inspect its details.</p></div><div id="selection-details" <?= !$selection ? 'hidden' : '' ?>><h3 id="selected-slot"><?= e($selection['label'] ?? '') ?></h3><dl><div><dt>Floor</dt><dd id="selected-floor"><?= e($availability['floor']['label']) ?></dd></div><div><dt>Location</dt><dd id="selected-location"><?= e($facility['name']) ?></dd></div><div><dt>Initial rate</dt><dd id="selected-rate"><?php if ($availability['rate']): ?>₱<?= number_format($availability['rate']['initial_centavos'] / 100, 2) ?> / <?= $availability['rate']['initial_minutes'] ?> min<?php else: ?>Not configured<?php endif; ?></dd></div><div><dt>Status</dt><dd class="success-text">Available</dd></div></dl><p class="notice">Selecting a space creates no hold. Review the estimate and submit checkout to hold it temporarily. A driver account is required.</p><?php if (($currentUser['role']??'driver')==='driver'): ?><a class="button primary" id="review-reservation" href="<?= e(url('reservation-review',['slot_id'=>$selection['id']??0]+$window->query())) ?>" <?= !$availability['rate']?'hidden':'' ?>><?= $currentUser?'Review reservation':'Log in to continue' ?></a><?php endif; ?><button class="button secondary" type="submit" form="map-query" id="clear-selection">Clear selection</button></div></aside>
    </div>
    <noscript><p class="notice">The server-rendered map works without JavaScript. Choose a floor/time and submit to refresh, or select a space to see its details.</p></noscript>
    <?php endif; ?>
</section>
