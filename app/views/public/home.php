<section class="hero shell">
    <div class="hero-copy">
        <div class="eyebrow">A better way to park</div>
        <h1>Less circling.<br>More <span class="gradient-text">living.</span></h1>
        <p class="hero-description">Your next stop starts with a space.<br> Discover parking, choose your spot, and get on with your day.</p>
        <div class="actions"><a class="button primary" href="<?= e(url('facilities')) ?>">Explore Parking</a><a class="text-link" href="<?= e(url('how-it-works')) ?>">See How It Works</a></div>
    </div>
    <!-- Replace this whole container with a local image when one is provided. -->
    <div class="image-placeholder hero-image"><span>Hero Image Placeholder</span></div>
</section>
<section class="finder shell" aria-labelledby="finder-title">
    <div><h2 id="finder-title">Where are you headed?</h2><p>Find a parking location for your next stop.</p></div>
    <form action="index.php" method="get" class="search-form planning-search" aria-describedby="search-preview-note">
        <input type="hidden" name="page" value="facilities">
        <label class="location-field"><span>Parking location or facility</span><input name="q" type="search" placeholder="Try Makati or Central…" maxlength="100"></label>
        <label><span>Date <small>(optional)</small></span><input name="date" type="date" data-planning-field></label>
        <label><span>Time <small>(optional)</small></span><input name="time" type="time" data-planning-field></label>
        <button class="button primary" type="submit">Find Parking</button>
    </form>
    <p id="search-preview-note" class="form-note">Check availability for your arrival time. Leave both date and time empty to check now. Checkout creates an unpaid temporary hold; payments are not enabled yet.</p>
</section>
<section class="section shell">
    <div class="section-heading"><div><span class="eyebrow">Parking, made simpler</span><h2>One less thing to think about.</h2></div><p>From finding a space to heading home,<br>a simpler journey is on the way.</p></div>
    <div class="benefit-grid">
        <article class="benefit"><h3>Real-Time Parking Availability</h3><p>Explore facilities and check database availability for your arrival time. The map refreshes while you browse.</p></article>
        <article class="benefit"><h3>Online Reservations</h3><p>Choose a space, review its estimated fee, and create a temporary checkout hold. Confirmation awaits verified payment in a later phase.</p></article>
        <article class="benefit"><h3>Secure Digital Payments</h3><p>Planned PayMongo hosted checkout will let you pay securely online. Payments are not enabled in this preview.</p></article>
        <article class="benefit"><h3>QR-Based Entry and Exit</h3><p>Your planned QR ticket will bring entry and exit into one place, issued only after the required payment checks.</p></article>
    </div>
</section>
<section class="home-process section shell" aria-labelledby="process-title">
    <div class="section-heading"><div><span class="eyebrow">How it works</span><h2 id="process-title">A simple route to your space.</h2></div><a class="text-link" href="<?= e(url('how-it-works')) ?>">See the full journey</a></div>
    <ol class="process-grid"><li><h3>Find Parking</h3><p>Explore a location that works for you.</p></li><li><h3>Reserve a Slot</h3><p>Pick your space and arrival time.</p></li><li><h3>Pay Online</h3><p>Review your fee and use hosted checkout.</p></li><li><h3>Scan Your QR Code</h3><p>Show your ticket to an attendant on arrival.</p></li></ol>
    <p class="form-note">Slot review and temporary unpaid holds are available. Online payment, confirmed entry, and QR access arrive in later phases.</p>
</section>
<section class="closing shell"><div><h2>Make room for what matters.</h2><p>Take a look around our sample parking experience.</p></div><a class="button primary" href="<?= e(url('facilities')) ?>">Explore Parking</a></section>
