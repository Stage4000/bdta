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
<div class="bdta-public-notice bg-dark text-white border-top border-secondary-subtle" data-public-notice role="status">
    <div class="container py-2 small text-center">{$message}</div>
</div>
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
