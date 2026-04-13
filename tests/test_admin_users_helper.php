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
        (3, 'alpha', 'hash', 'alpha@example.com', 'standard', 0, 0)
");

$admin_users = bdta_list_admin_users($conn);
assertSameValue('main admin sorted first', 'admin', $admin_users[0]['username']);
assertTrue($admin_users[0]['is_main_account'] === true, 'Expected seeded admin account to be flagged as main.');
assertTrue($admin_users[0]['can_manage_api_keys'] === true, 'Expected main admin to retain API-key access.');
assertSameValue('secondary admin alphabetical order', 'alpha', $admin_users[1]['username']);
assertTrue($admin_users[2]['can_manage_admin_users'] === true, 'Expected delegated admin-user management permission to be preserved.');

$current_admin = bdta_current_admin_user($conn, ['user_type' => 'admin', 'admin_id' => 2]);
assertTrue($current_admin !== null, 'Expected current admin lookup to return an admin user.');
assertTrue(bdta_admin_user_can_manage_admin_users($current_admin) === true, 'Expected delegated admin to manage admin users.');
assertTrue(bdta_admin_user_can_manage_api_keys($current_admin) === false, 'Expected delegated admin without API-key access to be restricted.');
assertTrue(bdta_current_admin_user($conn, ['user_type' => 'client', 'admin_id' => 2]) === null, 'Expected non-admin sessions not to map to admin_users records.');
assertTrue(bdta_is_valid_admin_username('trainer.one') === true, 'Expected valid admin usernames to pass validation.');
assertTrue(bdta_is_valid_admin_username('bad user') === false, 'Expected spaces to be rejected in admin usernames.');
assertTrue(bdta_is_valid_admin_username('bad..user') === false, 'Expected repeated separators to be rejected in admin usernames.');

$filtered_settings = bdta_filter_api_key_settings([
    ['key' => 'smtp_host', 'label' => 'SMTP Host'],
    ['key' => 'sendgrid_api_key', 'label' => 'SendGrid API Key'],
    ['key' => 'google_oauth_client_secret', 'label' => 'OAuth Secret'],
], false);
assertSameValue('non-sensitive settings remain visible', 1, count($filtered_settings));
assertSameValue('non-sensitive settings key preserved', 'smtp_host', $filtered_settings[0]['key']);

fwrite(STDOUT, "Admin user helper tests passed.\n");
