#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/public/includes/public_services.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$legacy_homepage = <<<HTML
<section id="services" class="homepage-section">
    <div class="container homepage-section-shell">
        <div class="text-center mb-5 homepage-section-heading">
            <h2 class="display-5 fw-bold mb-3">Our Training Packages</h2>
        </div>
        <div id="packages-grid" class="row g-4"></div>
        <div id="packages-empty" class="text-center py-5 d-none"></div>
    </div>
</section>
HTML;

$legacy_injected = bdta_inject_public_services_into_homepage($legacy_homepage);
assertTrue(str_contains($legacy_injected, 'id="services-grid"'), 'Expected legacy homepage markup to receive the services grid.');
assertTrue(str_contains($legacy_injected, 'Single Booking Services'), 'Expected legacy homepage markup to receive the services heading.');
assertTrue(substr_count($legacy_injected, 'id="services-grid"') === 1, 'Expected only one services grid to be injected.');
assertTrue(substr_count($legacy_injected, 'Training Packages') === 1, 'Expected legacy homepage injection to avoid duplicating the packages heading.');
assertTrue(
    strpos($legacy_injected, 'Single Booking Services') < strpos($legacy_injected, 'Our Training Packages')
        && strpos($legacy_injected, 'Our Training Packages') < strpos($legacy_injected, 'id="packages-grid"'),
    'Expected the original packages heading to remain directly above the packages grid after injection.'
);
assertTrue(str_contains($legacy_injected, 'id="packages-grid"'), 'Expected package grid to remain present after injection.');
assertTrue($legacy_injected === bdta_inject_public_services_into_homepage($legacy_injected), 'Expected legacy homepage injection to be idempotent.');

$legacy_with_spacing = <<<HTML
<section id="services">
    <div class="container">
        <div id = "services-grid" class="row g-4"></div>
        <div id = "packages-grid" class="row g-4"></div>
    </div>
</section>
HTML;
assertTrue($legacy_with_spacing === bdta_inject_public_services_into_homepage($legacy_with_spacing), 'Expected existing services grid markup with spaced attributes not to be duplicated.');

$module_homepage = <<<HTML
<main>
  <section class="bdta-packages-module py-5">
    <div class="container py-5">
      <div class="bdta-packages-grid row g-4"></div>
    </div>
  </section>
</main>
HTML;

$module_injected = bdta_inject_public_services_into_homepage($module_homepage);
assertTrue(str_contains($module_injected, 'class="bdta-services-module py-5"'), 'Expected block-based homepage markup to receive a services module.');
assertTrue(substr_count($module_injected, 'bdta-services-module') === 1, 'Expected only one services module to be injected.');
assertTrue(str_contains($module_injected, 'class="bdta-packages-module py-5"'), 'Expected package module to remain present after injection.');
assertTrue($module_injected === bdta_inject_public_services_into_homepage($module_injected), 'Expected module homepage injection to be idempotent.');

$module_with_existing_services = <<<HTML
<main>
  <section data-block="hero" class = "bdta-services-module py-5"></section>
  <section class = "bdta-packages-module py-5"></section>
</main>
HTML;
assertTrue($module_with_existing_services === bdta_inject_public_services_into_homepage($module_with_existing_services), 'Expected existing services module markup with spaced class attributes not to be duplicated.');

$unrelated_html = '<section><p>No package markup here.</p></section>';
assertTrue($unrelated_html === bdta_inject_public_services_into_homepage($unrelated_html), 'Expected unrelated HTML to remain unchanged.');

echo "Public services homepage injection test passed.\n";
