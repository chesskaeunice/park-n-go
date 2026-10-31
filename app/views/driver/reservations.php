<?php $manila = new DateTimeZone('Asia/Manila'); ?>
<section class="page-head page-head-row">
    <div><span class="eyebrow">Your parking plans</span><h1 class="h1">My Bookings</h1></div>
    <a class="icon-button filled" href="<?= e(url('facilities')) ?>" aria-label="Find parking"><?= ui_icon('search', 22) ?></a>
</section>
<p class="small muted page-sub">Review pending checkout holds and your reservation history.</p>
<section class="booking-section">
<?php if ($pageData['errors']): ?><div class="form-errors" role="alert"><?php foreach ($pageData['errors'] as $error): ?><p><?= e(ui_error_message($error)) ?></p><?php endforeach; ?></div>
<?php elseif (!$pageData['rows']): ?><div class="empty-state"><span class="state-icon"><?= ui_icon('ticket', 28) ?></span><h2 class="h3">No reservations on this page</h2><p>Choose a facility and space to start a checkout hold.</p><a class="button primary" href="<?= e(url('facilities')) ?>">Find parking</a></div>
<?php else: ?>
<ul class="booking-list">
<?php foreach (array_slice($pageData['rows'], 0, 20) as $reservation):
    $start = (new DateTimeImmutable($reservation['starts_at'], new DateTimeZone('UTC')))->setTimezone($manila);
    $end = (new DateTimeImmutable($reservation['ends_at'], new DateTimeZone('UTC')))->setTimezone($manila); ?>
    <li>
        <a class="booking-card" href="<?= e(url('reservation-checkout', ['id' => $reservation['id']])) ?>">
            <div class="booking-top">
                <?= ui_lot((int) $reservation['id'], 'booking-thumb', true) ?>
                <div class="grow"><h2 class="h4"><?= e($reservation['facility_name']) ?></h2><?= ui_status((string) $reservation['status']) ?></div>
            </div>
            <div class="booking-meta">
                <p><?= ui_icon('calendar', 15) ?><span><?= e($start->format('D, M j, Y · g:i A')) ?> – <?= e($end->format('g:i A')) ?></span></p>
                <p><?= ui_icon('layers', 15) ?><span>Slot <?= e($reservation['slot_label']) ?> · <?= e(short_reference($reservation['reference'])) ?></span></p>
            </div>
            <div class="booking-bottom"><span class="price-sm"><?= e(ui_peso((int) $reservation['estimated_centavos'])) ?></span><span class="pill-outline">View details</span></div>
        </a>
    </li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<nav class="pager" aria-label="Reservation history pages"><?php if ($pageData['pageNumber'] > 1): ?><a class="button secondary" href="<?= e(url('reservations', ['p' => $pageData['pageNumber'] - 1])) ?>"><?= ui_icon('chevron-left', 18) ?>Previous</a><?php endif; ?><?php if (count($pageData['rows']) > 20): ?><a class="button secondary" href="<?= e(url('reservations', ['p' => $pageData['pageNumber'] + 1])) ?>">Next<?= ui_icon('chevron-right', 18) ?></a><?php endif; ?></nav>
</section>
