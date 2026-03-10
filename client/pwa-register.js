(function () {
    'use strict';
    if (!('serviceWorker' in navigator)) {
        return;
    }
    // Register only for admin pages under /client/ to keep scope intentional.
    const ADMIN_PATH = '/client/';
    const currentPath = (window.location.pathname || '').toLowerCase();
    if (!currentPath.startsWith(ADMIN_PATH)) {
        return;
    }
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/client/sw.js', { scope: ADMIN_PATH }).catch(function (err) {
            console.error('Service worker registration failed:', err);
            if (window.alert) {
                alert('Unable to initialize the application. Please refresh or contact support if this issue persists.');
            }
        });
    });
}());
