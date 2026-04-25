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

assertTrue(str_contains($themeInit, "? saved : 'light';"), 'Expected theme initialization to default to light mode.');
assertTrue(!str_contains($themeInit, 'matchMedia'), 'Expected theme initialization not to auto-enable dark mode from system preferences.');

$indexHtmlPath = dirname(__DIR__) . '/index.html';
$indexHtml = file_get_contents($indexHtmlPath);
if ($indexHtml === false) {
    throw new RuntimeException('Failed to read static homepage HTML.');
}

assertTrue(str_contains($indexHtml, 'data-theme-toggle'), 'Expected the static homepage to expose a mobile floating theme toggle.');
assertTrue(str_contains($indexHtml, 'd-none d-lg-inline-flex'), 'Expected the navbar theme toggle to stay desktop-only when the floating mobile toggle is present.');

$indexPhpPath = dirname(__DIR__) . '/index.php';
$indexPhp = file_get_contents($indexPhpPath);
if ($indexPhp === false) {
    throw new RuntimeException('Failed to read homepage router.');
}

assertTrue(str_contains($indexPhp, "bdta_get_public_theme_toggle_button_html('d-lg-none')"), 'Expected the DB-backed homepage renderer to include the shared mobile theme toggle.');

$pagePhpPath = dirname(__DIR__) . '/page.php';
$pagePhp = file_get_contents($pagePhpPath);
if ($pagePhp === false) {
    throw new RuntimeException('Failed to read dynamic public page renderer.');
}

assertTrue(str_contains($pagePhp, "bdta_get_public_theme_toggle_button_html('d-lg-none')"), 'Expected dynamic public pages to include the shared mobile theme toggle.');

echo "Public theme toggle asset test passed.\n";
