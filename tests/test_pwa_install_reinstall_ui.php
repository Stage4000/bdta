<?php

function assertTrue(bool $condition, string $message): void
{
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

try {
    $repoRoot = dirname(__DIR__);
    $script = readFileOrFail($repoRoot . '/client/pwa-register.js', 'client/pwa-register.js');

    assertTrue(str_contains($script, 'const canInstall = !inStandaloneMode;'), 'Expected the install button to remain enabled whenever the admin app is not already running in standalone mode.');
    assertTrue(str_contains($script, 'function showInstallFallbackNotice()'), 'Expected the PWA installer script to define a fallback notice for browsers that do not expose the install prompt.');
    assertTrue(str_contains($script, 'open your browser menu and choose "Install app" or "Add to Home screen"'), 'Expected the fallback notice to explain how to reinstall the app manually.');
    assertTrue(str_contains($script, 'if (!deferredInstallPrompt) {'), 'Expected the installer click handler to explicitly handle the missing deferred install prompt case.');
    assertTrue(str_contains($script, 'showInstallFallbackNotice();'), 'Expected the installer click handler to surface the fallback notice when the browser does not provide an install prompt.');

    echo "PWA reinstall UI test passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
