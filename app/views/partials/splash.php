<?php
declare(strict_types=1);
// App launch animation. Hidden unless boot.js adds `show-splash` to <html> (first view of a session).
$splashMark = @file_get_contents(BASE_PATH . '/public/assets/images/logo-mark.svg') ?: '';
$splashMark = preg_replace('/<svg[^>]*>/', '<svg class="splash-mark" viewBox="0 0 100.1 100" width="132" height="132" aria-hidden="true" focusable="false">', $splashMark, 1) ?? '';
$splashMark = preg_replace('/<path /', '<path class="splash-p" ', $splashMark, 1) ?? '';
$splashMark = preg_replace('/<path d=/', '<path class="splash-swoosh" d=', $splashMark, 1) ?? '';
?>
<div class="splash" id="splash" aria-hidden="true">
    <div class="splash-rings"><span></span><span></span><span></span></div>
    <div class="splash-stage">
        <div class="splash-mark-wrap"><?= $splashMark ?></div>
        <div class="splash-word"><span>P</span><span>A</span><span>R</span><span>K</span><span class="gap"></span><span>N</span><span class="gap"></span><span>G</span><span>O</span></div>
        <p class="splash-tag">Your Space. Your Pace.</p>
    </div>
    <div class="splash-progress"><span></span></div>
</div>
