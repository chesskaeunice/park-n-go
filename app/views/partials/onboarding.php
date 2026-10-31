<?php
declare(strict_types=1);
// First-launch tour. Hidden by default; app.js opens it for new guests/drivers or via [data-tour-open] / ?tour=1.
$tourSteps = tour_steps();
$tourCount = count($tourSteps);
?>
<div class="onboarding" id="onboarding" role="dialog" aria-modal="true" aria-label="How Park n Go works" hidden>
    <div class="ob-header">
        <span class="ob-brand"><?= ui_logo('ob-logo') ?><span>PARK N GO</span></span>
        <button type="button" class="ob-skip" data-ob-skip>Skip</button>
    </div>
    <div class="ob-track" data-ob-track tabindex="-1">
        <?php foreach ($tourSteps as $index => [$heading, $copy]): ?>
        <section class="ob-slide<?= $index === 0 ? ' is-active' : '' ?>" data-ob-slide aria-roledescription="slide" aria-label="Step <?= $index + 1 ?> of <?= $tourCount ?>">
            <div class="ob-art"><?= tour_art($index) ?></div>
            <div class="ob-copy">
                <span class="ob-step">Step <?= $index + 1 ?> of <?= $tourCount ?></span>
                <h2><?= e($heading) ?></h2>
                <p><?= e($copy) ?></p>
            </div>
        </section>
        <?php endforeach; ?>
    </div>
    <div class="ob-footer">
        <div class="ob-dots">
            <?php for ($index = 0; $index < $tourCount; $index++): ?><button type="button" class="ob-dot" data-ob-dot="<?= $index ?>" aria-label="Go to step <?= $index + 1 ?>"<?= $index === 0 ? ' aria-current="step"' : '' ?>></button><?php endfor; ?>
        </div>
        <button type="button" class="button primary ob-next" data-ob-next>Next</button>
        <?php if (!($currentUser ?? null)): ?><a class="ob-login" href="<?= e(url('login')) ?>" data-ob-login hidden>I already have an account</a><?php endif; ?>
    </div>
</div>
