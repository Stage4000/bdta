#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/achievements.php';

function bdta_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function bdta_read(string $path): string
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        fwrite(STDERR, 'Unable to read ' . $path . PHP_EOL);
        exit(1);
    }

    return $contents;
}

$assignment = [
    'id' => 42,
    'client_name' => 'Taylor Trainer',
    'dog_name' => 'Scout',
    'program_name' => 'Leash Foundations',
    'awarded_on' => '2026-04-27',
    'achievement_title' => 'Leash Legend',
    'achievement_description' => 'Completed the leash walking program.',
    'certificate_body_html' => '<p>{{client_name}} and {{dog_name}} completed {{program_name}} on {{award_date}}.</p>',
];

bdta_assert_true(bdta_achievement_mode_supports_badge('badge_only'), 'badge_only should expose badge UI.');
bdta_assert_true(!bdta_achievement_mode_supports_badge('certificate_only'), 'certificate_only should hide badge UI.');
bdta_assert_true(bdta_achievement_mode_supports_certificate('badge_certificate'), 'badge_certificate should expose certificate UI.');

$rendered_body = bdta_render_achievement_certificate_body($assignment['certificate_body_html'], $assignment);
bdta_assert_true(str_contains($rendered_body, 'Taylor Trainer'), 'Certificate body should inject the client name placeholder.');
bdta_assert_true(str_contains($rendered_body, 'Scout'), 'Certificate body should inject the dog name placeholder.');
bdta_assert_true(str_contains($rendered_body, 'Leash Foundations'), 'Certificate body should inject the program name placeholder.');

$rendered_html = bdta_render_achievement_certificate_html($assignment);
bdta_assert_true(str_contains($rendered_html, '/assets/images/bdta-logo.png'), 'Certificate HTML should reference the local BDTA logo asset.');
bdta_assert_true(str_contains($rendered_html, 'Download PDF'), 'Certificate HTML should include a PDF download action.');
bdta_assert_true(str_contains($rendered_html, 'window.print()'), 'Certificate HTML should include a print action.');
bdta_assert_true(str_contains($rendered_html, '?id=42&amp;download=1'), 'Certificate HTML should cast and escape the assignment ID in the download link.');

$extra_actions = [];
$extra_actions[] = [
    'label' => 'Back to achievements',
    'href' => 'achievements.php',
    'class' => 'secondary',
];
$rendered_html_with_back_link = bdta_render_achievement_certificate_html($assignment, $extra_actions);
bdta_assert_true(str_contains($rendered_html_with_back_link, 'Back to achievements'), 'Certificate HTML should support explicit extra action links.');

$pdf = bdta_generate_achievement_certificate_pdf($assignment);
bdta_assert_true(strpos($pdf, '%PDF-1.4') === 0, 'Certificate PDF renderer should emit a PDF payload.');
bdta_assert_true(str_contains($pdf, 'Certificate of Achievement'), 'Certificate PDF payload should include the achievement heading.');

$database_php = bdta_read(dirname(__DIR__) . '/backend/includes/database.php');
bdta_assert_true(str_contains($database_php, 'CREATE TABLE IF NOT EXISTS achievement_types'), 'Database bootstrap should create achievement_types.');
bdta_assert_true(str_contains($database_php, 'CREATE TABLE IF NOT EXISTS client_achievements'), 'Database bootstrap should create client_achievements.');
bdta_assert_true(str_contains($database_php, 'CREATE TABLE IF NOT EXISTS achievement_assignment_log'), 'Database bootstrap should create achievement_assignment_log.');

$clients_view = bdta_read(dirname(__DIR__) . '/client/clients_view.php');
bdta_assert_true(str_contains($clients_view, 'href="#achievements"'), 'Client profile should expose an Achievements tab.');
bdta_assert_true(str_contains($clients_view, 'Audit history'), 'Client profile achievements UI should show audit history.');
bdta_assert_true(str_contains($clients_view, 'certificate_template'), 'Client profile achievements UI should allow certificate template uploads.');
bdta_assert_true(str_contains($clients_view, 'achievement_certificate.php?id='), 'Client profile achievements UI should link to printable certificates.');
bdta_assert_true(!str_contains($clients_view, 'image/svg+xml'), 'Badge icon uploads should no longer accept SVG files.');
bdta_assert_true(str_contains($clients_view, "DateTimeImmutable::createFromFormat('!Y-m-d', \$awarded_on)"), 'Award dates should be strictly validated.');

$portal_header = bdta_read(dirname(__DIR__) . '/portal/includes/header.php');
bdta_assert_true(str_contains($portal_header, 'achievements.php'), 'Portal navigation should include an Achievements link.');

$portal_index = bdta_read(dirname(__DIR__) . '/portal/index.php');
bdta_assert_true(str_contains($portal_index, 'Earned Badges'), 'Portal dashboard should render an earned badges section.');
bdta_assert_true(str_contains($portal_index, "'label' => 'Achievements'"), 'Portal dashboard quick links should include Achievements.');

$portal_achievements = bdta_read(dirname(__DIR__) . '/portal/achievements.php');
bdta_assert_true(str_contains($portal_achievements, 'Download PDF'), 'Portal achievements page should expose certificate downloads.');
bdta_assert_true(str_contains($portal_achievements, 'Print certificate'), 'Portal achievements page should expose print actions.');

$admin_certificate = bdta_read(dirname(__DIR__) . '/client/achievement_certificate.php');
bdta_assert_true(str_contains($admin_certificate, 'Back to client'), 'Admin certificate endpoint should render a direct back action.');
bdta_assert_true(!str_contains($admin_certificate, "str_replace('<div class=\"certificate-actions\">'"), 'Admin certificate endpoint should not rely on brittle string replacement.');

$portal_certificate = bdta_read(dirname(__DIR__) . '/portal/achievement_certificate.php');
bdta_assert_true(str_contains($portal_certificate, 'Back to achievements'), 'Portal certificate endpoint should render a direct back action.');
bdta_assert_true(!str_contains($portal_certificate, "str_replace('<div class=\"certificate-actions\">'"), 'Portal certificate endpoint should not rely on brittle string replacement.');

bdta_assert_true(file_exists(dirname(__DIR__) . '/client/achievement_certificate.php'), 'Admin achievement certificate endpoint should exist.');
bdta_assert_true(file_exists(dirname(__DIR__) . '/portal/achievement_certificate.php'), 'Portal achievement certificate endpoint should exist.');
bdta_assert_true(file_exists(dirname(__DIR__) . '/assets/images/bdta-logo.png'), 'The BDTA logo asset should be stored locally for certificates.');

echo "Achievement feature checks passed.\n";
