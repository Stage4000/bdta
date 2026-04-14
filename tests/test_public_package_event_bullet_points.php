#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/bullet_points.php';

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

$normalized = bdta_normalize_bullet_point_text("  First item \r\n\r\nSecond item\n  Third item  ");
bdta_assert($normalized === "First item\nSecond item\nThird item", 'Bullet-point normalization should trim lines and discard blanks.');
bdta_assert(
    bdta_parse_bullet_points("One\n\nTwo\r\n Three ") === ['One', 'Two', 'Three'],
    'Bullet-point parsing should return trimmed non-empty lines in order.'
);
bdta_assert(bdta_normalize_bullet_point_text(" \n \r\n ") === '', 'Whitespace-only bullet-point text should normalize to an empty string.');
bdta_assert(bdta_parse_bullet_points(null) === [], 'Null bullet-point text should parse to an empty list.');

$database = bdta_read_file(dirname(__DIR__) . '/backend/includes/database.php', 'database.php');
$packages_edit = bdta_read_file(dirname(__DIR__) . '/client/packages_edit.php', 'packages_edit.php');
$appointment_types_edit = bdta_read_file(dirname(__DIR__) . '/client/appointment_types_edit.php', 'appointment_types_edit.php');
$api_packages = bdta_read_file(dirname(__DIR__) . '/backend/public/api_packages.php', 'api_packages.php');
$api_events = bdta_read_file(dirname(__DIR__) . '/backend/public/api_events.php', 'api_events.php');
$site_js = bdta_read_file(dirname(__DIR__) . '/assets/js/public/site.js', 'site.js');
$modules_js = bdta_read_file(dirname(__DIR__) . '/assets/js/public/modules.js', 'modules.js');

bdta_assert(
    str_contains($database, 'ALTER TABLE packages ADD COLUMN bullet_points TEXT'),
    'Packages should auto-migrate a bullet_points column.'
);
bdta_assert(
    str_contains($database, 'ALTER TABLE appointment_types ADD COLUMN bullet_points TEXT'),
    'Appointment types should auto-migrate a bullet_points column for event bullets.'
);
bdta_assert(
    str_contains($packages_edit, 'name="bullet_points"'),
    'Package editor should expose a bullet_points field.'
);
bdta_assert(
    str_contains($packages_edit, 'Public Package Bullet Points'),
    'Package editor should label the public package bullet-point field.'
);
bdta_assert(
    str_contains($appointment_types_edit, 'id="event_bullet_points_section"'),
    'Appointment type editor should include a dedicated public event bullet-point section.'
);
bdta_assert(
    str_contains($appointment_types_edit, 'toggleEventBulletPoints'),
    'Appointment type editor should toggle the event bullet-point section with event settings.'
);
bdta_assert(
    str_contains($api_packages, "'bullet_points'   => bdta_parse_bullet_points"),
    'Packages API should expose parsed bullet points.'
);
bdta_assert(
    str_contains($api_events, "'bullet_points'    => bdta_parse_bullet_points"),
    'Events API should expose parsed bullet points.'
);
bdta_assert(
    str_contains($site_js, 'renderBulletSection(pkg.bullet_points') && str_contains($site_js, 'renderBulletSection(evt.bullet_points'),
    'Homepage package and event cards should render bullet-point sections.'
);
bdta_assert(
    str_contains($modules_js, 'renderBulletSection(pkg.bullet_points') && str_contains($modules_js, 'renderBulletSection(evt.bullet_points'),
    'Site-editor package and event modules should render bullet-point sections.'
);

echo "Public package/event bullet-point checks passed.\n";
