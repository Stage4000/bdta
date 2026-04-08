<?php

$siteCss = file_get_contents(dirname(__DIR__) . '/assets/css/public/site.css');
if ($siteCss === false) {
    throw new RuntimeException('Failed to read public site stylesheet.');
}

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . "\nMissing: " . $needle);
    }
}

assertContainsText('@media (max-width: 991.98px) {', $siteCss, 'Expected mobile navbar breakpoint styles.');
assertContainsText('.navbar-brand {', $siteCss, 'Expected navbar brand styles to exist.');
assertContainsText('max-width: calc(100% - 4.5rem);', $siteCss, 'Expected mobile navbar brand sizing to keep room for the toggle button.');
assertContainsText('overflow-wrap: anywhere;', $siteCss, 'Expected mobile navbar brand text to wrap instead of overflowing.');
assertContainsText('.navbar-toggler {', $siteCss, 'Expected navbar toggler styles to exist.');
assertContainsText('flex-shrink: 0;', $siteCss, 'Expected navbar toggler to remain visible on mobile.');
assertContainsText('.navbar-collapse {', $siteCss, 'Expected navbar collapse styles to exist.');
assertContainsText('flex-basis: 100%;', $siteCss, 'Expected collapsed mobile navigation to wrap onto its own row.');

echo "Public site CSS mobile navbar test passed.\n";
