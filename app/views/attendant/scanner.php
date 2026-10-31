<?php $direction = $page === 'entry-scan' ? 'Entry' : 'Exit'; ?>
<section class="page-head">
    <span class="eyebrow">Attendant workspace</span>
    <h1 class="h1">Validate <?= e(strtolower($direction)) ?> ticket</h1>
    <p class="small muted">Upload a screenshot or photo of the driver's QR ticket. The image is decoded locally, then the ticket is revalidated server-side.</p>
</section>
<article class="card scanner-card">
    <h2 class="h4">Upload QR ticket</h2>
    <?php if ($pageData['errors']): ?><div class="form-errors" role="alert"><?php foreach ($pageData['errors'] as $error): ?><p><?= e(ui_error_message($error)) ?></p><?php endforeach; ?></div><?php endif; ?>
    <?php if ($pageData['result']): ?><div class="status-note ok"><?= ui_icon('check-circle', 22) ?><p>Ticket validated. <?php if ($direction === 'Entry'): ?>Paid until <?= e($pageData['result']['paid_until']) ?> UTC.<?php else: ?>The session is completed and the slot is released.<?php endif; ?></p></div><?php endif; ?>
    <form class="account-form" method="post" action="<?= e(url($page)) ?>" data-qr-upload-form><?= csrf_field() ?>
        <input type="hidden" name="code" data-qr-upload-code>
        <label for="ticket-qr-image">QR image</label>
        <input id="ticket-qr-image" type="file" accept="image/png,image/jpeg,image/webp" data-qr-upload-file>
        <p class="form-note" data-qr-upload-status role="status">The image is decoded locally and is not uploaded.</p>
        <label for="ticket-manual-code">Or enter the manual ticket code</label>
        <input id="ticket-manual-code" data-qr-upload-manual maxlength="80" autocomplete="off" autocapitalize="characters">
        <button class="button primary block" type="submit">Validate <?= e($direction) ?> ticket</button>
    </form>
</article>
<article class="card"><h2 class="h4">Safety checks</h2><p class="small">Facility authorization, verified payment, entry window, duplicate scans, active-session state, and overtime are checked by the server.</p></article>
<script src="assets/js/jsQR.min.js"></script><script src="assets/js/qr-upload.js"></script>
