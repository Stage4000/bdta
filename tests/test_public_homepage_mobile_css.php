<?php

$cssPath = dirname(__DIR__) . '/assets/css/public/site.css';
$css = file_get_contents($cssPath);

if (!is_string($css) || $css === '') {
    throw new RuntimeException('Expected public site CSS to be readable.');
}

if (!str_contains($css, '@media (max-width: 767.98px)')) {
    throw new RuntimeException('Expected public site CSS to include the mobile breakpoint.');
}

if (!preg_match('/@media\s*\(max-width:\s*767\.98px\)\s*\{[\s\S]*?html,\s*body\s*\{[\s\S]*?overflow-x:\s*clip;[\s\S]*?\}/', $css)) {
    throw new RuntimeException('Expected mobile CSS to clip root horizontal overflow.');
}

if (!preg_match('/@media\s*\(max-width:\s*767\.98px\)\s*\{[\s\S]*?\[data-aos\],\s*\[data-aos\]\.aos-animate\s*\{/', $css)) {
    throw new RuntimeException('Expected mobile CSS to target animated homepage sections.');
}

if (!preg_match('/@media\s*\(max-width:\s*767\.98px\)\s*\{[\s\S]*?\[data-aos\],\s*\[data-aos\]\.aos-animate\s*\{[\s\S]*?transform:\s*none\s*!important;/', $css)) {
    throw new RuntimeException('Expected mobile CSS to neutralize mobile AOS transforms.');
}

if (!preg_match('/@media\s*\(max-width:\s*767\.98px\)\s*\{[\s\S]*?\[data-aos\],\s*\[data-aos\]\.aos-animate\s*\{[\s\S]*?transition-property:\s*none\s*!important;/', $css)) {
    throw new RuntimeException('Expected mobile CSS to disable mobile AOS transition-driven offsets.');
}

echo "Public homepage mobile CSS test passed.\n";
