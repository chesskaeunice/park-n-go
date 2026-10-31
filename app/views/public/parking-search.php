<?php if ($pageData['errors']): ?><div class="form-errors" role="alert"><ul><?php foreach ($pageData['errors'] as $error): ?><li><?= e(ui_error_message($error)) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php $filter = $window->query(); $isFacility = $page === 'facility'; ?>
<form id="map-query" class="search-panel<?= $isFacility ? ' card plan-card' : '' ?>" action="index.php" method="get">
    <input type="hidden" name="page" value="<?= e($page) ?>">
    <?php if ($isFacility): ?>
        <input type="hidden" name="facility_id" value="<?= (int) $pageData['facility']['id'] ?>">
        <?php if ($pageData['floors']): ?>
        <fieldset class="chip-group">
            <legend class="field-label">Floor</legend>
            <div class="chips-scroll">
                <?php foreach ($pageData['floors'] as $floor): ?>
                <label class="chip-radio"><input type="radio" name="floor_id" value="<?= (int) $floor['id'] ?>" <?= (int) $floor['id'] === $pageData['floorId'] ? 'checked' : '' ?>><span><?= e($floor['label']) ?></span></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php endif; ?>
    <?php else: ?>
        <label class="sr-only" for="facility-q">Location or facility</label>
        <div class="search-row">
            <div class="input-icon grow"><?= ui_icon('search') ?><input id="facility-q" type="search" name="q" maxlength="100" value="<?= e($pageData['query']) ?>" placeholder="Search parking locations"></div>
            <button class="square-button" type="submit" aria-label="Find parking"><?= ui_icon('arrow-right') ?></button>
        </div>
    <?php endif; ?>
    <details class="plan-details"<?= $isFacility || $filter['date'] !== '' || $filter['time'] !== '' ? ' open' : '' ?>>
        <summary><?= ui_icon('calendar', 18) ?><span>Arrival and duration</span><?= ui_icon('chevron-right', 18, 'summary-chevron') ?></summary>
        <div class="plan-fields">
            <div class="field-row">
                <div><label class="field-label" for="arrival-date">Arrival date</label><input id="arrival-date" type="date" name="date" value="<?= e($filter['date']) ?>"></div>
                <div><label class="field-label" for="arrival-time">Arrival time</label><input id="arrival-time" type="time" name="time" value="<?= e($filter['time']) ?>"></div>
            </div>
            <label class="field-label" for="duration">Duration</label>
            <select id="duration" name="hours"><?php foreach ([1,2,3,4,5,6,8,12,24] as $hours): ?><option value="<?= $hours ?>" <?= $window->hours === $hours ? 'selected' : '' ?>><?= $hours ?> hour<?= $hours === 1 ? '' : 's' ?></option><?php endforeach; ?><?php if (!in_array($window->hours, [1,2,3,4,5,6,8,12,24], true)): ?><option selected value="<?= $window->hours ?>"><?= $window->hours ?> hours</option><?php endif; ?></select>
            <button class="button primary block" type="submit"><?= $isFacility ? 'Check availability' : 'Find Parking' ?></button>
        </div>
    </details>
</form>
<p class="form-note"><?= e($window->label()) ?>. Leave both arrival fields empty to check now. A selection does not reserve a space.</p>
