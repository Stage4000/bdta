#!/usr/bin/env php
<?php

define('BDTA_TEST_MODE', true);

require_once dirname(__DIR__) . '/backend/includes/settings.php';
require_once dirname(__DIR__) . '/backend/includes/newsletter_embed.php';

function assertNewsletterEmbed(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

Settings::seedCacheForTesting([
    'newsletter_embed_html' => '',
]);

assertNewsletterEmbed(
    bdta_get_newsletter_embed_markup() === '',
    'Expected empty newsletter embed settings to render nothing.'
);

$embed_markup = '<div class="newsletter-embed">Subscribe</div>';
Settings::seedCacheForTesting([
    'newsletter_embed_html' => $embed_markup,
]);

assertNewsletterEmbed(
    bdta_get_newsletter_embed_markup() === $embed_markup,
    'Expected newsletter embed helper to return the saved embed markup unchanged.'
);

$html_without_body = '<div class="page-shell">Hello</div>';
assertNewsletterEmbed(
    bdta_inject_newsletter_embed_markup($html_without_body) === $html_without_body . "\n" . $embed_markup,
    'Expected newsletter embed helper to append markup when no closing body tag exists.'
);

$html_with_body = '<html><body><main>Page</main></body></html>';
$injected_html = bdta_inject_newsletter_embed_markup($html_with_body);
assertNewsletterEmbed(
    $injected_html === '<html><body><main>Page</main>' . $embed_markup . "\n</body></html>",
    'Expected newsletter embed helper to inject markup before the closing body tag.'
);

$database_source = file_get_contents(dirname(__DIR__) . '/backend/includes/database.php');
if (!is_string($database_source)) {
    throw new RuntimeException('Expected to read the database source.');
}

assertNewsletterEmbed(
    str_contains($database_source, "'newsletter_embed_html'")
        && str_contains($database_source, "'Newsletter Embed HTML'")
        && str_contains($database_source, "'textarea'"),
    'Expected database settings defaults to include the newsletter embed textarea.'
);

$admin_users_source = file_get_contents(dirname(__DIR__) . '/backend/includes/admin_users.php');
if (!is_string($admin_users_source)) {
    throw new RuntimeException('Expected to read the admin user helper source.');
}

assertNewsletterEmbed(
    str_contains($admin_users_source, "'newsletter_embed_html'"),
    'Expected newsletter embed HTML to follow the existing restricted global setting permissions.'
);

$index_source = file_get_contents(dirname(__DIR__) . '/index.php');
$page_source = file_get_contents(dirname(__DIR__) . '/page.php');
$settings_source = file_get_contents(dirname(__DIR__) . '/client/settings.php');
if (!is_string($index_source) || !is_string($page_source)) {
    throw new RuntimeException('Expected to read the public page renderers.');
}
if (!is_string($settings_source)) {
    throw new RuntimeException('Expected to read the settings page source.');
}

assertNewsletterEmbed(
    str_contains($index_source, "require_once __DIR__ . '/backend/includes/newsletter_embed.php';")
        && str_contains($index_source, 'bdta_inject_newsletter_embed_markup($html);')
        && str_contains($index_source, 'bdta_render_newsletter_embed();'),
    'Expected homepage rendering to load and output the newsletter embed helper.'
);

assertNewsletterEmbed(
    str_contains($page_source, "require_once __DIR__ . '/backend/includes/newsletter_embed.php';")
        && str_contains($page_source, 'bdta_render_newsletter_embed();'),
    'Expected dynamic public pages to load and output the newsletter embed helper.'
);

assertNewsletterEmbed(
    str_contains($settings_source, 'Trusted admins only: this embed code is rendered as-is on public site pages.')
        && str_contains($settings_source, 'Only paste official embed code from trusted providers')
        && str_contains($settings_source, 'malicious scripts could create XSS risk for visitors'),
    'Expected settings UI to warn that newsletter embed HTML is rendered directly on public pages.'
);

echo "Newsletter embed checks passed.\n";
