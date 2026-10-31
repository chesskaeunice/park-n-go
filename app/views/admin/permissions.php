<?php
require_once BASE_PATH . '/app/views/partials/admin.php';
$data = $pageData; $manager = $data['permissions']['manage_permissions'];
?>
<section class="page-head"><span class="eyebrow">Access control</span><h1 class="h1">Admin permissions</h1><p class="small muted">Every administrator can monitor operations. Pricing changes, refund approvals, and active-session exceptions each need an explicit permission. Nobody can change their own permissions.</p></section>
<?= admin_errors($data['errors']) ?>
<?php if (!$manager): ?><div class="status-note"><?= ui_icon('lock', 20) ?><span>Changing permissions needs the Manage permissions permission. The first grant is made by an operator with <code>php database/admin-permission.php</code>.</span></div><?php endif; ?>
<section class="content-card"><div class="table-scroll"><table><thead><tr><th scope="col">Administrator</th><?php foreach ($data['labels'] as $label): ?><th scope="col"><?= e($label) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($data['admins'] as $admin): $self = (int) $admin['id'] === (int) $currentUser['id']; ?><tr><td><b><?= e($admin['name']) ?></b><br><small><?= e($admin['email']) ?><?= $admin['status'] !== 'active' ? ' · disabled' : '' ?><?= $self ? ' · you' : '' ?></small></td>
<?php foreach ($data['labels'] as $permission => $label): $granted = isset($admin['permissions'][$permission]); ?><td><?= ui_status($granted ? 'active' : 'none') ?>
<?php if ($manager && !$self && $admin['status'] === 'active'): ?><form class="inline-form" action="<?= e(url('admin-permissions')) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $admin['id'] ?>"><input type="hidden" name="permission" value="<?= e($permission) ?>"><input type="hidden" name="action" value="<?= $granted ? 'revoke' : 'grant' ?>">
<input name="reason" required minlength="10" maxlength="1000" placeholder="Reason" aria-label="Reason to <?= $granted ? 'revoke' : 'grant' ?> <?= e($label) ?> for <?= e($admin['name']) ?>"><button class="pill-button" type="submit"><?= $granted ? 'Revoke' : 'Grant' ?></button></form><?php endif; ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table></div></section>
