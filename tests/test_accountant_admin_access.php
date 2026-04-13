#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/admin_users.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function assertFalse(bool $condition, string $message): void
{
    assertTrue(!$condition, $message);
}

function assertStrictEqual(string $label, mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $label . " failed.\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

assertStrictEqual(
    'supported admin account types',
    ['standard', 'accountant'],
    bdta_valid_admin_account_types()
);

assertTrue(
    bdta_session_admin_account_type_needs_refresh([
        'user_type' => 'admin',
        'admin_id' => 7,
    ], 1000),
    'Expected authenticated admin sessions without a cached account type to refresh immediately.'
);

assertFalse(
    bdta_session_admin_account_type_needs_refresh([
        'user_type' => 'admin',
        'admin_id' => 7,
        'admin_account_type' => 'accountant',
        'admin_account_type_refreshed_at' => 701,
    ], 1000),
    'Expected recently refreshed accountant sessions to skip the database refresh.'
);

assertTrue(
    bdta_session_admin_account_type_needs_refresh([
        'user_type' => 'admin',
        'admin_id' => 7,
        'admin_account_type' => 'accountant',
        'admin_account_type_refreshed_at' => 700,
    ], 1000),
    'Expected stale accountant sessions to refresh once the cache TTL expires.'
);

assertFalse(
    bdta_session_admin_account_type_needs_refresh([
        'user_type' => 'client',
        'admin_id' => 7,
        'admin_account_type' => 'accountant',
        'admin_account_type_refreshed_at' => 1,
    ], 1000),
    'Expected non-admin sessions not to trigger admin account-type refresh checks.'
);

assertTrue(
    bdta_session_admin_is_accountant([
        'user_type' => 'admin',
        'admin_account_type' => 'accountant',
    ]),
    'Expected accountant session helper to detect accountant admins.'
);

assertFalse(
    bdta_session_admin_is_accountant([
        'user_type' => 'admin',
        'admin_account_type' => 'standard',
    ]),
    'Expected standard admin sessions not to be treated as accountant admins.'
);

assertTrue(
    bdta_is_accountant_allowed_admin_path('/client/invoices_list.php'),
    'Expected accountant admins to keep invoice-list access.'
);

assertTrue(
    bdta_is_accountant_allowed_admin_path('/client/expenses_list.php'),
    'Expected accountant admins to keep expense-list access.'
);

assertTrue(
    bdta_is_accountant_allowed_admin_path('/client/reports_financial.php'),
    'Expected accountant admins to keep financial report access.'
);

assertFalse(
    bdta_is_accountant_allowed_admin_path('/client/settings.php'),
    'Expected accountant admins to remain blocked from settings.'
);

assertFalse(
    bdta_is_accountant_allowed_admin_path('/client/../settings.php'),
    'Expected accountant path checks to reject traversal-style paths.'
);

fwrite(STDOUT, "Accountant admin access checks passed.\n");
