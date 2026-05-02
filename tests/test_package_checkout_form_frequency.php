#!/usr/bin/env php
<?php

function assertPackageFormFrequency(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function readFixture(string $path, string $label): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, sprintf('Test setup failed: unable to read %s', $label) . PHP_EOL);
        exit(1);
    }

    return $contents;
}

$package_checkout = readFixture(dirname(__DIR__) . '/backend/includes/package_checkout.php', 'package_checkout.php');
$package_detail = readFixture(dirname(__DIR__) . '/client/package_detail.php', 'package_detail.php');
$form_frequency = readFixture(dirname(__DIR__) . '/tests/test_form_required_frequency.php', 'test_form_required_frequency.php');

assertPackageFormFrequency(
    str_contains($package_checkout, 'required_frequency, appointment_type_id')
        && str_contains($package_checkout, 'function bdta_get_package_checkout_form_state')
        && str_contains($package_checkout, 'bdta_form_template_needs_completion(')
        && str_contains($package_checkout, 'bdta_find_package_checkout_client_by_email')
        && str_contains($package_checkout, 'No re-submission is needed for this package purchase.'),
    'Package checkout should reuse form-frequency helpers and client email lookup before requiring attached forms again.'
);
assertPackageFormFrequency(
    str_contains($package_detail, '$effective_attached_form = $attached_form;')
        && str_contains($package_detail, '$attached_form_skip_message =')
        && str_contains($package_detail, 'bdta_get_package_checkout_form_state($conn, $attached_form, $package_checkout_form_email)')
        && str_contains($package_detail, "escape(\$attached_form_skip_message)")
        && str_contains($package_detail, 'alert alert-success')
        && str_contains($package_detail, 'bdta_validate_package_form_submission($effective_attached_form'),
    'Package detail checkout should hide and stop validating attached forms that are already current for the buyer.'
);
assertPackageFormFrequency(
    str_contains($form_frequency, 'bdta_form_template_needs_completion(')
        && str_contains($form_frequency, 'Expected per-appointment forms to be skipped')
        && str_contains($form_frequency, 'Expected once-per-pet forms to be skipped'),
    'Required-frequency behavior should remain covered by the shared form frequency helper regression test.'
);

echo "Package checkout form frequency wiring checks passed.\n";
