<?php
$heading = match ($page) { 'login' => 'Welcome back', 'register' => 'Create your account', 'forgot-password' => 'Reset your password', 'reset-password' => 'Choose a new password', default => $title };
?>
<section class="auth">
    <img class="auth-logo" src="assets/images/logo-mark.svg" alt="" width="64" height="64">
    <span class="eyebrow">Your Space. Your Pace.</span>
    <h1 class="h1"><?= e($heading) ?></h1>
    <?php if ($page === 'register'): ?><p class="muted">Create a driver account to get ready for your next journey.</p>
    <?php elseif ($page === 'login'): ?><p class="muted">Log in to reserve your next parking space. Drivers, attendants, and administrators can sign in here.</p>
    <?php elseif ($page === 'forgot-password'): ?><p class="muted">Enter your account email to request a single-use reset link. Links expire after 30 minutes.</p>
    <?php else: ?><p class="muted">Choose a new password. Resetting it signs out your other sessions.</p><?php endif; ?>
    <?php if ($errors ?? []): ?><div class="form-errors" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e(ui_error_message($error)) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form class="account-form" action="<?= e(url($page)) ?>" method="post"><?= csrf_field() ?>
        <?php if ($page === 'register'): ?><label for="name">Full name</label><div class="input-icon"><?= ui_icon('user') ?><input id="name" name="name" autocomplete="name" minlength="2" maxlength="100" required value="<?= e($old['name'] ?? '') ?>" placeholder="Juan Dela Cruz"></div><?php endif; ?>
        <?php if ($page !== 'reset-password'): ?><label for="email">Email address</label><div class="input-icon"><?= ui_icon('mail') ?><input id="email" name="email" type="email" autocomplete="email" maxlength="254" required value="<?= e($old['email'] ?? '') ?>" placeholder="you@example.com"></div><?php endif; ?>
        <?php if (in_array($page, ['register', 'login', 'reset-password'], true)): ?>
        <label for="password"><?= $page === 'reset-password' ? 'New password' : 'Password' ?></label>
        <div class="input-icon has-action"><?= ui_icon('lock') ?><input id="password" name="password" type="password" autocomplete="<?= $page === 'login' ? 'current-password' : 'new-password' ?>" <?= $page !== 'login' ? 'minlength="12" aria-describedby="password-help"' : '' ?> maxlength="72" required><button type="button" class="input-action" data-toggle-password="password" aria-label="Show password" aria-pressed="false" hidden><?= ui_icon('eye', 20) ?></button></div>
        <?php if ($page !== 'login'): ?><small id="password-help">Use at least 12 characters. Maximum 72 bytes; accented characters and emoji may use multiple bytes.</small><label for="password-confirmation">Confirm password</label><div class="input-icon"><?= ui_icon('lock') ?><input id="password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></div><?php endif; ?>
        <?php endif; ?>
        <?php if ($page === 'login'): ?><a class="forgot-link" href="<?= e(url('forgot-password')) ?>">Forgot password?</a><?php endif; ?>
        <button class="button primary block" type="submit"><?= match ($page) { 'register' => 'Create driver account', 'forgot-password' => 'Send reset link', 'reset-password' => 'Update password', default => 'Log in' } ?></button>
    </form>
    <?php if ($page === 'login'): ?>
    <div class="divider"><span>or</span></div>
    <a class="button secondary block" href="<?= e(url('register')) ?>">Create a driver account</a>
    <?php else: ?>
    <div class="account-links"><a href="<?= e(url('login')) ?>">Log in</a><?php if ($page !== 'register'): ?><a href="<?= e(url('register')) ?>">Register</a><?php endif; ?><?php if ($page !== 'forgot-password'): ?><a href="<?= e(url('forgot-password')) ?>">Forgot password</a><?php endif; ?></div>
    <?php endif; ?>
    <?php if (\ParkNGo\Services\Environment::get('APP_ENV', 'local') === 'local' && in_array($page, ['forgot-password','reset-password'], true)): ?><p class="local-note">Local development: email delivery uses the private <code>storage/mail/</code> outbox by default. No external email is sent in log mode.</p><?php endif; ?>
    <p class="app-footnote">By continuing, you agree to Park n Go’s terms and privacy policy.</p>
</section>
