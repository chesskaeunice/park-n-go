<section class="shell page-intro"><span class="eyebrow">A simpler journey</span><h1>Park. Scan. <span class="gradient-text">Go.</span></h1><p>Here’s the planned Park n Go experience, from your first search to your final stop.</p><div class="notice">Phase 4 preview. Review a space and create a temporary unpaid hold. Online payment, confirmed entry, and QR access arrive in later phases.</div></section>
<section class="shell steps">
<?php foreach ([['Find your space', 'Search for a facility, choose a floor, and pick an available parking slot from the visual map.'], ['Reserve with confidence', 'Choose your arrival time and duration, review your estimated fee, and complete payment through a hosted payment provider.'], ['Scan on arrival', 'Present your secure QR ticket to an attendant. A verified entry scan starts your parking session.'], ['Head out at your pace', 'Check your elapsed time, settle any outstanding charges, and present your ticket for a verified exit.']] as $index => [$heading, $copy]): ?>
<article class="step"><span class="step-number">0<?= $index + 1 ?></span><div><h2><?= e($heading) ?></h2><p><?= e($copy) ?></p></div></article>
<?php endforeach; ?>
<a class="button primary" href="<?= e(url('facilities')) ?>">Try the parking map</a>
</section>
