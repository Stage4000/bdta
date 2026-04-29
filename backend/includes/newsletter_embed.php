<?php

require_once __DIR__ . '/settings.php';

function bdta_get_newsletter_embed_markup(): string
{
    $embed_markup = trim(scalar_string(Settings::get('newsletter_embed_html', '')));
    return $embed_markup;
}

function bdta_inject_newsletter_embed_markup(string $html): string
{
    $embed_markup = bdta_get_newsletter_embed_markup();
    if ($embed_markup === '') {
        return $html;
    }

    if (preg_match('/<\/body>/i', $html) === 1) {
        return preg_replace('/<\/body>/i', $embed_markup . "\n</body>", $html, 1) ?? $html;
    }

    return rtrim($html) . "\n" . $embed_markup;
}

function bdta_render_newsletter_embed(): void
{
    echo bdta_get_newsletter_embed_markup();
}
