(function () {
    'use strict';
    if (!('serviceWorker' in navigator)) {
        return;
    }
    // Register only for admin pages under /client/ to keep scope intentional.
    const ADMIN_PATH = '/client/';
    const currentPath = (window.location.pathname || '').toLowerCase();
    const normalizedAdminPath = ADMIN_PATH.toLowerCase();
    if (!currentPath.startsWith(normalizedAdminPath)) {
        return;
    }
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/client/sw.js', { scope: ADMIN_PATH }).catch(function (err) {
            console.error('Service worker registration failed:', err);
            const note = document.createElement('div');
            note.textContent = 'Unable to initialize the application. Please refresh or contact support if this issue persists.';
            note.style.position = 'fixed';
            note.style.bottom = '1rem';
            note.style.right = '1rem';
            note.style.zIndex = '2000';
            note.style.background = '#dc3545';
            note.style.color = '#fff';
            note.style.padding = '0.75rem 1rem';
            note.style.borderRadius = '0.5rem';
            note.style.boxShadow = '0 0.5rem 1rem rgba(0,0,0,0.2)';
            note.setAttribute('role', 'alert');
            note.setAttribute('aria-live', 'assertive');
            document.body.appendChild(note);
        });
    });
}());
