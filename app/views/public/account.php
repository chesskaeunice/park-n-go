<section class="shell account-page"><div class="content-card">
<span class="eyebrow">Your Space. Your Pace.</span><h1><?= e($title) ?></h1>
<?php if ($errors ?? []): ?><div class="form-errors" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($page === 'register'): ?><p>Create a driver account to get ready for your next journey.</p>
<?php elseif ($page === 'login'): ?><p>Welcome back. Drivers, attendants, and administrators can sign in here.</p>
<?php elseif ($page === 'forgot-password'): ?><p>Enter your account email to request a single-use reset link. Links expire after 30 minutes.</p>
<?php else: ?><p>Choose a new password. Resetting it signs out your other sessions.</p><?php endif; ?>
<form class="account-form" action="<?= e(url($page)) ?>" method="post"><?= csrf_field() ?>
<?php if ($page === 'register'): ?><label for="name">Full name</label><input id="name" name="name" autocomplete="name" minlength="2" maxlength="100" required value="<?= e($old['name'] ?? '') ?>"><?php endif; ?>
<?php if ($page !== 'reset-password'): ?><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" maxlength="254" required value="<?= e($old['email'] ?? '') ?>"><?php endif; ?>
<?php if (in_array($page, ['register', 'login', 'reset-password'], true)): ?>
<label for="password"><?= $page === 'reset-password' ? 'New password' : 'Password' ?></label><input id="password" name="password" type="password" autocomplete="<?= $page === 'login' ? 'current-password' : 'new-password' ?>" <?= $page !== 'login' ? 'minlength="12" aria-describedby="password-help"' : '' ?> maxlength="72" required>
<?php if ($page !== 'login'): ?><small id="password-help">Use at least 12 characters. Maximum 72 bytes; accented characters and emoji may use multiple bytes.</small><label for="password-confirmation">Confirm password</label><input id="password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="72" required><?php endif; ?>
<?php endif; ?>
<button class="button primary" type="submit"><?= match ($page) { 'register' => 'Create driver account', 'forgot-password' => 'Send reset link', 'reset-password' => 'Update password', default => 'Log in' } ?></button>
</form>
<div class="account-links"><a href="<?= e(url('login')) ?>">Log in</a><a href="<?= e(url('register')) ?>">Register</a><a href="<?= e(url('forgot-password')) ?>">Forgot password</a></div>
<?php if (\ParkNGo\Services\Environment::get('APP_ENV', 'local') === 'local' && in_array($page, ['forgot-password','reset-password'], true)): ?><p class="local-note">Local development: email delivery uses the private <code>storage/mail/</code> outbox by default. No external email is sent in log mode.</p><?php endif; ?>
</div></section>
