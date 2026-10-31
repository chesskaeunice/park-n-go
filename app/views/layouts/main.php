<?php
declare(strict_types=1);
require_once BASE_PATH . '/app/views/partials/ui.php';
require_once BASE_PATH . '/app/views/partials/tour.php';

$currentUser = $currentUser ?? null;
$role = is_array($currentUser) ? (string) $currentUser['role'] : 'guest';
// [target route, label, icon, routes that highlight the tab]
$tabs = match ($role) {
    'driver' => [
        ['dashboard', 'Home', 'home', ['dashboard', 'home']],
        ['facilities', 'Explore', 'compass', ['facilities', 'facility']],
        ['reservations', 'Bookings', 'ticket', ['reservations', 'reservation-review', 'reservation-checkout', 'ticket', 'payment-return']],
        ['profile', 'Profile', 'user', ['profile']],
    ],
    'attendant' => [
        ['attendant-dashboard', 'Home', 'home', ['attendant-dashboard', 'home']],
        ['entry-scan', 'Entry', 'scan-in', ['entry-scan']],
        ['exit-scan', 'Exit', 'scan-out', ['exit-scan']],
        ['profile', 'Profile', 'user', ['profile']],
    ],
    'admin' => [
        ['admin-dashboard', 'Home', 'home', ['admin-dashboard', 'home']],
        ['admin-facilities', 'Inventory', 'layers', ['admin-facilities', 'admin-floors', 'admin-slots']],
        ['facilities', 'Explore', 'compass', ['facilities', 'facility']],
        ['profile', 'Profile', 'user', ['profile']],
    ],
    default => [
        ['home', 'Home', 'home', ['home']],
        ['facilities', 'Explore', 'compass', ['facilities', 'facility']],
        ['how-it-works', 'Guide', 'book', ['how-it-works', 'about']],
        ['login', 'Account', 'user', ['login', 'register', 'forgot-password', 'reset-password']],
    ],
};
$homeRoute = $currentUser ? \ParkNGo\Services\Auth::dashboard($role) : 'home';
$isTabPage = $page === 'home' || in_array($page, array_column($tabs, 0), true);
$flowPages = ['facility', 'reservation-review', 'reservation-checkout', 'reservation-cancel', 'payment-start', 'payment-return', 'payment-refresh', 'ticket'];
$showTabs = !in_array($page, $flowPages, true);
// Phase 7 operations pages carry wide tables; on large screens they may use more width than the phone shell.
$adminWide = $role === 'admin' && in_array($page, ['admin-dashboard', 'admin-occupancy', 'admin-session-exception', 'admin-reconciliation', 'admin-refunds', 'admin-pricing', 'admin-reports', 'admin-audit', 'admin-permissions'], true);
$backTo = match (true) {
    in_array($page, ['facility', 'reservation-review'], true) => url('facilities'),
    in_array($page, ['reservation-checkout', 'reservation-cancel', 'payment-start', 'payment-return', 'payment-refresh', 'ticket'], true) => url('reservations'),
    in_array($page, ['admin-facilities', 'admin-floors', 'admin-slots'], true) => url('admin-dashboard'),
    $page === 'admin-session-exception' => url('admin-occupancy'),
    in_array($page, ['entry-scan', 'exit-scan'], true) => url('attendant-dashboard'),
    default => url($homeRoute),
};
$screenTitle = [
    'facility' => 'Parking Details', 'reservation-review' => 'Review Reservation', 'reservation-checkout' => 'Checkout',
    'reservation-cancel' => 'Reservation', 'payment-return' => 'Payment Status', 'payment-refresh' => 'Payment Status',
    'ticket' => 'Parking Ticket', 'how-it-works' => 'How It Works', 'about' => 'About Park n Go', 'register' => 'Create Account',
    'forgot-password' => 'Reset Password', 'reset-password' => 'New Password', 'profile' => 'Profile',
    'admin-floors' => 'Manage Floors', 'admin-slots' => 'Manage Slots', 'entry-scan' => 'Entry Scanner', 'exit-scan' => 'Exit Scanner',
    'admin-occupancy' => 'Occupancy', 'admin-session-exception' => 'Session Exception', 'admin-reconciliation' => 'Reconciliation', 'admin-refunds' => 'Refunds',
    'admin-pricing' => 'Pricing', 'admin-reports' => 'Reports', 'admin-audit' => 'Audit Log', 'admin-permissions' => 'Permissions',
][$page] ?? $title;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#FFFFFF">
    <meta name="description" content="Park n Go — Your Space. Your Pace. Find a parking space, reserve your slot, and scan in with a QR ticket.">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Park n Go">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title><?= e($title) ?> · Park n Go</title>
    <link rel="manifest" href="manifest.json">
    <link rel="icon" href="assets/images/mark.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="assets/images/apple-touch-icon.png">
    <link rel="preload" href="assets/fonts/jost-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="assets/fonts/jost-600.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/account.css">
    <script src="assets/js/boot.js"></script>
    <script src="assets/js/app.js" defer></script>
</head>
<body class="<?= $showTabs ? 'has-tabbar' : 'no-tabbar' ?><?= $adminWide ? ' admin-wide' : '' ?>" data-page="<?= e($page) ?>" data-role="<?= e($role) ?>">
<?php require BASE_PATH . '/app/views/partials/splash.php'; ?>
<a class="skip-link" href="#main">Skip to content</a>
<div class="app-shell" id="app-shell">
    <header class="appbar<?= $isTabPage ? ' appbar-root' : '' ?>">
        <?php if ($isTabPage): ?>
        <a class="brand" href="<?= e(url($homeRoute)) ?>" aria-label="Park n Go home"><?= ui_logo('brand-logo') ?><span class="brand-word">PARK N GO</span></a>
        <div class="appbar-actions">
            <?php if ($currentUser): ?>
            <a class="avatar-link" href="<?= e(url('profile')) ?>" aria-label="Your profile"><span class="avatar sm"><?= e(ui_initials((string) $currentUser['name'])) ?></span></a>
            <?php elseif (!in_array($page, ['login', 'register'], true)): ?>
            <a class="pill-link" href="<?= e(url('login')) ?>">Log in</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <a class="icon-button" href="<?= e($backTo) ?>" data-back aria-label="Go back"><?= ui_icon('arrow-left', 22) ?></a>
        <span class="appbar-title"><?= e($screenTitle) ?></span>
        <?php endif; ?>
    </header>
    <main id="main" class="screen">
        <?php if ($message = flash()): ?><div class="toast" role="status"><?= ui_icon('check-circle', 20) ?><span><?= e($message) ?></span></div><?php endif; ?>
        <?php require BASE_PATH . '/app/views/' . $view . '.php'; ?>
    </main>
    <?php if ($showTabs): ?>
    <nav class="tabbar<?= $role === 'driver' ? ' driver-bottom-nav' : '' ?>" aria-label="<?= $role === 'driver' ? 'Driver navigation' : 'App navigation' ?>">
        <?php foreach ($tabs as [$target, $label, $icon, $pages]): ?>
        <a href="<?= e(url($target)) ?>"<?= in_array($page, $pages, true) ? ' aria-current="page"' : '' ?>><span class="tab-icon"><?= ui_icon($icon, 22) ?></span><span class="tab-label"><?= e($label) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
</div>
<?php require BASE_PATH . '/app/views/partials/onboarding.php'; ?>
</body>
</html>
