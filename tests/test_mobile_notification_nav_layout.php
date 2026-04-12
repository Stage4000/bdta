<?php

$sharedUiCssPath = dirname(__DIR__) . '/assets/css/shared-ui.css';
$sharedUiCss = file_get_contents($sharedUiCssPath);

if ($sharedUiCss === false) {
    throw new RuntimeException('Failed to read shared UI CSS file.');
}

if ($sharedUiCss === '') {
    throw new RuntimeException('Shared UI CSS file is empty.');
}

$mobileBreakpointStart = strpos($sharedUiCss, '@media (max-width: 768px)');
if ($mobileBreakpointStart === false) {
    throw new RuntimeException('Expected shared UI CSS to expose the mobile breakpoint block.');
}

$nextMediaStart = strpos($sharedUiCss, '@media', $mobileBreakpointStart + 1);
$mobileCss = $nextMediaStart === false
    ? substr($sharedUiCss, $mobileBreakpointStart)
    : substr($sharedUiCss, $mobileBreakpointStart, $nextMediaStart - $mobileBreakpointStart);

if ($mobileCss === '') {
    throw new RuntimeException('Failed to extract shared UI mobile CSS block.');
}

if (!preg_match('/\.app-mobile-navbar \.navbar-toggler\s*\{(?P<rule>[^}]*)\}/s', $mobileCss, $navbarTogglerMatches)) {
    throw new RuntimeException('Expected mobile CSS to target the mobile navbar toggler.');
}

$navbarTogglerRule = $navbarTogglerMatches['rule'];
if (!str_contains($navbarTogglerRule, 'margin-right: 4.25rem;')) {
    throw new RuntimeException('Expected mobile navbar toggler to reserve space for the notification button.');
}

echo "Mobile notification nav layout test passed.\n";
