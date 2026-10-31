<?php $data = $pageData; ?>
<section class="page-head"><span class="eyebrow">Exit kiosk</span><h1 class="h1">Kiosk status</h1></section>
<section class="content-card">
<?php if (!$data['authorized']): ?><div class="form-errors" role="alert"><p>This kiosk is not authorized.</p><a class="text-link" href="<?= e(url('kiosk-activate')) ?>">Activate this kiosk</a></div>
<?php else: ?><p><strong><?= e($data['kiosk']['name']) ?></strong></p><p class="muted">Assigned facility: <?= e($data['kiosk']['facility_name']) ?></p><p class="status-pill">Active</p>
<?php if ($data['errors']): ?><div class="form-errors" role="alert"><ul><?php foreach ($data['errors'] as $error): ?><li><?= e(ui_error_message($error)) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($data['exit']): ?><div class="status-note ok" role="status"><strong>Exit confirmed</strong><p>The parking session is complete and the slot has been released.</p><p class="small muted">Facility: <?= e($data['exit']['facility_name']) ?></p></div><a class="button secondary block" href="<?= e(url('kiosk-home')) ?>">Verify another ticket</a>
<?php else: ?>
<form id="kiosk-ticket-form" class="account-form" action="<?= e(url('kiosk-verify')) ?>" method="post" data-qr-upload-form>
<?= csrf_field() ?><input type="hidden" name="code" value="" data-qr-upload-code>
<label for="kiosk-qr-image">Upload QR image</label><input id="kiosk-qr-image" type="file" accept="image/png,image/jpeg,image/webp" data-qr-upload-file><p class="form-note" role="status" data-qr-upload-status>The image is decoded locally and is not uploaded.</p>
<label for="kiosk-manual-code">Or enter the manual ticket code</label><input id="kiosk-manual-code" maxlength="80" autocomplete="off" autocapitalize="characters" data-qr-upload-manual><button class="button primary block" type="submit">Verify ticket</button>
</form>
<?php if ($data['verification']): ?><div class="status-note ok" role="status"><strong>Ticket verified</strong><p>Facility: <?= e($data['verification']['facility_name']) ?> · Slot <?= e($data['verification']['slot_label']) ?></p><p class="small muted">Entry: <?= e($data['verification']['entry_at']) ?> UTC · Paid until <?= e($data['verification']['paid_until']) ?> UTC</p><form class="account-form" action="<?= e(url('kiosk-exit')) ?>" method="post"><?= csrf_field() ?><button class="button primary block" type="submit">Confirm Exit</button></form></div><?php endif; ?>
<?php endif; ?><?php endif; ?>
</section>
<?php if ($data['authorized'] && !$data['exit']): ?><script src="assets/js/jsQR.min.js"></script><script src="assets/js/qr-upload.js"></script><?php endif; ?>
