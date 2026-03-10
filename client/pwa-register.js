(function () {
    'use strict';
    if (!('serviceWorker' in navigator)) {
        return;
    }
    // Register only for admin pages under /client/ to keep scope intentional.
    var path = (window.location && window.location.pathname || '').toLowerCase();
    if (!path.startsWith('/client/')) {
        return;
    }
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/client/sw.js', { scope: '/client/' }).catch(function (err) {
            console.error('Service worker registration failed:', err);
        });
    });
}());
