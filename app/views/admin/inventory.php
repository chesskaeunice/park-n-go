<?php
$inventory = $pageData; $type = $inventory['type']; $old = $inventory['old'];
$value = static fn(string $key, string $default = ''): string => is_scalar($old[$key] ?? null) ? (string) $old[$key] : $default;
$context = $type === 'floor' ? ['facility_id' => $inventory['facilityId']] : ($type === 'slot' ? ['floor_id' => $inventory['floorId']] : []);
?>
<section class="shell page-intro"><a class="text-link" href="<?= e(url('admin-dashboard')) ?>">Back to administrator dashboard</a><h1><?= e($title) ?></h1><p>Add or update parking inventory. Disable records to preserve their history; existing records are never deleted.</p><nav class="inventory-tabs" aria-label="Inventory management"><a href="<?= e(url('admin-facilities')) ?>" <?= $type === 'facility' ? 'aria-current="page"' : '' ?>>Facilities</a><a href="<?= e(url('admin-floors')) ?>" <?= $type === 'floor' ? 'aria-current="page"' : '' ?>>Floors</a><a href="<?= e(url('admin-slots')) ?>" <?= $type === 'slot' ? 'aria-current="page"' : '' ?>>Slots</a></nav></section>
<section class="shell inventory-section">
<?php if ($inventory['errors']): ?><div class="form-errors" role="alert"><ul><?php foreach ($inventory['errors'] as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($type !== 'facility'): ?>
<form class="search-form inventory-filter" action="index.php" method="get"><input type="hidden" name="page" value="<?= e($page) ?>"><label><span>Facility</span><select name="facility_id"><?php foreach ($inventory['facilities'] as $facility): ?><option value="<?= (int) $facility['id'] ?>" <?= (int) $facility['id'] === $inventory['facilityId'] ? 'selected' : '' ?>><?= e($facility['name']) ?><?= !$facility['is_active'] ? ' (disabled)' : '' ?></option><?php endforeach; ?></select></label><button class="button secondary" type="submit">View facility</button></form>
<?php if ($type === 'slot' && $inventory['floors']): ?><form class="search-form inventory-filter" action="index.php" method="get"><input type="hidden" name="page" value="<?= e($page) ?>"><label><span>Floor</span><select name="floor_id"><?php foreach ($inventory['floors'] as $floor): ?><option value="<?= (int) $floor['id'] ?>" <?= (int) $floor['id'] === $inventory['floorId'] ? 'selected' : '' ?>><?= e($floor['label']) ?><?= !$floor['is_active'] ? ' (disabled)' : '' ?></option><?php endforeach; ?></select></label><button class="button secondary" type="submit">View floor</button></form><?php endif; ?>
<?php endif; ?>
<div class="inventory-grid"><div class="content-card"><h2><?= $inventory['editing'] ? 'Edit' : 'Add' ?> <?= e($type) ?></h2>
<?php if (($type === 'floor' && !$inventory['facilityId']) || ($type === 'slot' && !$inventory['floorId'])): ?><p>Create a facility and floor before adding this inventory.</p>
<?php else: ?>
<form class="account-form" action="<?= e(url($page, $context + (isset($_GET['edit']) && $inventory['editing'] ? ['edit' => $inventory['editing']['id']] : []))) ?>" method="post">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($value('id', '0')) ?>">
    <?php if ($type === 'facility'): ?>
    <label for="inventory-name">Facility name</label><input id="inventory-name" name="name" minlength="2" maxlength="150" required value="<?= e($value('name')) ?>">
    <label for="inventory-address">Address</label><input id="inventory-address" name="address" minlength="2" maxlength="255" required value="<?= e($value('address')) ?>">
    <label for="inventory-city">City</label><input id="inventory-city" name="city" minlength="2" maxlength="100" required value="<?= e($value('city')) ?>">
    <label for="inventory-description">Description</label><textarea id="inventory-description" name="description" maxlength="2000" rows="4"><?= e($value('description')) ?></textarea><small>New facilities show “Rate not configured” until a rate is installed; payment/rate management is a later phase.</small>
    <?php else: ?>
    <input type="hidden" name="<?= $type === 'floor' ? 'facility_id' : 'floor_id' ?>" value="<?= $type === 'floor' ? $inventory['facilityId'] : $inventory['floorId'] ?>">
    <label for="inventory-label"><?= $type === 'floor' ? 'Floor label' : 'Unique slot label' ?></label><input id="inventory-label" name="label" maxlength="<?= $type === 'floor' ? 50 : 24 ?>" required value="<?= e($value('label')) ?>">
    <?php if ($type === 'floor'): ?><label for="sort-order">Display order</label><input type="number" id="sort-order" name="sort_order" min="-100" max="100" required value="<?= e($value('sort_order', '0')) ?>">
    <?php else: ?>
    <label for="slot-status">Inventory status</label><select id="slot-status" name="status"><?php foreach (['available' => 'Available', 'maintenance' => 'Under maintenance', 'disabled' => 'Disabled'] as $status => $label): ?><option value="<?= e($status) ?>" <?= $value('status', 'available') === $status ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
    <label for="position-x">Map column (0–11)</label><input type="number" id="position-x" name="position_x" min="0" max="11" required value="<?= e($value('position_x', '0')) ?>"><label for="position-y">Map row (0–9)</label><input type="number" id="position-y" name="position_y" min="0" max="9" required value="<?= e($value('position_y', '0')) ?>"><small>Positions must be unique within a floor. Reserved/occupied/held are derived automatically, never assigned here.</small>
    <?php endif; endif; ?>
    <?php if ($type !== 'slot'): $active = $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($old['is_active']) : (bool) ($old['is_active'] ?? true); ?><label class="checkbox-label"><input type="checkbox" name="is_active" value="1" <?= $active ? 'checked' : '' ?>> Active and visible</label><?php endif; ?>
    <button class="button primary" type="submit">Save <?= e($type) ?></button>
    <?php if ($inventory['editing']): ?><a class="text-link" href="<?= e(url($page, $context)) ?>">Cancel editing</a><?php endif; ?>
</form><?php endif; ?></div>
<div class="content-card"><h2>Existing <?= $type === 'facility' ? 'facilities' : ($type === 'floor' ? 'floors' : 'slots') ?></h2><div class="table-scroll"><table><thead><tr><th scope="col">Name</th><th scope="col">Status</th><th scope="col">Manage</th></tr></thead><tbody>
<?php foreach ($inventory['records'] as $record): ?><tr><td><?= e($record[$type === 'facility' ? 'name' : 'label']) ?><?php if ($type === 'slot'): ?><br><small>Column <?= (int) $record['position_x'] ?>, row <?= (int) $record['position_y'] ?></small><?php endif; ?></td><td><?= e($type === 'slot' ? $record['status'] : ($record['is_active'] ? 'Active' : 'Disabled')) ?></td><td><a class="text-link" href="<?= e(url($page, $context + ['edit' => $record['id']])) ?>">Edit</a><?php if ($type !== 'slot'): ?><br><a class="text-link" href="<?= e(url($type === 'facility' ? 'admin-floors' : 'admin-slots', [$type === 'facility' ? 'facility_id' : 'floor_id' => $record['id']])) ?>"><?= $type === 'facility' ? 'Floors' : 'Slots' ?></a><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$inventory['records']): ?><tr><td colspan="3">No records yet. Add your first <?= e($type) ?> using the form.</td></tr><?php endif; ?>
</tbody></table></div></div></div></section>
