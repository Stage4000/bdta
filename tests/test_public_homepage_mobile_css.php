<?php

$cssPath = dirname(__DIR__) . '/assets/css/public/site.css';
$css = file_get_contents($cssPath);

if (!is_string($css) || $css === '') {
    throw new RuntimeException('Expected public site CSS to be readable.');
}

if (!str_contains($css, '@media (max-width: 767.98px)')) {
    throw new RuntimeException('Expected public site CSS to include the mobile breakpoint.');
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

if (!preg_match('/html,\s*body\s*\{[\s\S]*?overflow-x:\s*clip;[\s\S]*?\}/', $mobileCss)) {
    throw new RuntimeException('Expected mobile CSS to clip root horizontal overflow.');
}

$aosMobileRulePattern = '/\[data-aos\],\s*\[data-aos\]\.aos-animate\s*\{[\s\S]*?transform:\s*none\s*!important;[\s\S]*?transition-property:\s*none\s*!important;[\s\S]*?\}/';
if (!preg_match($aosMobileRulePattern, $mobileCss)) {
    throw new RuntimeException('Expected mobile CSS to target animated homepage sections.');
}

echo "Public homepage mobile CSS test passed.\n";
