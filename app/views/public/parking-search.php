<?php if ($pageData['errors']): ?><div class="form-errors" role="alert"><ul><?php foreach ($pageData['errors'] as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form id="map-query" class="search-form facility-search availability-search" action="index.php" method="get">
    <input type="hidden" name="page" value="<?= e($page) ?>">
    <?php if ($page === 'facility'): ?>
        <input type="hidden" name="facility_id" value="<?= (int) $pageData['facility']['id'] ?>">
        <label><span>Floor</span><select name="floor_id" id="floor"><?php foreach ($pageData['floors'] as $floor): ?><option value="<?= (int) $floor['id'] ?>" <?= (int) $floor['id'] === $pageData['floorId'] ? 'selected' : '' ?>><?= e($floor['label']) ?></option><?php endforeach; ?></select></label>
    <?php else: ?>
        <label class="location-field"><span>Location or facility</span><input type="search" name="q" maxlength="100" value="<?= e($pageData['query']) ?>" placeholder="Search parking locations"></label>
    <?php endif; ?>
    <?php $filter = $window->query(); ?>
    <label><span>Arrival date</span><input type="date" name="date" value="<?= e($filter['date']) ?>"></label>
    <label><span>Arrival time</span><input type="time" name="time" value="<?= e($filter['time']) ?>"></label>
    <label><span>Duration</span><select name="hours"><?php foreach ([1,2,3,4,5,6,8,12,24] as $hours): ?><option value="<?= $hours ?>" <?= $window->hours === $hours ? 'selected' : '' ?>><?= $hours ?> hour<?= $hours === 1 ? '' : 's' ?></option><?php endforeach; ?><?php if (!in_array($window->hours, [1,2,3,4,5,6,8,12,24], true)): ?><option selected value="<?= $window->hours ?>"><?= $window->hours ?> hours</option><?php endif; ?></select></label>
    <button class="button primary" type="submit"><?= $page === 'facility' ? 'Check availability' : 'Find Parking' ?></button>
</form>
<p class="form-note"><?= e($window->label()) ?>. Leave both arrival fields empty to check now. A selection does not reserve a space.</p>
