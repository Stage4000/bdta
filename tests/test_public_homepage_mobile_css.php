<?php

$cssPath = dirname(__DIR__) . '/assets/css/public/site.css';
$css = file_get_contents($cssPath);

if (!is_string($css) || $css === '') {
    throw new RuntimeException('Expected public site CSS to be readable.');
}

if (!str_contains($css, '@media (max-width: 767.98px)')) {
    throw new RuntimeException('Expected public site CSS to include the mobile breakpoint.');
}

if (!str_contains($css, "html,\n    body {\n        overflow-x: clip;")) {
    throw new RuntimeException('Expected mobile CSS to clip root horizontal overflow.');
}

if (!str_contains($css, "[data-aos],\n    [data-aos].aos-animate {")) {
    throw new RuntimeException('Expected mobile CSS to target animated homepage sections.');
}

if (!str_contains($css, 'transform: none !important;')) {
    throw new RuntimeException('Expected mobile CSS to neutralize mobile AOS transforms.');
}

if (!str_contains($css, 'transition-property: none !important;')) {
    throw new RuntimeException('Expected mobile CSS to disable mobile AOS transition-driven offsets.');
}

echo "Public homepage mobile CSS test passed.\n";
