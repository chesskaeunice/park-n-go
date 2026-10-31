<section class="page-head">
    <span class="eyebrow">A simpler journey</span>
    <h1 class="h1">Park. Scan. <span class="accent">Go.</span></h1>
    <p class="muted">Here’s the Park n Go experience, from your first search to your final stop.</p>
    <button type="button" class="button secondary block" data-tour-open hidden><?= ui_icon('play') ?>Replay the app tour</button>
</section>
<ol class="how-steps">
    <?php foreach (tour_steps() as $index => [$heading, $copy]): ?>
    <li class="how-step is-active">
        <div class="how-art"><?= tour_art($index) ?></div>
        <div class="how-copy"><span class="step-number">0<?= $index + 1 ?></span><h2 class="h3"><?= e($heading) ?></h2><p><?= e($copy) ?></p></div>
    </li>
    <?php endforeach; ?>
    <li class="how-step how-step-plain">
        <div class="how-copy"><span class="step-number">05</span><h2 class="h3">Head out at your pace</h2><p>Check your elapsed time, settle any outstanding charges, and present your ticket for a verified exit.</p></div>
    </li>
</ol>
<div class="notice"><?= ui_icon('info', 18) ?><p>Local development uses Maya Sandbox checkout, server-side payment verification, confirmed reservations, and QR access. Production payment deployment remains separate.</p></div>
<a class="button primary block" href="<?= e(url('facilities')) ?>">Try the parking map</a>
