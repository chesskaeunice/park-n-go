<?php
$data = $pageData['ticketData']; $reservation = $data['reservation']; $ticket = $data['ticket'];
$manila = new DateTimeZone('Asia/Manila');
$entryStart = (new DateTimeImmutable($reservation['starts_at'], new DateTimeZone('UTC')))->setTimezone($manila);
$entryEnd = $entryStart->modify('+30 minutes');
?>
<div class="okbar<?= $ticket ? '' : ' warn' ?>"><?= ui_icon($ticket ? 'check-circle' : 'clock', 20) ?><span><?= $ticket ? 'Payment verified · Booking confirmed' : 'Waiting for payment verification' ?></span></div>
<article class="ticket-card">
    <div class="ticket-head">
        <span class="eyebrow">Secure parking access</span>
        <h1 class="h3"><?= e($reservation['facility_name']) ?></h1>
        <p class="small muted"><?= e($reservation['floor_label'] . ' · ' . $reservation['slot_label']) ?></p>
    </div>
    <?php if ($ticket): ?>
    <div class="qr-ticket"><img src="<?= e((new \ParkNGo\Services\Qr\TicketService())->qrDataUri($ticket['token'])) ?>" alt="Secure parking ticket QR code" width="220" height="220"></div>
    <details class="manual-code"><summary>Show manual fallback code</summary><p><code><?= e($ticket['manual_code']) ?></code></p><p class="form-note">Refreshing this page rotates the bearer credential.</p></details>
    <?php else: ?>
    <div class="ticket-pending"><span class="state-icon"><?= ui_icon('qr', 30) ?></span><h2 class="h4">Not ready yet</h2><p class="small">Your ticket will appear only after Maya reports a verified reservation payment. A return page or pending payment cannot unlock entry.</p></div>
    <?php endif; ?>
    <div class="perforation" aria-hidden="true"><span></span></div>
    <dl class="ticket-grid">
        <div><dt>Reference</dt><dd><?= e(short_reference($reservation['reference'])) ?></dd></div>
        <div><dt>Entry window</dt><dd><?= e($entryStart->format('M j · g:i')) ?>–<?= e($entryEnd->format('g:i A')) ?></dd></div>
        <div><dt>Reservation status</dt><dd><?= e(ucfirst($reservation['status'])) ?></dd></div>
        <div><dt>Ticket status</dt><dd><?= e($ticket ? 'Active' : 'Payment verification required') ?></dd></div>
    </dl>
</article>
<p class="form-note center">Show this QR code to an authorized attendant. It contains only an expiring opaque ticket token. Entry window shown in Manila time.</p>
<a class="button primary block" href="<?= e(url('reservations')) ?>">Done</a>
