<section class="hero-card">
    <?= ui_lot(0, 'hero-art') ?>
    <div class="hero-copy">
        <span class="eyebrow">A better way to park</span>
        <h1 class="display">Less circling.<br>More <span class="accent">living.</span></h1>
        <p class="lead">Your next stop starts with a space. Discover parking, choose your spot, and get on with your day.</p>
        <div class="button-row">
            <a class="button primary" href="<?= e(url('facilities')) ?>"><?= ui_icon('compass') ?>Explore Parking</a>
            <a class="button secondary" href="<?= e(url('how-it-works')) ?>">How It Works</a>
        </div>
    </div>
</section>

<section class="card search-card" aria-labelledby="finder-title">
    <h2 id="finder-title" class="h3">Where are you headed?</h2>
    <p class="small muted">Find a parking location for your next stop.</p>
    <form action="index.php" method="get" class="stack-form planning-search" aria-describedby="search-preview-note">
        <input type="hidden" name="page" value="facilities">
        <label class="field-label" for="home-q">Parking location or facility</label>
        <div class="input-icon"><?= ui_icon('search') ?><input id="home-q" name="q" type="search" placeholder="Try Makati or Central…" maxlength="100"></div>
        <div class="field-row">
            <div><label class="field-label" for="home-date">Date <small>(optional)</small></label><div class="input-icon"><?= ui_icon('calendar') ?><input id="home-date" name="date" type="date" data-planning-field></div></div>
            <div><label class="field-label" for="home-time">Time <small>(optional)</small></label><div class="input-icon"><?= ui_icon('clock') ?><input id="home-time" name="time" type="time" data-planning-field></div></div>
        </div>
        <button class="button primary block" type="submit"><?= ui_icon('search') ?>Find Parking</button>
    </form>
    <p id="search-preview-note" class="form-note">Leave date and time empty to check now. Checkout creates a temporary hold; Maya Sandbox payment is available locally.</p>
</section>

<section class="section" aria-labelledby="process-title">
    <div class="section-head"><h2 id="process-title" class="h3">A simple route to your space</h2><a class="link" href="<?= e(url('how-it-works')) ?>">See all</a></div>
    <ol class="journey">
        <li><span class="journey-icon"><?= ui_icon('search', 22) ?></span><div><h3 class="h4">Find Parking</h3><p>Explore a location that works for you.</p></div></li>
        <li><span class="journey-icon"><?= ui_icon('car', 22) ?></span><div><h3 class="h4">Reserve a Slot</h3><p>Pick your space and arrival time.</p></div></li>
        <li><span class="journey-icon"><?= ui_icon('wallet', 22) ?></span><div><h3 class="h4">Pay Online</h3><p>Review your fee and use hosted checkout.</p></div></li>
        <li><span class="journey-icon"><?= ui_icon('qr', 22) ?></span><div><h3 class="h4">Scan Your QR Code</h3><p>Show your ticket to an attendant on arrival.</p></div></li>
    </ol>
    <button type="button" class="button ghost block" data-tour-open hidden><?= ui_icon('play') ?>Watch the 30-second tour</button>
</section>

<section class="section" aria-labelledby="benefits-title">
    <div class="section-head"><h2 id="benefits-title" class="h3">One less thing to think about</h2></div>
    <div class="benefits">
        <article class="benefit"><span class="benefit-icon"><?= ui_icon('compass', 20) ?></span><h3 class="h4">Real-Time Availability</h3><p>Check database availability for your arrival time. The map refreshes while you browse.</p></article>
        <article class="benefit"><span class="benefit-icon"><?= ui_icon('calendar', 20) ?></span><h3 class="h4">Online Reservations</h3><p>Choose a space, review its fee, and hold it while you pay.</p></article>
        <article class="benefit"><span class="benefit-icon"><?= ui_icon('shield', 20) ?></span><h3 class="h4">Secure Digital Payments</h3><p>Maya hosted checkout with server-side payment verification.</p></article>
        <article class="benefit"><span class="benefit-icon"><?= ui_icon('qr', 20) ?></span><h3 class="h4">QR Entry and Exit</h3><p>A secure ticket, revalidated at entry and exit.</p></article>
    </div>
</section>

<section class="cta-card">
    <h2 class="h3">Make room for what matters.</h2>
    <p>Take a look around our sample parking experience.</p>
    <a class="button light block" href="<?= e(url('facilities')) ?>">Explore Parking</a>
</section>
<p class="app-footnote">Slot review, Maya Sandbox payment verification, confirmed reservations, and QR access are available in local development.<br>&copy; <?= date('Y') ?> Park n Go · Development preview · Phase 6</p>
