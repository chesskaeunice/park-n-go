<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#FFFFFF">
    <meta name="description" content="Park n Go — Your Space. Your Pace. Explore a simpler approach to parking reservations and QR entry.">
    <title><?= e($title) ?> · Park n Go</title>
    <link rel="icon" href="assets/images/mark.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/account.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="header">
    <a class="brand" href="<?= e(url()) ?>" aria-label="Park n Go home"><span class="brand-mark" aria-hidden="true">P</span><span>Park <span class="brand-n">n</span> Go</span></a>
    <button class="menu-toggle" aria-controls="navigation" aria-expanded="false" aria-label="Toggle navigation">☰</button>
    <nav id="navigation" aria-label="Main navigation">
        <?php foreach (['home' => 'Home', 'facilities' => 'Find Parking', 'how-it-works' => 'How It Works', 'about' => 'About Us'] as $key => $label): ?>
        <a href="<?= e(url($key)) ?>" <?= $page === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?></a>
        <?php endforeach; ?>
        <?php if ($currentUser ?? null): ?>
        <a class="nav-login" href="<?= e(url(\ParkNGo\Services\Auth::dashboard($currentUser['role']))) ?>">My Dashboard</a>
        <form class="logout-form" action="<?= e(url('logout')) ?>" method="post"><?= csrf_field() ?><button type="submit">Log out</button></form>
        <?php else: ?>
        <a class="nav-login" href="<?= e(url('login')) ?>">Log In</a>
        <?php endif; ?>
    </nav>
</header>
<main id="main">
<?php if ($message = flash()): ?><div class="shell"><div class="flash-message" role="status"><?= e($message) ?></div></div><?php endif; ?>
<?php require BASE_PATH . '/app/views/' . $view . '.php'; ?>
</main>
<footer class="footer shell">
    <div class="footer-main">
        <div><a class="brand small" href="<?= e(url()) ?>"><span class="brand-mark" aria-hidden="true">P</span><span>Park <span class="brand-n">n</span> Go</span></a><p>Your Space. Your Pace.</p></div>
        <nav class="footer-nav" aria-label="Footer navigation"><a href="<?= e(url()) ?>">Home</a><a href="<?= e(url('facilities')) ?>">Find Parking</a><a href="<?= e(url('how-it-works')) ?>">How It Works</a><a href="<?= e(url('about')) ?>">About Us</a></nav>
        <div class="footer-contact"><strong>Contact</strong><p>Contact details will be added before launch.</p></div>
    </div>
    <div class="footer-bottom"><span>&copy; <?= date('Y') ?> Park n Go. All rights reserved.</span><span>Development preview · Phase 4</span></div>
</footer>
<?php if (($currentUser['role'] ?? '') === 'driver'): ?>
<nav class="driver-bottom-nav" aria-label="Driver navigation"><a href="<?= e(url('dashboard')) ?>" <?= $page === 'dashboard' ? 'aria-current="page"' : '' ?>>⌂ <span>Dashboard</span></a><a href="<?= e(url('facilities')) ?>" <?= in_array($page,['facilities','facility'],true) ? 'aria-current="page"' : '' ?>>⌖ <span>Explore</span></a><a href="<?= e(url('reservations')) ?>" <?= in_array($page,['reservations','reservation-review','reservation-checkout'],true) ? 'aria-current="page"' : '' ?>>▤ <span>Bookings</span></a><a href="<?= e(url('profile')) ?>" <?= $page === 'profile' ? 'aria-current="page"' : '' ?>>◎ <span>Profile</span></a></nav>
<?php endif; ?>
</body>
</html>
