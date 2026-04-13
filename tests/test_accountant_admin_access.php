#!/usr/bin/env php
<?php

function bdta_assert_contains(string $contents, string $needle, string $message): void
{
    if (!str_contains($contents, $needle)) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function bdta_read_file(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, 'Unable to read test fixture: ' . $path . PHP_EOL);
        exit(1);
    }

    return $contents;
}

$root = dirname(__DIR__);
$settings = bdta_read_file($root . '/client/settings.php');
$header = bdta_read_file($root . '/backend/includes/header.php');
$invoicesList = bdta_read_file($root . '/client/invoices_list.php');
$expensesList = bdta_read_file($root . '/client/expenses_list.php');
$invoicesView = bdta_read_file($root . '/client/invoices_view.php');

bdta_assert_contains($settings, 'name="new_admin_account_type"', 'Admin settings should expose an account type selector when creating admin users.');
bdta_assert_contains($settings, 'Accountant (read-only accounting)', 'Admin settings should label the accountant account type clearly.');
bdta_assert_contains($header, 'bdta_session_admin_is_accountant($_SESSION)', 'Sidebar navigation should recognize accountant sessions.');
bdta_assert_contains($invoicesList, '$can_modify_accounting = !bdta_session_admin_is_accountant($_SESSION);', 'Invoice list should compute accountant read-only access.');
bdta_assert_contains($expensesList, 'Your accountant account has read-only expense access.', 'Expense list should block accountant write actions.');
bdta_assert_contains($invoicesView, 'Your accountant account has read-only invoice access.', 'Invoice view should block accountant write actions.');

fwrite(STDOUT, "Accountant admin access checks passed.\n");
