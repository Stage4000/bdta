<?php

/**
 * Insert the public single-booking services markup into legacy homepage HTML when
 * the saved homepage predates the new services subsection.
 */
function bdta_get_public_services_legacy_markup(): string
{
    return <<<HTML
            <div class="text-center mb-4" data-aos="fade-up">
                <h3 class="fw-bold mb-2">Single Booking Services</h3>
                <p class="text-muted mb-0">Book one-on-one services and other standalone appointments online</p>
            </div>

            <div id="services-grid" class="row g-4">
                <div id="services-loading" class="col-12 text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading services…</span>
                    </div>
                    <p class="text-muted mt-3">Loading services…</p>
                </div>
            </div>

            <div id="services-empty" class="text-center py-5 d-none">
                <i class="fas fa-dog display-4 text-muted mb-3"></i>
                <p class="lead text-muted">No single booking services are currently available. Check back soon!</p>
                <a href="#contact" class="btn btn-outline-primary">Contact Us</a>
            </div>

            <div class="text-center mt-5 mb-4" data-aos="fade-up">
                <h3 class="fw-bold mb-2">Training Packages</h3>
                <p class="text-muted mb-0">Bundled training programs designed to set your dog up for success</p>
            </div>

HTML;
}

function bdta_get_public_services_module_markup(): string
{
    return <<<HTML
<section class="bdta-services-module py-5">
  <div class="container py-5">
    <div class="text-center mb-5">
      <h2 class="display-5 fw-bold mb-3">Single Booking Services</h2>
      <p class="lead text-muted">Book one-on-one services and other standalone appointments online</p>
    </div>
    <div class="bdta-services-grid row g-4">
      <div class="bdta-services-loading col-12 text-center py-5">
        <div class="spinner-border text-primary" role="status">
          <span class="visually-hidden">Loading services…</span>
        </div>
        <p class="text-muted mt-3">Loading services…</p>
      </div>
    </div>
    <div class="bdta-services-empty text-center py-5 d-none">
      <i class="fas fa-dog display-4 text-muted mb-3"></i>
      <p class="lead text-muted">No single booking services are currently available. Check back soon!</p>
      <a href="#contact" class="btn btn-outline-primary">Contact Us</a>
    </div>
  </div>
</section>
HTML;
}

function bdta_inject_public_services_into_homepage(string $html): string
{
    if ($html === ''
        || str_contains($html, 'id="services-grid"')
        || str_contains($html, "id='services-grid'")
        || str_contains($html, 'class="bdta-services-module')
        || str_contains($html, "class='bdta-services-module")
    ) {
        return $html;
    }

    $legacy_markup = bdta_get_public_services_legacy_markup();
    $legacy_result = preg_replace(
        '/(\s*)(<div\b[^>]*\bid=(["\'])packages-grid\3[^>]*>)/i',
        '$1' . $legacy_markup . '$1$2',
        $html,
        1,
        $legacy_count
    );
    if ($legacy_count === 1 && is_string($legacy_result)) {
        return $legacy_result;
    }

    $module_markup = bdta_get_public_services_module_markup();
    $module_result = preg_replace(
        '/(\s*)(<section\b[^>]*class=(["\'])(?:(?!\3).)*\bbdta-packages-module\b(?:(?!\3).)*\3[^>]*>)/is',
        '$1' . $module_markup . '$1$2',
        $html,
        1,
        $module_count
    );
    if ($module_count === 1 && is_string($module_result)) {
        return $module_result;
    }

    return $html;
}
