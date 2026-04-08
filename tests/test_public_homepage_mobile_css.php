<?php

$cssPath = dirname(__DIR__) . '/assets/css/public/site.css';
$css = file_get_contents($cssPath);

if (!is_string($css) || $css === '') {
    throw new RuntimeException('Expected public site CSS to be readable.');
}

$mobileBreakpointStart = strpos($css, '@media (max-width: 767.98px)');
if ($mobileBreakpointStart === false) {
    throw new RuntimeException('Expected public site CSS to expose the mobile breakpoint block.');
}

$nextMediaStart = strpos($css, '@media', $mobileBreakpointStart + 1);
$mobileCss = $nextMediaStart === false
    ? substr($css, $mobileBreakpointStart)
    : substr($css, $mobileBreakpointStart, $nextMediaStart - $mobileBreakpointStart);

if (!is_string($mobileCss) || $mobileCss === '') {
    throw new RuntimeException('Expected mobile CSS block to be readable.');
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

echo "Public homepage mobile CSS test passed.\n";
