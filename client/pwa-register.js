(function () {
    'use strict';
    if (!('serviceWorker' in navigator)) {
        return;
    }
    // Register only for admin pages under /client/ to keep scope intentional.
    const currentPath = (window.location.pathname || '').toLowerCase();
    if (!currentPath.startsWith('/client/')) {
        return;
    }
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/client/sw.js', { scope: '/client/' }).catch(function (err) {
            console.error('Service worker registration failed:', err);
            if (window.alert) {
                alert('Unable to enable offline support. Please refresh or contact support if this continues.');
            }
        });
    });
}());
