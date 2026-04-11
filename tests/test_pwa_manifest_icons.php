<?php

function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$repoRoot = dirname(__DIR__);
$manifestPath = $repoRoot . '/client/manifest.webmanifest';
$manifestJson = file_get_contents($manifestPath);
assertTrue($manifestJson !== false, 'Expected the PWA manifest to be readable.');

$manifest = json_decode($manifestJson, true);
assertTrue(is_array($manifest), 'Expected the PWA manifest JSON to decode to an array.');
assertTrue(isset($manifest['icons']) && is_array($manifest['icons']), 'Expected the PWA manifest to define icons.');

$expectedIcons = [
    '/client/pwa-icon-192.png' => '192x192',
    '/client/pwa-icon-512.png' => '512x512',
];

foreach ($expectedIcons as $src => $size) {
    $matchingIcon = null;

    foreach ($manifest['icons'] as $icon) {
        if (($icon['src'] ?? null) === $src) {
            $matchingIcon = $icon;
            break;
        }
    }

    assertTrue(is_array($matchingIcon), 'Expected manifest icon "' . $src . '" to be present.');
    assertTrue(($matchingIcon['type'] ?? null) === 'image/png', 'Expected manifest icon "' . $src . '" to be a PNG.');
    assertTrue(($matchingIcon['sizes'] ?? null) === $size, 'Expected manifest icon "' . $src . '" to advertise size ' . $size . '.');

    $iconPath = $repoRoot . $src;
    assertTrue(is_file($iconPath), 'Expected icon file "' . $iconPath . '" to exist.');
    $iconSize = getimagesize($iconPath);
    assertTrue(is_array($iconSize), 'Expected icon file "' . $iconPath . '" to be a readable image.');
    assertTrue(($iconSize[0] . 'x' . $iconSize[1]) === $size, 'Expected icon file "' . $iconPath . '" to match manifest size ' . $size . '.');
}

$appleTouchIconPath = $repoRoot . '/client/apple-touch-icon.png';
assertTrue(is_file($appleTouchIconPath), 'Expected the Apple touch icon file to exist.');
$appleTouchIconSize = getimagesize($appleTouchIconPath);
assertTrue(is_array($appleTouchIconSize), 'Expected the Apple touch icon file to be a readable image.');
assertTrue(($appleTouchIconSize[0] ?? 0) === 180 && ($appleTouchIconSize[1] ?? 0) === 180, 'Expected the Apple touch icon to be 180x180.');

$loginMarkup = file_get_contents($repoRoot . '/client/login.php');
assertTrue($loginMarkup !== false, 'Expected login.php to be readable.');
assertTrue(str_contains($loginMarkup, '<link rel="apple-touch-icon" sizes="180x180" href="/client/apple-touch-icon.png">'), 'Expected login.php to include the Apple touch icon link.');

$headerMarkup = file_get_contents($repoRoot . '/backend/includes/header.php');
assertTrue($headerMarkup !== false, 'Expected backend/includes/header.php to be readable.');
assertTrue(str_contains($headerMarkup, '<link rel="apple-touch-icon" sizes="180x180" href="/client/apple-touch-icon.png">'), 'Expected backend header to include the Apple touch icon link.');

echo "PWA manifest icon test passed.\n";
