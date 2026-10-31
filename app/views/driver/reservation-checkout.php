<section class="page-head">
    <h1 class="h2">Reservation checkout</h1>
    <p class="small muted">A temporary hold keeps your selected space while checkout is pending.</p>
</section>
<section class="booking-section">
<?php if ($pageData['errors']): ?><div class="form-errors" role="alert"><?php foreach ($pageData['errors'] as $error): ?><p><?= e(ui_error_message($error)) ?></p><?php endforeach; ?></div>
<?php elseif ($reservation = $pageData['reservation']):
$snapshot = json_decode($reservation['rate_snapshot'], true, 64, JSON_THROW_ON_ERROR);
$label = static fn(string $timestamp): string => (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y · g:i A');
$live = $reservation['status'] === 'pending' && $reservation['expires_at'] && $reservation['released_at'] === null && $reservation['expires_at'] > $reservation['server_now'];
$holdSeconds = (int) ($pageData['config']['hold_minutes'] ?? 10) * 60;
?>
    <?php if ($live): ?>
    <section class="card hold-card">
        <div class="hold-ring" data-hold-ring aria-hidden="true">
            <svg viewBox="0 0 132 132"><circle class="ring-track" cx="66" cy="66" r="56"/><circle class="ring-progress" cx="66" cy="66" r="56" pathLength="100"/></svg>
            <div class="ring-center"><span class="ring-time" data-hold-ring-time>--:--</span><span class="ring-caption">time left</span></div>
        </div>
        <h2 class="h3">Your space is temporarily held.</h2>
        <p class="small muted">Hold expires at <?= e($label($reservation['expires_at'])) ?>.</p>
        <p class="hold-clock" data-hold-expires="<?= e(str_replace(' ', 'T', $reservation['expires_at']) . 'Z') ?>" data-server-now="<?= e(str_replace(' ', 'T', $reservation['server_now']) . 'Z') ?>" data-hold-total="<?= $holdSeconds ?>">Time remaining is checked by the server.</p>
    </section>
    <?php endif; ?>
    <article class="card">
        <div class="card-top"><div><span class="eyebrow">Reference</span><h2 class="h4 reference"><?= e(short_reference($reservation['reference'])) ?></h2></div><?= ui_status((string) $reservation['status']) ?></div>
        <p class="small"><?= e($snapshot['location']['facility'] ?? $reservation['facility_name']) ?><br><?= e(($snapshot['location']['floor'] ?? $reservation['floor_label']) . ' · ' . ($snapshot['location']['slot'] ?? $reservation['slot_label'])) ?></p>
        <dl class="kv">
            <div><dt>Arrival</dt><dd><?= e($label($reservation['starts_at'])) ?></dd></div>
            <div><dt>Departure estimate</dt><dd><?= e($label($reservation['ends_at'])) ?></dd></div>
            <div class="kv-total"><dt>Estimated total</dt><dd><?= e(ui_peso((int) $reservation['estimated_centavos'])) ?></dd></div>
        </dl>
        <p class="form-note">All times are Manila time. The estimate and cancellation rules were saved when this reservation was created.</p>
    </article>
    <?php if ($live): ?>
    <div class="pay-heading h4">Pay with</div>
    <div class="pay-option" aria-hidden="true"><span class="pay-icon"><?= ui_icon('wallet', 22) ?></span><span class="grow"><b>Maya Checkout</b><small>E-wallet, cards and bank transfer</small></span><span class="radio-dot"></span></div>
    <form class="action-form" method="post" action="<?= e(url('payment-start')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $reservation['id'] ?>"><button class="button primary block" type="submit">Proceed to Maya Checkout</button></form>
    <a class="link center-link" href="<?= e(url('reservation-checkout', ['id' => $reservation['id']])) ?>"><?= ui_icon('refresh', 16) ?>Refresh status</a>
    <?php elseif ($reservation['status'] === 'expired'): ?><div class="status-note"><?= ui_icon('clock', 22) ?><p>The unpaid hold expired. The space is available for other eligible bookings. Choose a new space to try again.</p></div>
    <?php elseif ($reservation['status'] === 'cancelled'): ?><div class="status-note"><?= ui_icon('x-circle', 22) ?><p>This reservation was cancelled. Its temporary hold has been released.</p></div>
    <?php elseif ($reservation['status'] === 'confirmed'): ?><div class="status-note ok"><?= ui_icon('check-circle', 22) ?><p>This reservation is payment-confirmed.</p></div><a class="button primary block" href="<?= e(url('ticket', ['id' => $reservation['id']])) ?>"><?= ui_icon('qr') ?>View secure ticket</a>
    <?php else: ?><div class="status-note"><?= ui_icon('info', 22) ?><p>This reservation is <?= e($reservation['status']) ?>. No live unpaid hold is shown.</p></div><?php endif; ?>
    <?php if (in_array($reservation['status'], ['pending', 'confirmed'], true)): ?><form class="action-form" method="post" action="<?= e(url('reservation-cancel')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $reservation['id'] ?>"><button class="button secondary block" type="submit">Cancel reservation</button></form><?php endif; ?>
    <a class="link center-link" href="<?= e(url('facilities')) ?>">Explore parking</a>
<?php endif; ?>
</section>
