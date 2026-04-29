<?php

require_once __DIR__ . '/settings.php';

function bdta_get_newsletter_embed_markup(): string
{
    return trim(scalar_string(Settings::get('newsletter_embed_html', '')));
}

function bdta_inject_newsletter_embed_markup(string $html): string
{
    $embed_markup = bdta_get_newsletter_embed_markup();
    if ($embed_markup === '') {
        return $html;
    }

    $html_with_embed = preg_replace('/<\/body>/i', $embed_markup . "\n</body>", $html, 1);
    if (is_string($html_with_embed) && $html_with_embed !== $html) {
        return $html_with_embed;
    }

    return rtrim($html) . "\n" . $embed_markup;
}

function bdta_render_newsletter_embed(): void
{
    echo bdta_get_newsletter_embed_markup();
}
