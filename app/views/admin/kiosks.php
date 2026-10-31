<?php $data = $pageData; ?>
<section class="page-head"><span class="eyebrow">Exit kiosk access</span><h1 class="h1">Manage kiosks</h1><p class="small muted">Register a facility-bound browser for the future exit workflow. QR upload and exit verification are not available yet.</p></section>
<section class="content-card">
<?php if ($data['errors']): ?><div class="form-errors" role="alert"><ul><?php foreach ($data['errors'] as $error): ?><li><?= e(ui_error_message($error)) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($data['notice']): ?><div class="form-success" role="status"><strong>Activation code for <?= e($data['notice']['name']) ?></strong><p>Enter this one-time code on the designated kiosk browser within 10 minutes:</p><code><?= e($data['notice']['code']) ?></code></div><?php endif; ?>
<form class="account-form" action="<?= e(url('admin-kiosks')) ?>" method="post">
<?= csrf_field() ?><input type="hidden" name="action" value="register">
<label for="kiosk-facility">Facility</label><select id="kiosk-facility" name="facility_id" required><option value="">Choose a facility</option><?php foreach ($data['facilities'] as $facility): ?><option value="<?= (int) $facility['id'] ?>"><?= e($facility['name']) ?></option><?php endforeach; ?></select>
<label for="kiosk-name">Kiosk name</label><input id="kiosk-name" name="name" maxlength="100" required autocomplete="off"><button class="button primary block" type="submit">Register kiosk</button>
</form></section>
<section class="content-card"><h2 class="h4">Registered kiosks</h2><div class="table-scroll"><table><thead><tr><th>Name</th><th>Facility</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach ($data['kiosks'] as $kiosk): ?><tr><td><?= e($kiosk['name']) ?></td><td><?= e($kiosk['facility_name']) ?></td><td><?= e(ucfirst($kiosk['status'])) ?></td><td><?php if ($kiosk['status'] !== 'revoked'): ?><form action="<?= e(url('admin-kiosks')) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><input type="hidden" name="kiosk_id" value="<?= (int) $kiosk['id'] ?>"><button class="button secondary" type="submit">Revoke</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$data['kiosks']): ?><tr><td colspan="4">No kiosks registered.</td></tr><?php endif; ?></tbody></table></div></section>
