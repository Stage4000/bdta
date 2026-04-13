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

function assertSameValue(string $label, mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $label . " failed.\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$conn = new PDO('sqlite::memory:');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->exec("
    CREATE TABLE admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        email TEXT NOT NULL,
        account_type TEXT NOT NULL DEFAULT 'standard',
        can_manage_admin_users INTEGER NOT NULL DEFAULT 0,
        can_manage_api_keys INTEGER NOT NULL DEFAULT 0
    )
");

$conn->exec("
    INSERT INTO admin_users (id, username, password_hash, email, account_type, can_manage_admin_users, can_manage_api_keys)
    VALUES
        (1, 'admin', 'hash', 'admin@example.com', 'main', 1, 1),
        (2, 'beta', 'hash', 'beta@example.com', 'standard', 1, 0),
        (3, 'alpha', 'hash', 'alpha@example.com', 'standard', 0, 0),
        (4, 'accounting', 'hash', 'accounting@example.com', 'accountant', 1, 1)
");

$admin_users = bdta_list_admin_users($conn);
assertSameValue('main admin sorted first', 'admin', $admin_users[0]['username']);
assertTrue($admin_users[0]['is_main_account'] === true, 'Expected seeded admin account to be flagged as main.');
assertTrue($admin_users[0]['can_manage_api_keys'] === true, 'Expected main admin to retain API-key access.');
assertSameValue('secondary admin alphabetical order', 'accounting', $admin_users[1]['username']);
assertSameValue('tertiary admin alphabetical order', 'alpha', $admin_users[2]['username']);
assertTrue($admin_users[3]['can_manage_admin_users'] === true, 'Expected delegated admin-user management permission to be preserved.');

$current_admin = bdta_current_admin_user($conn, ['user_type' => 'admin', 'admin_id' => 2]);
assertTrue($current_admin !== null, 'Expected current admin lookup to return an admin user.');
assertTrue(bdta_admin_user_can_manage_admin_users($current_admin) === true, 'Expected delegated admin to manage admin users.');
assertTrue(bdta_admin_user_can_manage_api_keys($current_admin) === false, 'Expected delegated admin without API-key access to be restricted.');
assertTrue(bdta_current_admin_user($conn, ['user_type' => 'client', 'admin_id' => 2]) === null, 'Expected non-admin sessions not to map to admin_users records.');
assertTrue(bdta_is_valid_admin_username('trainer.one') === true, 'Expected valid admin usernames to pass validation.');
assertTrue(bdta_is_valid_admin_username('bad user') === false, 'Expected spaces to be rejected in admin usernames.');
assertTrue(bdta_is_valid_admin_username('bad..user') === false, 'Expected repeated separators to be rejected in admin usernames.');

$filtered_settings = bdta_filter_api_key_settings([
    ['key' => 'email_from_address', 'label' => 'From Email Address'],
    ['key' => 'smtp_host', 'label' => 'SMTP Host'],
    ['key' => 'imap_host', 'label' => 'IMAP Host'],
    ['key' => 'tawk_to_property_id', 'label' => 'Tawk.to Property ID'],
    ['key' => 'db_password', 'label' => 'Database Password'],
    ['key' => 'sendgrid_api_key', 'label' => 'SendGrid API Key'],
    ['key' => 'google_oauth_client_secret', 'label' => 'OAuth Secret'],
], false);
assertSameValue('non-sensitive settings remain visible', 1, count($filtered_settings));
assertSameValue('non-sensitive settings key preserved', 'email_from_address', $filtered_settings[0]['key']);
$filtered_setting_keys = array_column($filtered_settings, 'key');
assertSameValue('only safe setting key remains', ['email_from_address'], $filtered_setting_keys);
assertTrue(!in_array('smtp_host', $filtered_setting_keys, true), 'Expected SMTP settings to be filtered.');
assertTrue(!in_array('imap_host', $filtered_setting_keys, true), 'Expected IMAP settings to be filtered.');
assertTrue(!in_array('tawk_to_property_id', $filtered_setting_keys, true), 'Expected Tawk.to IDs to be filtered.');
assertTrue(!in_array('db_password', $filtered_setting_keys, true), 'Expected database settings to be filtered.');
assertTrue(!in_array('sendgrid_api_key', $filtered_setting_keys, true), 'Expected API keys to be filtered.');
assertTrue(!in_array('google_oauth_client_secret', $filtered_setting_keys, true), 'Expected OAuth secrets to be filtered.');
$multi_filtered_settings = bdta_filter_api_key_settings([
    ['key' => 'email_from_address', 'label' => 'From Email Address'],
    ['key' => 'email_from_name', 'label' => 'From Name'],
    ['key' => 'smtp_port', 'label' => 'SMTP Port'],
    ['key' => 'db_host', 'label' => 'Database Host'],
], false);
assertSameValue(
    'multiple safe settings remain visible',
    ['email_from_address', 'email_from_name'],
    array_column($multi_filtered_settings, 'key')
);
assertTrue(bdta_session_is_authenticated_admin(['user_type' => 'admin', 'admin_id' => 2]) === true, 'Expected authenticated admin session helper to pass.');
assertTrue(bdta_session_is_authenticated_admin(['user_type' => 'client', 'admin_id' => 2]) === false, 'Expected non-admin session helper to fail.');
assertTrue(bdta_current_admin_can_manage_api_key_settings($conn, ['user_type' => 'admin', 'admin_id' => 1]) === true, 'Expected main admin helper to allow API-key settings.');
assertTrue(bdta_current_admin_can_manage_api_key_settings($conn, ['user_type' => 'admin', 'admin_id' => 2]) === false, 'Expected delegated admin without API-key access to remain restricted.');
$accountant_admin = bdta_current_admin_user($conn, ['user_type' => 'admin', 'admin_id' => 4]);
assertTrue($accountant_admin !== null, 'Expected accountant admin lookup to return an admin user.');
/** @var array{id: int, username: string, email: string, account_type: string, can_manage_admin_users: bool, can_manage_api_keys: bool, is_main_account: bool} $accountant_admin */
assertSameValue('accountant account type preserved', 'accountant', $accountant_admin['account_type']);
assertTrue(bdta_admin_user_is_accountant($accountant_admin) === true, 'Expected accountant helper to flag accountant accounts.');
assertTrue(bdta_admin_user_can_manage_admin_users($accountant_admin) === false, 'Expected accountant accounts to stay unable to manage admin users.');
assertTrue(bdta_admin_user_can_manage_api_keys($accountant_admin) === false, 'Expected accountant accounts to stay unable to manage API keys.');
assertTrue(bdta_admin_user_can_modify_accounting($accountant_admin) === false, 'Expected accountant accounts to remain read-only for accounting records.');
assertTrue(bdta_admin_user_can_modify_accounting($current_admin) === true, 'Expected standard admin accounts to retain accounting write access.');
assertTrue(bdta_session_admin_is_accountant(['user_type' => 'admin', 'admin_account_type' => 'accountant']) === true, 'Expected accountant session helper to detect accountant admins.');
assertTrue(bdta_session_admin_is_accountant(['user_type' => 'client', 'admin_account_type' => 'accountant']) === false, 'Expected non-admin accountant session helper to fail.');
assertTrue(bdta_is_accountant_allowed_admin_path('/client/invoices_list.php') === true, 'Expected invoices list to remain available to accountant accounts.');
assertTrue(bdta_is_accountant_allowed_admin_path('/client/reports_export.php') === true, 'Expected report exports to remain available to accountant accounts.');
assertTrue(bdta_is_accountant_allowed_admin_path('/client/settings.php') === false, 'Expected settings page to remain unavailable to accountant accounts.');
assertTrue(bdta_is_accountant_allowed_admin_path('/client/../settings.php') === false, 'Expected accountant path helper to reject traversal-style paths.');

fwrite(STDOUT, "Admin user helper tests passed.\n");
