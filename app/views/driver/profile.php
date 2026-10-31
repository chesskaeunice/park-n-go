<?php $profileRole = (string) $currentUser['role']; ?>
<section class="profile-head">
    <span class="avatar lg"><?= e(ui_initials((string) $currentUser['name'])) ?></span>
    <div>
        <h1 class="h3"><?= e($currentUser['name']) ?></h1>
        <p class="small muted"><?= e($currentUser['email']) ?></p>
        <span class="status-pill ok"><?= e(ucfirst($profileRole)) ?></span>
    </div>
</section>
<?php if ($errors ?? []): ?><div class="form-errors" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e(ui_error_message($error)) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<article class="card">
    <span class="eyebrow">YOUR ACCOUNT</span>
    <h2 class="h4">Profile settings</h2>
    <p class="small muted">Keep your contact details up to date.</p>
    <form class="account-form" action="<?= e(url('profile')) ?>" method="post"><?= csrf_field() ?>
        <label for="profile-name">Full name</label><div class="input-icon"><?= ui_icon('user') ?><input id="profile-name" name="name" required minlength="2" maxlength="100" autocomplete="name" value="<?= e($old['name'] ?? $currentUser['name']) ?>"></div>
        <label for="profile-phone">Phone number (optional)</label><div class="input-icon"><?= ui_icon('phone') ?><input id="profile-phone" name="phone" type="tel" maxlength="24" autocomplete="tel" value="<?= e($old['phone'] ?? $currentUser['phone'] ?? '') ?>"></div>
        <label for="profile-email">Email address</label><div class="input-icon"><?= ui_icon('mail') ?><input id="profile-email" readonly type="email" value="<?= e($currentUser['email']) ?>"></div>
        <small>Email changes need a verified address workflow and are not available yet.</small>
        <button class="button primary block" type="submit">Save profile</button>
    </form>
</article>
<nav class="menu-list" aria-label="Account shortcuts">
    <?php if ($profileRole === 'driver'): ?>
    <a href="<?= e(url('reservations')) ?>"><span class="menu-icon"><?= ui_icon('ticket') ?></span><span class="grow"><b>My bookings</b><small>Holds, tickets and history</small></span><?= ui_icon('chevron-right', 18) ?></a>
    <?php elseif ($profileRole === 'attendant'): ?>
    <a href="<?= e(url('entry-scan')) ?>"><span class="menu-icon"><?= ui_icon('scan-in') ?></span><span class="grow"><b>Entry scanner</b><small>Validate arriving tickets</small></span><?= ui_icon('chevron-right', 18) ?></a>
    <?php else: ?>
    <a href="<?= e(url('admin-facilities')) ?>"><span class="menu-icon"><?= ui_icon('layers') ?></span><span class="grow"><b>Parking inventory</b><small>Facilities, floors and slots</small></span><?= ui_icon('chevron-right', 18) ?></a>
    <?php endif; ?>
    <a href="<?= e(url('how-it-works')) ?>"><span class="menu-icon"><?= ui_icon('book') ?></span><span class="grow"><b>How Park n Go works</b><small>The journey, step by step</small></span><?= ui_icon('chevron-right', 18) ?></a>
    <button type="button" data-tour-open hidden><span class="menu-icon"><?= ui_icon('play') ?></span><span class="grow"><b>Replay the app tour</b><small>The four-step onboarding</small></span><?= ui_icon('chevron-right', 18) ?></button>
    <a href="<?= e(url('about')) ?>"><span class="menu-icon"><?= ui_icon('help') ?></span><span class="grow"><b>About Park n Go</b><small>Our vision and contact</small></span><?= ui_icon('chevron-right', 18) ?></a>
</nav>
<form class="logout-form" action="<?= e(url('logout')) ?>" method="post"><?= csrf_field() ?><button type="submit"><span class="menu-icon"><?= ui_icon('logout') ?></span><span>Log out</span></button></form>
