<?php

require_once __DIR__ . '/settings.php';

function bdta_get_public_notice_text(): string {
    $notice = Settings::get('public_notice_text', '');
    if (!is_scalar($notice)) {
        return '';
    }

    return trim(str_replace(["\r\n", "\r"], "\n", (string) $notice));
}

/**
 * @return array{enabled: bool, text: string}
 */
function bdta_get_public_notice_state(): array {
    return [
        'enabled' => (bool) Settings::get('public_notice_enabled', false),
        'text' => bdta_get_public_notice_text(),
    ];
}

function bdta_should_render_public_notice(): bool {
    $notice = bdta_get_public_notice_state();
    return $notice['enabled'] && $notice['text'] !== '';
}

function bdta_get_public_notice_markup(): string {
    $notice = bdta_get_public_notice_state();
    $enabled = $notice['enabled'];
    $notice_text = $notice['text'];

    if (!$enabled || $notice_text === '') {
        return '';
    }

    $message = nl2br(htmlspecialchars($notice_text, ENT_QUOTES, 'UTF-8'));

    return <<<HTML
<style>
body.bdta-public-notice-visible {
    padding-bottom: var(--bdta-public-notice-height, 0px);
}

.bdta-public-notice {
    position: fixed;
    right: 0;
    bottom: 0;
    left: 0;
    z-index: 1080;
    box-shadow: 0 -0.5rem 1rem rgba(0, 0, 0, 0.15);
}

.bdta-public-notice__content {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.bdta-public-notice__message {
    flex: 1 1 auto;
}

.bdta-public-notice__dismiss {
    flex: 0 0 auto;
    padding: 0.25rem;
}

@media (max-width: 575.98px) {
    .bdta-public-notice__content {
        gap: 0.75rem;
    }
}
</style>
<div class="bdta-public-notice bg-dark text-white border-top border-secondary-subtle" data-public-notice>
    <div class="container py-2 small bdta-public-notice__content">
        <div class="bdta-public-notice__message" aria-live="polite">{$message}</div>
        <button type="button" class="btn-close btn-close-white bdta-public-notice__dismiss" data-public-notice-dismiss aria-label="Dismiss notice"></button>
    </div>
</div>
<script>
(function () {
    function initPublicNotice() {
        var notice = document.querySelector('[data-public-notice]');
        if (!notice || notice.dataset.initialized) {
            return;
        }

        notice.dataset.initialized = '1';

        var dismissButton = notice.querySelector('[data-public-notice-dismiss]');
        var body = document.body;
        var root = document.documentElement;
        var resizeFrame = null;

        function syncNoticeHeight() {
            if (notice.hidden) {
                return;
            }

            root.style.setProperty('--bdta-public-notice-height', notice.offsetHeight + 'px');
            body.classList.add('bdta-public-notice-visible');
        }

        function dismissNotice() {
            if (resizeFrame !== null) {
                window.cancelAnimationFrame(resizeFrame);
                resizeFrame = null;
            }

            notice.hidden = true;
            body.classList.remove('bdta-public-notice-visible');
            root.style.removeProperty('--bdta-public-notice-height');
            window.removeEventListener('resize', scheduleNoticeHeightSync);
        }

        function scheduleNoticeHeightSync() {
            if (resizeFrame !== null) {
                return;
            }

            resizeFrame = window.requestAnimationFrame(function () {
                resizeFrame = null;
                syncNoticeHeight();
            });
        }

        if (dismissButton) {
            dismissButton.addEventListener('click', dismissNotice);
        }

        syncNoticeHeight();
        window.addEventListener('resize', scheduleNoticeHeightSync);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPublicNotice);
    } else {
        initPublicNotice();
    }
})();
</script>
HTML;
}

function bdta_render_public_notice(): void {
    echo bdta_get_public_notice_markup();
}

function bdta_inject_public_notice_markup(string $html): string {
    $markup = bdta_get_public_notice_markup();
    if ($markup === '' || str_contains($html, 'data-public-notice')) {
        return $html;
    }

    if (preg_match('/<\/body>/i', $html) === 1) {
        return preg_replace('/<\/body>/i', $markup . "\n</body>", $html, 1) ?? $html;
    }

    return $html . $markup;
}
