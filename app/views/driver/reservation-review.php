<section class="page-head">
    <h1 class="h2">Review your reservation</h1>
    <p class="small muted">Check your arrival, parking space, and estimated cost before starting checkout.</p>
</section>
<section class="booking-section">
<?php if ($pageData['errors']): ?><div class="form-errors" role="alert"><ul><?php foreach ($pageData['errors'] as $error): ?><li><?= e(ui_error_message($error)) ?></li><?php endforeach; ?></ul></div><a class="button primary block" href="<?= e($pageData['reviewLink']) ?>">Open a fresh review</a>
<?php elseif ($quote = $pageData['quote']): $snapshot = $quote['snapshot']; $estimate = $quote['estimate']; $manila = new DateTimeZone('Asia/Manila'); ?>
    <div class="card facility-mini">
        <?= ui_lot((int) $quote['facility']['id'], 'mini-thumb', true) ?>
        <div><h2 class="h4"><?= e($quote['facility']['name']) ?></h2><p class="small muted"><?= e($quote['facility']['address']) ?> · <?= e($quote['facility']['city']) ?></p></div>
    </div>
    <article class="card">
        <h2 class="h4">Booking details</h2>
        <dl class="kv">
            <div><dt>Floor and space</dt><dd><?= e($quote['floor']['label'] . ' · ' . $quote['slot']['label']) ?></dd></div>
            <div><dt>Arrival</dt><dd><?= e($quote['window']->start->setTimezone($manila)->format('M j, Y · g:i A')) ?></dd></div>
            <div><dt>Estimated departure</dt><dd><?= e($quote['window']->end->setTimezone($manila)->format('M j, Y · g:i A')) ?></dd></div>
            <div><dt>Duration</dt><dd><?= $quote['window']->hours ?> hour<?= $quote['window']->hours === 1 ? '' : 's' ?></dd></div>
        </dl>
        <p class="form-note">All displayed times are Manila time. Availability is checked again when you submit. This review does not hold the space.</p>
        <a class="link" href="<?= e(url('facility', ['facility_id' => $quote['facility']['id'], 'floor_id' => $quote['floor']['id']] + $quote['window']->query())) ?>">Change parking selection<?= ui_icon('chevron-right', 16) ?></a>
    </article>
    <article class="card">
        <h2 class="h4">Estimated parking fee</h2>
        <dl class="kv">
            <div><dt>Initial <?= $quote['rate']['initial_minutes'] ?> minutes</dt><dd><?= e(ui_peso((int) $estimate['initial_centavos'])) ?></dd></div>
            <div><dt>Succeeding hours (<?= $estimate['succeeding_hours'] ?>)</dt><dd><?= e(ui_peso((int) $estimate['succeeding_centavos'])) ?></dd></div>
            <?php if ($estimate['cap_reduction_centavos']): ?><div><dt>Daily-cap reduction</dt><dd>−<?= e(ui_peso((int) $estimate['cap_reduction_centavos'])) ?></dd></div><?php endif; ?>
            <div><dt>Additional charge</dt><dd><?= e(ui_peso((int) $estimate['additional_centavos'])) ?></dd></div>
            <div class="kv-total"><dt>Estimated total</dt><dd><?= e(ui_peso((int) $estimate['total_centavos'])) ?></dd></div>
        </dl>
        <p class="form-note">Grace applies only when the entire stay is within <?= $quote['rate']['grace_minutes'] ?> minutes. The daily cap covers parking charges; additional charges apply separately. Final fees depend on actual parking time in a later phase.</p>
    </article>
    <form method="post" action="<?= e(url('reservation-review')) ?>" class="action-form sticky-cta"><?= csrf_field() ?><input type="hidden" name="request_key" value="<?= e($quote['request_key']) ?>"><button class="button primary block" type="submit">Start checkout hold</button></form>
    <p class="form-note">Unpaid holds can be cancelled at any time. Confirmed reservation cancellation requires at least <?= $snapshot['cancellation_cutoff_minutes'] ?> minutes before arrival and no active session. Paid bookings require a verified refund workflow.</p>
<?php endif; ?>
</section>
