<?php
$payment = $pageData['payment'];
$status = (string) $payment['local_status'];
$tone = $status === 'verified' ? 'ok' : (in_array($status, ['failed', 'expired', 'cancelled', 'reconciliation'], true) ? 'off' : 'warn');
$statusIcon = $tone === 'ok' ? 'check-circle' : ($tone === 'off' ? 'alert' : 'clock');
?>
<section class="status-hero tone-<?= $tone ?>">
    <span class="status-icon"><?= ui_icon($statusIcon, 34) ?></span>
    <span class="eyebrow">MAYA CHECKOUT</span>
    <h1 class="h2"><?= e(ucwords(str_replace('_', ' ', $status))) ?></h1>
    <p class="small muted">Reference: <?= e(short_reference($payment['reference'])) ?></p>
</section>
<?php if ($pageData['errors'] ?? []): ?><div class="form-errors" role="alert"><?php foreach ($pageData['errors'] as $error): ?><p><?= e(ui_error_message($error)) ?></p><?php endforeach; ?></div><?php endif; ?>
<article class="card">
    <?php $type = (string) ($payment['payment_type'] ?? 'INITIAL_RESERVATION'); ?>
    <?php if ($status === 'verified' && $type === 'PARKING_EXTENSION'): ?><p class="notice-text">Payment verified. Your parking session has been extended; keep using the same QR ticket.</p><?php elseif ($status === 'verified' && $type === 'OVERTIME_SETTLEMENT'): ?><p class="notice-text">Overtime payment verified. You have 15 minutes from verification to exit with the same QR ticket.</p><?php elseif ($status === 'verified'): ?><p class="notice-text">Payment verified. Your reservation can now be used according to its confirmed status.</p><?php elseif ($status === 'reconciliation'): ?><p class="notice-text">Payment requires administrative reconciliation. Do not retry repeatedly.</p><?php else: ?><p class="notice-text">Payment is still awaiting independent server verification. Refresh this page later.</p><?php endif; ?>
    <?php if (!in_array($status, ['verified','reconciliation','failed','expired','cancelled'], true)): ?><form class="action-form" method="post" action="<?= e(url('payment-refresh')) ?>"><?= csrf_field() ?><input type="hidden" name="reference" value="<?= e($payment['reference']) ?>"><button class="button primary block" type="submit"><?= ui_icon('refresh') ?>Refresh payment status</button></form><?php endif; ?>
    <?php if ($status === 'verified' && $payment['reservation_id']): ?><a class="button primary block" href="<?= e(url('ticket', ['id' => $payment['reservation_id']])) ?>"><?= ui_icon('qr') ?>View secure QR ticket</a><?php elseif ($payment['reservation_id']): ?><a class="link center-link" href="<?= e(url('reservation-checkout', ['id' => $payment['reservation_id']])) ?>">Return to reservation</a><?php elseif ($payment['parking_session_id']): ?><a class="link center-link" href="<?= e(url('dashboard')) ?>">Return to your parking session</a><?php endif; ?>
</article>
<p class="form-note center">Park n Go never confirms a reservation from a browser redirect. The server must verify the Maya transaction first.</p>
