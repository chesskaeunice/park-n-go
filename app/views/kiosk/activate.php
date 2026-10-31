<?php $data = $pageData; ?>
<section class="page-head"><span class="eyebrow">Kiosk enrollment</span><h1 class="h1">Activate this kiosk</h1><p class="small muted">Enter the one-time code supplied by an authorized administrator. Codes expire after 10 minutes.</p></section>
<section class="content-card">
<?php if ($data['errors']): ?><div class="form-errors" role="alert"><ul><?php foreach ($data['errors'] as $error): ?><li><?= e(ui_error_message($error)) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form class="account-form" action="<?= e(url('kiosk-activate')) ?>" method="post">
<?= csrf_field() ?><label for="activation-code">Activation code</label><input id="activation-code" name="code" maxlength="32" minlength="32" pattern="[A-Fa-f0-9]{32}" required autocomplete="off" autocapitalize="characters"><button class="button primary block" type="submit">Activate kiosk</button>
</form></section>
