'use strict';
// Runs before first paint (loaded synchronously in <head>, CSP-safe external file).
// Decides whether the launch animation should cover the first screen of this session.
(function () {
    var root = document.documentElement;
    root.classList.add('js');
    try {
        var params = new URLSearchParams(window.location.search);
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var seen = window.sessionStorage.getItem('png-splash-seen') === '1';
        if (params.get('splash') === '1' || (!seen && !reduce)) root.classList.add('show-splash');
    } catch (error) {
        // Storage can be blocked (private mode); skip the animation rather than risk hiding the page.
    }
})();
