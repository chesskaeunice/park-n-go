<section class="greeting">
    <span class="avatar"><?= e(ui_initials((string) $currentUser['name'])) ?></span>
    <div><p class="small muted">Attendant workspace</p><p class="h3"><?= e($currentUser['name']) ?></p></div>
</section>
<h1 class="h1 home-title">Ready for<br>the next car?</h1>
<p class="small muted">Your attendant account is authenticated and this workspace is protected by role permissions.</p>
<div class="action-tiles">
    <a class="action-tile primary" href="<?= e(url('entry-scan')) ?>"><span class="tile-icon"><?= ui_icon('scan-in', 26) ?></span><span class="h4">Entry scanner</span><span class="small">Validate arriving tickets</span></a>
    <a class="action-tile" href="<?= e(url('exit-scan')) ?>"><span class="tile-icon"><?= ui_icon('scan-out', 26) ?></span><span class="h4">Exit scanner</span><span class="small">Complete sessions</span></a>
</div>
<article class="card"><h2 class="h4">Parking operations</h2><p class="small">Validate verified QR tickets, accept authorized entry scans, and complete exit scans after overtime checks.</p></article>
<article class="card"><h2 class="h4">Know the journey</h2><p class="small">QR entry and exit are revalidated server-side against payment, facility authorization, timing, and session state.</p><a class="link" href="<?= e(url('how-it-works')) ?>">How it works<?= ui_icon('chevron-right', 16) ?></a></article>
