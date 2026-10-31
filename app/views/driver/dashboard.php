<?php
$facilities = $pageData['facilities'] ?? [];
$upcoming = $pageData['upcoming'] ?? null;
$activeSession = $pageData['activeSession'] ?? null;
$overtime = $pageData['overtime'] ?? null;
$paymentError = $pageData['paymentError'] ?? null;
$manila = new DateTimeZone('Asia/Manila');
$hour = (int) (new DateTimeImmutable('now', $manila))->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$local = static fn(string $utc): DateTimeImmutable => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($manila);
?>
<section class="greeting">
    <span class="avatar"><?= e(ui_initials((string) $currentUser['name'])) ?></span>
    <div><p class="small muted"><?= e($greeting) ?>,</p><p class="h3"><?= e($currentUser['name']) ?></p></div>
</section>
<h1 class="h1 home-title">Where are you<br>headed today?</h1>
<form class="search-row" action="index.php" method="get">
    <input type="hidden" name="page" value="facilities">
    <label class="sr-only" for="dash-q">Search parking</label>
    <div class="input-icon grow"><?= ui_icon('search') ?><input id="dash-q" type="search" name="q" placeholder="Search location or facility" maxlength="100"></div>
    <button class="square-button" type="submit" aria-label="Search parking"><?= ui_icon('sliders') ?></button>
</form>

<?php if ($upcoming): $start = $local($upcoming['starts_at']); $end = $local($upcoming['ends_at']); $confirmed = $upcoming['status'] === 'confirmed'; ?>
<a class="upcoming-card" href="<?= e(url($confirmed ? 'ticket' : 'reservation-checkout', ['id' => $upcoming['id']])) ?>">
    <div class="upcoming-top"><span class="pill-glass"><?= $confirmed ? 'Upcoming' : 'Awaiting payment' ?> · <?= e($start->format('D, M j')) ?></span><?= ui_icon('ticket', 22) ?></div>
    <div><p class="h3"><?= e($upcoming['facility_name']) ?></p><p class="upcoming-meta">Slot <?= e($upcoming['slot_label']) ?> · <?= e($start->format('g:i A')) ?> – <?= e($end->format('g:i A')) ?></p></div>
    <div class="upcoming-bottom"><span class="upcoming-meta"><?= ui_icon('ticket', 16) ?><span><?= e(short_reference($upcoming['reference'])) ?></span></span><span class="pill-solid"><?= $confirmed ? 'View QR Ticket' : 'Complete payment' ?></span></div>
</a>
<?php else: ?>
<section class="upcoming-card is-empty">
    <div class="upcoming-top"><span class="pill-glass">No upcoming bookings</span><?= ui_icon('ticket', 22) ?></div>
    <p class="h3">Your next space awaits.</p>
    <p class="upcoming-meta">Choose a space, review its estimated fee, create a temporary hold, and complete Maya Sandbox payment verification.</p>
    <div class="upcoming-bottom"><span></span><a class="pill-solid" href="<?= e(url('facilities')) ?>">Explore Parking</a></div>
</section>
<?php endif; ?>

<?php if ($paymentError): ?><div class="form-errors" role="alert"><p><?= e(ui_error_message($paymentError)) ?></p></div><?php endif; ?>
<?php if (!empty($activeSession)): $inGrace = !empty($overtime['grace_until']) && empty($overtime['payment_required']) && $overtime['grace_until'] > $overtime['paid_until'] && $overtime['grace_until'] >= $overtime['server_now']; ?>
<section class="upcoming-card" id="active-session">
    <div class="upcoming-top"><span class="pill-glass">Active parking session</span><?= ui_icon('car', 22) ?></div>
    <p class="h3"><?= e($activeSession['facility_name']) ?></p>
    <p class="upcoming-meta">Slot <?= e($activeSession['slot_label']) ?> · Paid until <?= e($local($activeSession['paid_until'])->format('M j, g:i:s A')) ?></p>
    <?php if (!empty($overtime['payment_required'])): ?>
        <p class="form-note">Overtime due: <?= $overtime['estimated_centavos'] === null ? 'amount unavailable' : e(ui_peso((int)$overtime['estimated_centavos'])) ?>. Exit remains blocked until verified. Settle overtime before extending.</p>
        <?php if ($overtime['estimated_centavos'] !== null): ?><form action="<?= e(url('overtime-start')) ?>" method="post"><?= csrf_field() ?><input type="hidden" name="session_id" value="<?= (int)$activeSession['id'] ?>"><button class="button primary block" type="submit">Pay overtime</button></form><?php endif; ?>
    <?php else: ?>
        <p class="form-note"><?= $inGrace ? 'Overtime settled. Exit before ' . e($local($overtime['grace_until'])->format('g:i:s A')) . ' or extend now; an extension replaces the remaining exit grace.' : 'Your session is within its paid period.' ?></p>
        <form action="<?= e(url('extension-start')) ?>" method="post" class="account-form"><input type="hidden" name="session_id" value="<?= (int)$activeSession['id'] ?>"><?= csrf_field() ?><fieldset><legend>Extend parking with approved blocks</legend><label><input type="checkbox" name="blocks[]" value="1"> 1 hour</label><label><input type="checkbox" name="blocks[]" value="2"> 2 hours</label><label><input type="checkbox" name="blocks[]" value="3"> 3 hours</label></fieldset><p class="form-note">Choose blocks totaling up to 6 hours<?= (int) $activeSession['succeeding_hour_centavos'] > 0 ? ' · ' . e(ui_peso((int) $activeSession['succeeding_hour_centavos'])) . ' per hour' : '' ?>. The server calculates the final amount.</p><button class="button secondary block" type="submit">Pay extension</button></form>
    <?php endif; ?>
</section>
<?php endif; ?>

<nav class="quick-actions" aria-label="Shortcuts">
    <a href="<?= e(url('facilities')) ?>"><span class="qa-icon"><?= ui_icon('compass', 22) ?></span>Explore</a>
    <a href="<?= e(url('reservations')) ?>"><span class="qa-icon"><?= ui_icon('ticket', 22) ?></span>Bookings</a>
    <a href="<?= e(url('how-it-works')) ?>"><span class="qa-icon"><?= ui_icon('book', 22) ?></span>Guide</a>
    <a href="<?= e(url('profile')) ?>"><span class="qa-icon"><?= ui_icon('user', 22) ?></span>Profile</a>
</nav>

<div class="section-head"><h2 class="h3">Parking for you</h2><a class="link" href="<?= e(url('facilities')) ?>">See all</a></div>
<?php if ($facilities): ?>
<div class="carousel">
    <?php foreach ($facilities as $facility): ?>
    <a class="spot-card" href="<?= e(url('facility', ['facility_id' => $facility['id']])) ?>">
        <?= ui_lot((int) $facility['id'], 'spot-art') ?>
        <div class="spot-info">
            <h3 class="h4"><?= e($facility['name']) ?></h3>
            <p class="meta"><?= ui_icon('pin', 14) ?><span><?= e($facility['city']) ?> · <?= (int) $facility['floor_count'] ?> floor<?= (int) $facility['floor_count'] === 1 ? '' : 's' ?></span></p>
            <?php if ($facility['initial_centavos'] !== null): ?><p class="price-sm"><?= e(ui_peso((int) $facility['initial_centavos'])) ?> <small>/ first <?= (int) $facility['initial_minutes'] ?> min</small></p><?php else: ?><p class="small muted">Rate not configured</p><?php endif; ?>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="empty-state"><p>No parking facilities are active yet.</p></div>
<?php endif; ?>
