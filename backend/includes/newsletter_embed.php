<?php

require_once __DIR__ . '/settings.php';

function bdta_get_newsletter_embed_markup(): string
{
    return trim(scalar_string(Settings::get('newsletter_embed_html', '')));
}

function bdta_get_newsletter_embed_wrapped_markup(): string
{
    $embed_markup = bdta_get_newsletter_embed_markup();
    if ($embed_markup === '') {
        return '';
    }

    return <<<HTML
<section class="bdta-newsletter-embed-section" aria-label="Newsletter signup">
    <div class="container">
        <div class="bdta-newsletter-embed-card">
            {$embed_markup}
        </div>
    </div>
</section>
HTML;
}

function bdta_inject_newsletter_embed_markup(string $html): string
{
    if (str_contains($html, 'bdta-newsletter-embed-section')) {
        return $html;
    }

    $embed_markup = bdta_get_newsletter_embed_wrapped_markup();
    if ($embed_markup === '') {
        return $html;
    }

    $html_with_embed = preg_replace('/(<footer\b[^>]*>)/i', $embed_markup . "\n$1", $html, 1);
    if (is_string($html_with_embed) && $html_with_embed !== $html) {
        return $html_with_embed;
    }

    $html_with_embed = preg_replace('/<\/body>/i', $embed_markup . "\n</body>", $html, 1);
    if (is_string($html_with_embed)) {
        if ($html_with_embed !== $html) {
            return $html_with_embed;
        }

        return rtrim($html) . "\n" . $embed_markup;
    }

    return $html;
}

function bdta_render_newsletter_embed(): void
{
    // This setting intentionally renders trusted admin-provided embed HTML/JS.
    echo bdta_get_newsletter_embed_wrapped_markup();
}
