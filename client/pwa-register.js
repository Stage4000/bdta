(function () {
    'use strict';

    // Register only for admin pages under /client/ to keep scope intentional.
    const ADMIN_PATH = '/client/';
    const canRegisterServiceWorker = 'serviceWorker' in navigator;
    const currentPath = (window.location.pathname || '').toLowerCase();
    const normalizedAdminPath = ADMIN_PATH.toLowerCase();
    if (!currentPath.startsWith(normalizedAdminPath)) {
        return;
    }

    const installNavItem = document.getElementById('pwaInstallNavItem');
    const installButton = document.getElementById('pwaInstallButton');
    const hasInstallUi = installNavItem !== null && installButton !== null;
    const installFallbackMessage = 'To reinstall the BDTA app, open your browser menu and choose "Install app" or "Add to Home screen". Chrome may not show the automatic install prompt again right away after uninstalling.';
    const installFallbackNoticeBackground = '#0d6efd';
    const installFallbackNoticeDurationMs = 8000;
    let deferredInstallPrompt = null;
    let installFallbackNotice = null;

    function isStandaloneMode() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    }

    function showInstallFallbackNotice() {
        if (!document.body) {
            return;
        }

        if (installFallbackNotice) {
            installFallbackNotice.remove();
        }

        installFallbackNotice = document.createElement('div');
        installFallbackNotice.textContent = installFallbackMessage;
        installFallbackNotice.style.position = 'fixed';
        installFallbackNotice.style.bottom = '1rem';
        installFallbackNotice.style.right = '1rem';
        installFallbackNotice.style.zIndex = '2000';
        installFallbackNotice.style.maxWidth = '26rem';
        installFallbackNotice.style.background = installFallbackNoticeBackground;
        installFallbackNotice.style.color = '#fff';
        installFallbackNotice.style.padding = '0.75rem 1rem';
        installFallbackNotice.style.borderRadius = '0.5rem';
        installFallbackNotice.style.boxShadow = '0 0.5rem 1rem rgba(0,0,0,0.2)';
        installFallbackNotice.setAttribute('role', 'status');
        installFallbackNotice.setAttribute('aria-live', 'polite');
        document.body.appendChild(installFallbackNotice);

        const notice = installFallbackNotice;
        window.setTimeout(function () {
            if (installFallbackNotice === notice) {
                installFallbackNotice.remove();
                installFallbackNotice = null;
            }
        }, installFallbackNoticeDurationMs);
    }

    function updateInstallButton() {
        if (!installNavItem || !installButton) {
            return;
        }

        const inStandaloneMode = isStandaloneMode();
        const canInstall = !inStandaloneMode;
        installNavItem.classList.toggle('d-none', inStandaloneMode);
        installButton.disabled = !canInstall;
        installButton.classList.toggle('disabled', !canInstall);
    }

    window.addEventListener('beforeinstallprompt', function (event) {
        if (!hasInstallUi) {
            return;
        }
        event.preventDefault();
        deferredInstallPrompt = event;
        updateInstallButton();
    });

    window.addEventListener('appinstalled', function () {
        if (!hasInstallUi) {
            return;
        }
        deferredInstallPrompt = null;
        updateInstallButton();
    });

    if (installButton) {
        installButton.addEventListener('click', async function () {
            if (!deferredInstallPrompt) {
                showInstallFallbackNotice();
                return;
            }

            deferredInstallPrompt.prompt();

            try {
                const userChoice = await deferredInstallPrompt.userChoice;
                if (userChoice && userChoice.outcome !== 'accepted') {
                    showInstallFallbackNotice();
                }
            } catch (err) {
                console.error('PWA install prompt failed:', err);
                showInstallFallbackNotice();
            }

            deferredInstallPrompt = null;
            updateInstallButton();
        });
    }

    window.addEventListener('load', function () {
        updateInstallButton();
        if (!canRegisterServiceWorker) {
            return;
        }

        navigator.serviceWorker.register('/client/sw.js', { scope: ADMIN_PATH }).catch(function (err) {
            console.error('Service worker registration failed:', err);
            const note = document.createElement('div');
            note.textContent = 'Some enhanced features (such as PWA installability) may be unavailable. You can keep using the app; please refresh if this issue persists.';
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
