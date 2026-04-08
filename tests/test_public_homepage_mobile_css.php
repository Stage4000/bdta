<?php

$cssPath = dirname(__DIR__) . '/assets/css/public/site.css';
$css = file_get_contents($cssPath);

if (!is_string($css) || $css === '') {
    throw new RuntimeException('Expected public site CSS to be readable.');
}

if (!str_contains($css, '@media (max-width: 767.98px)')) {
    throw new RuntimeException('Expected public site CSS to include the mobile breakpoint.');
}

if (!preg_match('/@media\s*\(max-width:\s*767\.98px\)\s*\{(?P<block>.*)\}\s*$/s', $css, $matches)) {
    throw new RuntimeException('Expected public site CSS to expose the mobile breakpoint block.');
}

$mobileCss = $matches['block'];

if (
    !str_contains($mobileCss, 'html,')
    || !str_contains($mobileCss, 'body')
    || !str_contains($mobileCss, 'overflow-x: clip;')
) {
    throw new RuntimeException('Expected mobile CSS to clip root horizontal overflow.');
}

if (
    !str_contains($mobileCss, '[data-aos],')
    || !str_contains($mobileCss, '[data-aos].aos-animate')
) {
    throw new RuntimeException('Expected mobile CSS to target animated homepage sections.');
}

if (!str_contains($mobileCss, 'transform: none !important;')) {
    throw new RuntimeException('Expected mobile CSS to neutralize mobile AOS transforms.');
}

if (!str_contains($mobileCss, 'transition-property: none !important;')) {
    throw new RuntimeException('Expected mobile CSS to disable mobile AOS transition-driven offsets.');
}

echo "Public homepage mobile CSS test passed.\n";
