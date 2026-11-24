// App startup guard. If the app code hasn't started at all within 20 seconds
// (typically a stale index.html from the browser cache referencing chunks that no longer
// exist on the server), it tries ONCE to reload the page with a unique URL – that
// bypasses the cache exactly like a manual hard reload. The app signals that it is up
// by setting document.documentElement.dataset.zvkBoot = '1' in +page.svelte.
(function () {
    'use strict';
    var KEY = 'zvk_bootheal';
    setTimeout(function () {
        if (document.documentElement.dataset.zvkBoot === '1') return; // app is running
        if (navigator.onLine === false) return; // offline: reloading won't help
        var already = true; // without storage, better not try (it could cause a reload loop)
        try {
            already = sessionStorage.getItem(KEY) === '1';
            sessionStorage.setItem(KEY, '1');
        } catch (e) { /* storage blocked */ }
        if (already) return;
        location.replace(location.pathname + '?v=' + Date.now().toString(36));
    }, 20000);
})();
