#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/appointment_type_public_services.php';

function bdta_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function bdta_read_file(string $path, string $label): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, sprintf('Test setup failed: unable to read %s', $label) . PHP_EOL);
        exit(1);
    }

    return $contents;
}

$database = bdta_read_file(dirname(__DIR__) . '/backend/includes/database.php', 'database.php');
$appointment_types_edit = bdta_read_file(dirname(__DIR__) . '/client/appointment_types_edit.php', 'appointment_types_edit.php');
$api_services = bdta_read_file(dirname(__DIR__) . '/backend/public/api_services.php', 'api_services.php');
$site_js = bdta_read_file(dirname(__DIR__) . '/assets/js/public/site.js', 'site.js');
$site_css = bdta_read_file(dirname(__DIR__) . '/assets/css/public/site.css', 'site.css');
$modules_js = bdta_read_file(dirname(__DIR__) . '/assets/js/public/modules.js', 'modules.js');
$index_php = bdta_read_file(dirname(__DIR__) . '/index.php', 'index.php');
$index_html = bdta_read_file(dirname(__DIR__) . '/index.html', 'index.html');
$site_editor = bdta_read_file(dirname(__DIR__) . '/client/site_editor.php', 'site_editor.php');
$template_duplication = bdta_read_file(dirname(__DIR__) . '/backend/includes/template_duplication.php', 'template_duplication.php');

bdta_assert(
    str_contains($database, 'public_available INTEGER DEFAULT 0')
        && str_contains($database, 'ALTER TABLE appointment_types ADD COLUMN public_available INTEGER DEFAULT 0'),
    'Appointment types should persist a public_available flag.'
);
bdta_assert(
    bdta_appointment_type_can_show_in_public_services(0, 0) === true
        && bdta_appointment_type_can_show_in_public_services(1, 0) === false
        && bdta_appointment_type_can_show_in_public_services(0, 1) === false
        && bdta_normalize_appointment_type_public_available(1, 1, 0) === 0
        && bdta_normalize_appointment_type_public_available(1, 0, 1) === 0
        && bdta_normalize_appointment_type_public_available(1, 0, 0) === 1,
    'Appointment types should only remain public-services eligible when they are single-booking types.'
);
bdta_assert(
    str_contains($appointment_types_edit, 'id="public_available"')
        && str_contains($appointment_types_edit, 'Show in Public Services')
        && str_contains($appointment_types_edit, 'updatePublicAvailabilityToggle()')
        && str_contains($appointment_types_edit, 'publicToggle.dataset.lastEligibleChecked'),
    'Appointment type editor should expose a public services visibility toggle.'
);
bdta_assert(
    str_contains($api_services, 'public_available = 1')
        && str_contains($api_services, "AND is_group_class = 0")
        && str_contains($api_services, "AND is_mini_session = 0")
        && str_contains($api_services, "'booking_url'"),
    'Public services API should expose only eligible single-booking appointment types.'
);
bdta_assert(
    str_contains($site_js, 'loadServices();')
        && str_contains($site_js, "fetchJson('backend/public/api_services.php')")
        && str_contains($site_js, "document.getElementById('services-grid')")
        && str_contains($site_js, 'service.bullet_points')
        && str_contains($site_js, 'watchDynamicHomepageSections()')
        && str_contains($site_js, "data-bdta-loaded")
        && !str_contains($site_js, 'initServiceCardHover()'),
    'Homepage JavaScript should load and render public single-booking services.'
);
bdta_assert(
    str_contains($site_css, '.service-card:hover')
        && str_contains($site_css, '.service-card:hover .service-icon'),
    'Public service cards should keep their hover behavior through CSS so dynamic cards work immediately.'
);
bdta_assert(
    str_contains($modules_js, '.bdta-services-module')
        && str_contains($modules_js, "fetch('/backend/public/api_services.php')")
        && str_contains($modules_js, 'watchForDynamicModules'),
    'Site-editor modules should load and render public single-booking services.'
);
bdta_assert(
    str_contains($site_editor, "require_once '../backend/public/includes/public_services.php';")
        && str_contains($site_editor, 'bdta_inject_public_services_into_homepage')
        && str_contains($site_editor, "'/assets/js/public/site.js'")
        && str_contains($site_editor, "bm.add('bdta-services'")
        && str_contains($site_editor, 'servicesModuleMarkup'),
    'Site editor should inject homepage services content and expose a reusable BDTA services block.'
);
bdta_assert(
    str_contains($index_php, 'bdta_inject_public_services_into_homepage'),
    'Homepage renderer should inject the public services section into legacy published homepage content.'
);
bdta_assert(
    str_contains($index_html, 'id="services-grid"')
        && str_contains($index_html, 'Single Booking Services')
        && str_contains($index_html, 'id="services-empty"'),
    'Homepage services section should include a single-booking services grid and empty state.'
);
bdta_assert(
    str_contains($template_duplication, 'public_available,'),
    'Appointment type duplication should preserve the public services visibility flag.'
);

echo "Public services front-end checks passed.\n";
