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
    const installFallbackMessage = 'To reinstall the BDTA app, open your browser menu and choose "Install app" or "Add to Home screen". Some browsers may not show the automatic install prompt again right away after uninstalling.';
    const installFallbackNoticeBackground = '#0d6efd';
    const installFallbackNoticeDurationMs = 8000;
    const serviceWorkerFailureMessage = 'Some enhanced features (such as PWA installability) may be unavailable. You can keep using the app; please refresh if this issue persists.';
    const serviceWorkerFailureNoticeBackground = '#dc3545';
    let deferredInstallPrompt = null;
    let installFallbackNotice = null;
    let serviceWorkerFailureNotice = null;

    function isStandaloneMode() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    }

    /**
     * @param {HTMLDivElement|null} currentNotice
     * @param {string} message
     * @param {string} background
     * @param {string} role
     * @param {string} ariaLive
     * @param {number|null} durationMs
     * @param {(notice: HTMLDivElement) => void} onDismiss
     * @returns {HTMLDivElement|null}
     */
    function showToastNotice(currentNotice, message, background, role, ariaLive, durationMs, onDismiss) {
        if (!document.body) {
            return currentNotice;
        }

        if (currentNotice) {
            currentNotice.remove();
        }

        const notice = document.createElement('div');
        notice.textContent = message;
        notice.style.position = 'fixed';
        notice.style.bottom = '1rem';
        notice.style.right = '1rem';
        notice.style.zIndex = '2000';
        notice.style.maxWidth = '26rem';
        notice.style.background = background;
        notice.style.color = '#fff';
        notice.style.padding = '0.75rem 1rem';
        notice.style.borderRadius = '0.5rem';
        notice.style.boxShadow = '0 0.5rem 1rem rgba(0,0,0,0.2)';
        notice.setAttribute('role', role);
        notice.setAttribute('aria-live', ariaLive);
        document.body.appendChild(notice);

        if (durationMs !== null) {
            window.setTimeout(function () {
                if (notice.isConnected) {
                    notice.remove();
                }

                if (typeof onDismiss === 'function') {
                    onDismiss(notice);
                }
            }, durationMs);
        }

        return notice;
    }

    function showInstallFallbackNotice() {
        installFallbackNotice = showToastNotice(
            installFallbackNotice,
            installFallbackMessage,
            installFallbackNoticeBackground,
            'status',
            'polite',
            installFallbackNoticeDurationMs,
            function (notice) {
                if (installFallbackNotice === notice) {
                    installFallbackNotice = null;
                }
            }
        );
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
                await deferredInstallPrompt.userChoice;
            } catch (err) {
                console.error('PWA install prompt failed:', err);
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
            serviceWorkerFailureNotice = showToastNotice(
                serviceWorkerFailureNotice,
                serviceWorkerFailureMessage,
                serviceWorkerFailureNoticeBackground,
                'alert',
                'assertive',
                null,
                function (notice) {
                    if (serviceWorkerFailureNotice === notice) {
                        serviceWorkerFailureNotice = null;
                    }
                }
            );
        });
    });
}());
