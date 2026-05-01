#!/usr/bin/env php
<?php

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
$packages_edit = bdta_read_file(dirname(__DIR__) . '/client/packages_edit.php', 'packages_edit.php');
$portal_header = bdta_read_file(dirname(__DIR__) . '/portal/includes/header.php', 'portal/includes/header.php');
$portal_index = bdta_read_file(dirname(__DIR__) . '/portal/index.php', 'portal/index.php');
$portal_packages = bdta_read_file(dirname(__DIR__) . '/portal/packages.php', 'portal/packages.php');

bdta_assert(
    str_contains($database, 'portal_available INTEGER DEFAULT 0')
        && str_contains($database, 'ALTER TABLE packages ADD COLUMN portal_available INTEGER DEFAULT 0'),
    'Packages should persist a portal_available flag.'
);
bdta_assert(
    str_contains($packages_edit, 'id="portal_available"')
        && str_contains($packages_edit, 'Available in Client Portal'),
    'Package editor should expose a client portal visibility toggle.'
);
bdta_assert(
    str_contains($portal_header, "packages.php")
        && str_contains($portal_header, 'fa-box-open'),
    'Portal navigation should include a Packages link.'
);
bdta_assert(
    str_contains($portal_index, "'href' => 'packages.php'")
        && str_contains($portal_index, "'label' => 'Packages'"),
    'Portal dashboard quick links should include Packages.'
);
bdta_assert(
    str_contains($portal_packages, 'portal_available = 1')
        && str_contains($portal_packages, 'share_token IS NOT NULL')
        && str_contains($portal_packages, "requirePortalLogin();")
        && str_contains($portal_packages, '/client/package_detail.php?token=')
        && str_contains($portal_packages, 'Available Packages'),
    'Portal packages page should require login, filter to portal-visible purchasable packages, and link into package checkout.'
);

echo "Portal package visibility checks passed.\n";
