<?php $window = $pageData['window']; $facility = $pageData['facility']; $availability = $pageData['availability']; $selection = $pageData['selection']; ?>
<section class="detail-hero">
    <?= ui_lot((int) $facility['id'], 'detail-art') ?>
    <div class="detail-title">
        <h1 class="h2"><?= e($facility['name']) ?></h1>
        <p class="small muted"><?= e($facility['address']) ?> · <?= e($facility['city']) ?></p>
    </div>
    <div class="chips-scroll info-chips">
        <span class="chip"><?= ui_icon('pin', 16) ?><?= e($facility['city']) ?></span>
        <span class="chip"><?= ui_icon('layers', 16) ?><?= count($pageData['floors']) ?> floor<?= count($pageData['floors']) === 1 ? '' : 's' ?></span>
        <?php if ($availability && $availability['rate']): ?><span class="chip"><?= ui_icon('clock', 16) ?><?= e(ui_peso((int) $availability['rate']['initial_centavos'])) ?> / <?= (int) $availability['rate']['initial_minutes'] ?> min</span><?php endif; ?>
    </div>
    <?php if ($facility['description']): ?><div class="description"><h2 class="h3">Description</h2><p><?= e($facility['description']) ?></p></div><?php endif; ?>
    <div class="notice"><?= ui_icon('info', 18) ?><p>Select an available space, review the estimate, then start a temporary checkout hold. Maya Sandbox payment and verified QR entry are available in local development.</p></div>
</section>
<section class="facility-detail" id="parking-map">
    <h2 class="h3 section-title">Plan your stay</h2>
    <?php require BASE_PATH . '/app/views/public/parking-search.php'; ?>
    <?php if (!$pageData['floors']): ?><div class="empty-state"><span class="state-icon"><?= ui_icon('layers', 28) ?></span><h2 class="h3">No active floors yet</h2><p>This facility is not ready for slot selection. Please check another facility.</p></div>
    <?php elseif ($availability): ?>
    <div class="section-head map-heading">
        <div><h2 class="h3">Choose a parking space</h2><p id="map-window" class="small muted"><?= e($availability['window']['label']) ?></p></div>
        <button type="button" class="pill-button" id="refresh-map" hidden><?= ui_icon('refresh', 16) ?><span>Refresh</span></button>
    </div>
    <p id="map-feedback" role="status" aria-live="polite" class="form-note"><?= (int) $availability['counts']['available'] ?> of <?= count($availability['slots']) ?> spaces available. <?= e($availability['layout_note'] ?? '') ?></p>
    <div class="map-layout" id="parking-data" data-facility-id="<?= (int) $facility['id'] ?>">
        <div class="map-panel">
            <div class="map-legend"><span><i class="available"></i>Available</span><span><i class="selected"></i>Selected</span><span><i class="held"></i>Held</span><span><i class="reserved"></i>Reserved</span><span><i class="occupied"></i>Occupied</span><span><i class="maintenance"></i>Maintenance</span><span><i class="disabled"></i>Disabled</span></div>
            <div class="map-head"><span class="map-badge"><?= ui_icon('layers', 15) ?><?= e($availability['floor']['label']) ?></span><span class="map-open" id="map-open-count"><?= (int) $availability['counts']['available'] ?> open</span></div>
            <div class="map-scroll" tabindex="0" role="region" aria-label="Parking layout, scroll horizontally to view all spaces"><div class="interactive-map"><div class="map-direction"><span><?= ui_icon('arrow-right', 14) ?> ENTRANCE</span><span>EXIT <?= ui_icon('arrow-right', 14) ?></span></div><div id="map-rows">
            <?php
            $rows = []; $columns = 1;
            foreach ($availability['slots'] as $slot) { $rows[$slot['position_y']][] = $slot; $columns = max($columns, min(12, $slot['position_x'] + 1)); }
            foreach ($rows as $rowIndex => $slots): ?>
                <?php if ($rowIndex !== array_key_first($rows)): ?><div class="drive-lane"><i class="lane-arrow" aria-hidden="true"></i><span>DRIVE LANE · ONE WAY</span><i class="lane-arrow" aria-hidden="true"></i></div><?php endif; ?>
                <div class="slot-row cols-<?= $columns ?>">
                <?php $byColumn = []; foreach ($slots as $slot) $byColumn[$slot['position_x']] = $slot;
                for ($column = 0; $column < $columns; $column++): $slot = $byColumn[$column] ?? null; if (!$slot): ?><span class="slot-gap" aria-hidden="true"></span><?php continue; endif; ?>
                    <button class="slot <?= e($slot['status']) ?> <?= ($selection['id'] ?? null) === $slot['id'] ? 'selected' : '' ?>" type="submit" form="map-query" name="slot_id" value="<?= $slot['id'] ?>" data-slot-id="<?= $slot['id'] ?>" data-label="<?= e($slot['label']) ?>" aria-label="<?= e($slot['label'] . ', ' . $slot['status']) ?>" <?= $slot['status'] !== 'available' ? 'disabled' : 'aria-pressed="' . (($selection['id'] ?? null) === $slot['id'] ? 'true' : 'false') . '"' ?>>
                        <span class="<?= $slot['status'] === 'occupied' ? 'top-car' : 'slot-symbol' ?>" aria-hidden="true"><?= $slot['status'] === 'occupied' ? '' : ($slot['status'] === 'available' ? 'P' : '−') ?></span><span class="slot-label"><?= e($slot['label']) ?></span>
                    </button>
                <?php endfor; ?></div>
            <?php endforeach; ?>
            <?php if (!$rows): ?><p>No slots have been added to this floor.</p><?php endif; ?>
            </div><div class="map-bottom"><span>PEDESTRIAN WALKWAY</span><span>LIFT &amp; STAIRS</span></div></div></div>
            <p class="map-tip">Tap an available space. On small screens, scroll inside the layout.</p>
        </div>
        <aside class="selection-panel<?= $selection ? ' has-selection' : '' ?>" aria-live="polite">
            <span class="eyebrow">Your selected space</span>
            <div id="selection-empty" <?= $selection ? 'hidden' : '' ?>><h3 class="h4">Find your space.</h3><p class="small">Select an available slot to inspect its details.</p></div>
            <div id="selection-details" <?= !$selection ? 'hidden' : '' ?>>
                <div class="selection-top"><h3 id="selected-slot"><?= e($selection['label'] ?? '') ?></h3><span class="status-pill ok">Available</span></div>
                <dl class="kv"><div><dt>Floor</dt><dd id="selected-floor"><?= e($availability['floor']['label']) ?></dd></div><div><dt>Location</dt><dd id="selected-location"><?= e($facility['name']) ?></dd></div><div><dt>Initial rate</dt><dd id="selected-rate"><?php if ($availability['rate']): ?><?= e(ui_peso((int) $availability['rate']['initial_centavos'])) ?> / <?= (int) $availability['rate']['initial_minutes'] ?> min<?php else: ?>Not configured<?php endif; ?></dd></div></dl>
                <p class="form-note">Selecting a space creates no hold. Review the estimate and submit checkout to hold it temporarily. A driver account is required.</p>
                <div class="selection-actions">
                    <button class="button secondary" type="submit" form="map-query" id="clear-selection">Clear</button>
                    <?php if (($currentUser['role'] ?? 'driver') === 'driver'): ?><a class="button primary grow" id="review-reservation" href="<?= e(url('reservation-review', ['slot_id' => $selection['id'] ?? 0] + $window->query())) ?>" <?= !$availability['rate'] ? 'hidden' : '' ?>><?= $currentUser ? 'Review reservation' : 'Log in to continue' ?></a><?php endif; ?>
                </div>
            </div>
        </aside>
    </div>
    <noscript><p class="notice">The server-rendered map works without JavaScript. Choose a floor/time and submit to refresh, or select a space to see its details.</p></noscript>
    <?php endif; ?>
</section>
