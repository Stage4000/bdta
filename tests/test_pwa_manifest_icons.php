#!/usr/bin/env php
<?php

function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function readFileOrFail(string $path, string $label): string
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException('Expected ' . $label . ' to be readable.');
    }

    return $contents;
}

/**
 * @return list<array{src: string, type: string, sizes: string}>
 */
function loadManifestIcons(string $manifestPath): array
{
    $manifest = json_decode(readFileOrFail($manifestPath, 'the PWA manifest'), true);

    if (!is_array($manifest)) {
        throw new RuntimeException('Expected the PWA manifest JSON to decode to an array.');
    }

    $icons = $manifest['icons'] ?? null;

    if (!is_array($icons)) {
        throw new RuntimeException('Expected the PWA manifest to define icons.');
    }

    $manifestIcons = [];

    foreach ($icons as $icon) {
        if (
            !is_array($icon)
            || !isset($icon['src'], $icon['type'], $icon['sizes'])
            || !is_string($icon['src'])
            || !is_string($icon['type'])
            || !is_string($icon['sizes'])
        ) {
            throw new RuntimeException('Expected each manifest icon to define string src, type, and sizes fields.');
        }

        $manifestIcons[] = [
            'src' => $icon['src'],
            'type' => $icon['type'],
            'sizes' => $icon['sizes'],
        ];
    }

    return $manifestIcons;
}

/**
 * @param list<array{src: string, type: string, sizes: string}> $icons
 *
 * @return array{src: string, type: string, sizes: string}|null
 */
function findManifestIcon(array $icons, string $src): ?array
{
    foreach ($icons as $icon) {
        if ($icon['src'] === $src) {
            return $icon;
        }
    }

    return null;
}

try {
    $repoRoot = dirname(__DIR__);
    $manifestPath = $repoRoot . '/client/manifest.webmanifest';
    $manifestIcons = loadManifestIcons($manifestPath);

    $expectedIcons = [
        '/client/pwa-icon-192.png' => '192x192',
        '/client/pwa-icon-512.png' => '512x512',
    ];

    foreach ($expectedIcons as $src => $size) {
        $matchingIcon = findManifestIcon($manifestIcons, $src);

        if ($matchingIcon === null) {
            throw new RuntimeException('Expected manifest icon "' . $src . '" to be present.');
        }

        assertTrue($matchingIcon['type'] === 'image/png', 'Expected manifest icon "' . $src . '" to be a PNG.');
        assertTrue($matchingIcon['sizes'] === $size, 'Expected manifest icon "' . $src . '" to advertise size ' . $size . '.');

        $iconPath = $repoRoot . $src;
        assertTrue(is_file($iconPath), 'Expected icon file "' . $iconPath . '" to exist.');
        $iconSize = getimagesize($iconPath);
        if ($iconSize === false) {
            throw new RuntimeException('Expected icon file "' . $iconPath . '" to be a readable image.');
        }
        assertTrue(($iconSize[0] . 'x' . $iconSize[1]) === $size, 'Expected icon file "' . $iconPath . '" to match manifest size ' . $size . '.');
    }

    $appleTouchIconPath = $repoRoot . '/client/apple-touch-icon.png';
    assertTrue(is_file($appleTouchIconPath), 'Expected the Apple touch icon file to exist.');
    $appleTouchIconSize = getimagesize($appleTouchIconPath);
    if ($appleTouchIconSize === false) {
        throw new RuntimeException('Expected the Apple touch icon file to be a readable image.');
    }
    assertTrue($appleTouchIconSize[0] === 180 && $appleTouchIconSize[1] === 180, 'Expected the Apple touch icon to be 180x180.');

    $loginMarkup = readFileOrFail($repoRoot . '/client/login.php', 'login.php');
    assertTrue(str_contains($loginMarkup, '<link rel="apple-touch-icon" sizes="180x180" href="/client/apple-touch-icon.png">'), 'Expected login.php to include the Apple touch icon link.');

    $headerMarkup = readFileOrFail($repoRoot . '/backend/includes/header.php', 'backend/includes/header.php');
    assertTrue(str_contains($headerMarkup, '<link rel="apple-touch-icon" sizes="180x180" href="/client/apple-touch-icon.png">'), 'Expected backend header to include the Apple touch icon link.');

    echo "PWA manifest icon test passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
