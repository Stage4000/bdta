#!/usr/bin/env php
<?php

function guard_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function read_source(string $relative_path): string
{
    $absolute_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . $relative_path;
    $contents = file_get_contents($absolute_path);
    if ($contents === false) {
        fwrite(STDERR, 'Unable to read source file: ' . $relative_path . PHP_EOL);
        exit(1);
    }

    return $contents;
}

$require_login_files = [
    'client/appointment_types_edit.php',
    'client/appointment_types_list.php',
    'client/packages_edit.php',
    'client/packages_list.php',
    'client/quotes_create.php',
    'client/quotes_list.php',
    'client/contract_templates_edit.php',
    'client/client_packages_manage.php',
];

foreach ($require_login_files as $file) {
    guard_assert(
        str_contains(read_source($file), 'requireLogin();'),
        $file . ' should route through requireLogin().'
    );
}

guard_assert(
    str_contains(read_source('client/contract_templates_get.php'), 'bdta_session_admin_is_accountant'),
    'contract_templates_get.php should enforce accountant restrictions explicitly.'
);
guard_assert(
    str_contains(read_source('client/time_tracker.php'), 'bdta_session_admin_is_accountant'),
    'time_tracker.php should enforce accountant restrictions explicitly for POST requests.'
);

$post_delete_files = [
    'client/appointment_types_delete.php',
    'client/contract_templates_delete.php',
    'client/form_templates_delete.php',
    'client/workflows_delete.php',
];

foreach ($post_delete_files as $file) {
    $source = read_source($file);
    guard_assert(str_contains($source, 'isPostRequest()'), $file . ' should reject non-POST deletes.');
    guard_assert(str_contains($source, 'requireValidCsrfToken'), $file . ' should require CSRF tokens.');
}

guard_assert(
    str_contains(read_source('client/packages_edit.php'), "isset(\$_POST['delete_package'])"),
    'Package deletion should be POST-only.'
);

echo "Admin route guard tests passed.\n";
