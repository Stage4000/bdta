<?php

$cssPath = dirname(__DIR__) . '/assets/css/public/site.css';
$css = file_get_contents($cssPath);

if ($css === false) {
    throw new RuntimeException('Failed to read public site CSS file.');
}

if ($css === '') {
    throw new RuntimeException('Public site CSS file is empty.');
}

$mobileBreakpointStart = strpos($css, '@media (max-width: 767.98px)');
if ($mobileBreakpointStart === false) {
    throw new RuntimeException('Expected public site CSS to expose the mobile breakpoint block.');
}

$nextMediaStart = strpos($css, '@media', $mobileBreakpointStart + 1);
$mobileCss = $nextMediaStart === false
    ? substr($css, $mobileBreakpointStart)
    : substr($css, $mobileBreakpointStart, $nextMediaStart - $mobileBreakpointStart);

if ($mobileCss === '') {
    throw new RuntimeException('Failed to extract mobile CSS block.');
}

if (!preg_match('/html,\s*body\s*\{(?P<rule>[^}]*)\}/s', $mobileCss, $rootRuleMatches)) {
    throw new RuntimeException('Expected mobile CSS to include the root overflow rule.');
}

$rootRule = $rootRuleMatches['rule'];
if (!str_contains($rootRule, 'overflow-x: clip;')) {
    throw new RuntimeException('Expected mobile CSS to clip root horizontal overflow.');
}

if (!preg_match('/\[data-aos\],\s*\[data-aos\]\.aos-animate\s*\{(?P<rule>[^}]*)\}/s', $mobileCss, $aosRuleMatches)) {
    throw new RuntimeException('Expected mobile CSS to target animated homepage sections.');
}

$aosRule = $aosRuleMatches['rule'];
if (!str_contains($aosRule, 'transform: none !important;')) {
    throw new RuntimeException('Expected mobile CSS to neutralize mobile AOS transforms.');
}

if (!str_contains($aosRule, 'transition-property: none !important;')) {
    throw new RuntimeException('Expected mobile CSS to disable mobile AOS transition-driven offsets.');
}

if (!preg_match('/\.btn\.public-theme-toggle\s*\{(?P<rule>[^}]*)\}/s', $css, $toggleRuleMatches)) {
    throw new RuntimeException('Expected shared public CSS to include theme toggle positioning.');
}

$toggleRule = $toggleRuleMatches['rule'];
if (!str_contains($toggleRule, 'top: auto;')) {
    throw new RuntimeException('Expected shared public CSS to clear the top-edge theme toggle placement.');
}

if (!str_contains($toggleRule, 'bottom: calc(1rem + env(safe-area-inset-bottom, 0px));')) {
    throw new RuntimeException('Expected shared public CSS to anchor the shared theme toggle away from the navigation area.');
}

if (!str_contains($toggleRule, 'left: 1rem;')) {
    throw new RuntimeException('Expected shared public CSS to place the shared theme toggle away from the right-edge login button.');
}

if (!preg_match('/\.btn\.public-theme-toggle\s*\{(?P<rule>[^}]*)\}/s', $mobileCss, $mobileToggleRuleMatches)) {
    throw new RuntimeException('Expected mobile CSS to include a theme toggle override.');
}

$mobileToggleRule = $mobileToggleRuleMatches['rule'];
if (!str_contains($mobileToggleRule, 'right: 1rem;')) {
    throw new RuntimeException('Expected mobile CSS to move the shared theme toggle away from the bottom-left widget area.');
}

if (!str_contains($mobileToggleRule, 'left: auto;')) {
    throw new RuntimeException('Expected mobile CSS to clear the shared theme toggle left offset.');
}

if (!str_contains($mobileToggleRule, 'bottom: calc(5rem + env(safe-area-inset-bottom, 0px));')) {
    throw new RuntimeException('Expected mobile CSS to leave extra room for bottom-edge controls on the right.');
}

echo "Public homepage mobile CSS test passed.\n";
