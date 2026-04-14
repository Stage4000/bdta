#!/usr/bin/env php
<?php

class Settings
{
    /** @var array<string, string> */
    public static array $values = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? $default;
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function assertContains(string $needle, string $haystack, string $message): void
{
    assertTrue(str_contains($haystack, $needle), $message);
}

require_once dirname(__DIR__) . '/backend/includes/turnstile.php';

Settings::$values = [
    'turnstile_site_key' => '',
    'turnstile_secret_key' => '',
];
assertTrue(bdta_turnstile_is_enabled() === false, 'Expected Turnstile to stay disabled when keys are missing.');
assertTrue(bdta_get_turnstile_assets_html() === '', 'Expected no assets when Turnstile is disabled.');

Settings::$values = [
    'turnstile_site_key' => 'site-key-123',
    'turnstile_secret_key' => 'secret-key-456',
];

assertTrue(bdta_turnstile_is_enabled() === true, 'Expected Turnstile to be enabled when both keys are present.');
assertContains('/assets/js/public/turnstile.js', bdta_get_turnstile_assets_html(), 'Expected helper JS asset to be included.');
assertContains('render=explicit', bdta_get_turnstile_assets_html(), 'Expected Cloudflare explicit render script to be included.');

$widget_markup = bdta_get_turnstile_widget_markup(['wrapper_class' => 'mb-4']);
assertContains('bdta-turnstile-wrapper mb-4', $widget_markup, 'Expected widget wrapper class to be rendered.');
assertContains('data-sitekey="site-key-123"', $widget_markup, 'Expected site key to be rendered into widget markup.');

$prepared_html = bdta_prepare_public_html_with_turnstile('<html><body><form method="post"><input type="text" name="name"></form></body></html>');
assertContains('bdta-turnstile', $prepared_html, 'Expected Turnstile widget to be injected into HTML forms.');
assertContains('challenges.cloudflare.com/turnstile', $prepared_html, 'Expected Turnstile assets to be injected into HTML.');

$already_present = '<html><body><form method="post"><div class="bdta-turnstile" data-sitekey="site-key-123"></div></form></body></html>';
$prepared_existing = bdta_prepare_public_html_with_turnstile($already_present);
assertTrue(substr_count($prepared_existing, 'bdta-turnstile') === 1, 'Expected existing Turnstile widgets not to be duplicated.');

$token = bdta_get_turnstile_response_token([
    'turnstile_token' => 'token-a',
    'cf-turnstile-response' => 'token-b',
]);
assertTrue($token === 'token-b', 'Expected Cloudflare response token to take precedence when present.');

fwrite(STDOUT, "Turnstile helper tests passed.\n");
