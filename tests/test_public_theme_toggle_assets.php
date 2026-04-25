<?php

function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$themeInitPath = dirname(__DIR__) . '/assets/js/theme-init.js';
$themeInit = file_get_contents($themeInitPath);
if ($themeInit === false) {
    throw new RuntimeException('Failed to read theme initialization script.');
}

assertTrue(
    preg_match('/saved\s*===\s*[\'"]dark[\'"]/', $themeInit) === 1,
    'Expected theme initialization to preserve an explicit dark-mode selection.'
);
assertTrue(
    preg_match('/saved\s*===\s*[\'"]light[\'"]/', $themeInit) === 1,
    'Expected theme initialization to preserve an explicit light-mode selection.'
);
assertTrue(
    preg_match('/var\s+theme\s*=.*[\'"]light[\'"]\s*;/s', $themeInit) === 1,
    'Expected theme initialization to default to light mode.'
);
assertTrue(!str_contains($themeInit, 'matchMedia'), 'Expected theme initialization not to auto-enable dark mode from system preferences.');

$indexHtmlPath = dirname(__DIR__) . '/index.html';
$indexHtml = file_get_contents($indexHtmlPath);
if ($indexHtml === false) {
    throw new RuntimeException('Failed to read static homepage HTML.');
}

assertTrue(str_contains($indexHtml, 'data-theme-toggle'), 'Expected the static homepage to expose a mobile floating theme toggle.');
assertTrue(str_contains($indexHtml, 'd-none d-lg-inline-flex'), 'Expected the navbar theme toggle to stay desktop-only when the floating mobile toggle is present.');
assertTrue(str_contains($indexHtml, 'public-theme-toggle'), 'Expected the static homepage mobile floating theme toggle to use shared theme-aware styling.');
assertTrue(!str_contains($indexHtml, 'background-color:rgba(255,255,255,0.95)'), 'Expected the static homepage mobile floating toggle not to hard-code a light background.');

$siteCssPath = dirname(__DIR__) . '/assets/css/public/site.css';
$siteCss = file_get_contents($siteCssPath);
if ($siteCss === false) {
    throw new RuntimeException('Failed to read public site CSS.');
}

assertTrue(str_contains($siteCss, '.public-theme-toggle'), 'Expected public site CSS to define shared floating toggle styling.');
assertTrue(str_contains($siteCss, '--bs-body-bg-rgb'), 'Expected shared floating toggle styling to use theme-aware Bootstrap body colors.');

$indexPhpPath = dirname(__DIR__) . '/index.php';
$indexPhp = file_get_contents($indexPhpPath);
if ($indexPhp === false) {
    throw new RuntimeException('Failed to read homepage router.');
}

assertTrue(str_contains($indexPhp, 'bdta_get_public_theme_toggle_button_html()'), 'Expected the DB-backed homepage renderer to include the shared floating theme toggle on all viewport sizes.');
assertTrue(!str_contains($indexPhp, "bdta_get_public_theme_toggle_button_html('d-lg-none')"), 'Expected the DB-backed homepage renderer not to hide the shared floating theme toggle on desktop.');

$pagePhpPath = dirname(__DIR__) . '/page.php';
$pagePhp = file_get_contents($pagePhpPath);
if ($pagePhp === false) {
    throw new RuntimeException('Failed to read dynamic public page renderer.');
}

assertTrue(str_contains($pagePhp, 'bdta_get_public_theme_toggle_button_html()'), 'Expected dynamic public pages to include the shared floating theme toggle on all viewport sizes.');
assertTrue(!str_contains($pagePhp, "bdta_get_public_theme_toggle_button_html('d-lg-none')"), 'Expected dynamic public pages not to hide the shared floating theme toggle on desktop.');

echo "Public theme toggle asset test passed.\n";
