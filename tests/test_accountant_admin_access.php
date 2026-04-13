#!/usr/bin/env php
<?php

function bdta_assert_contains(string $contents, string $needle, string $message): void
{
    if (!str_contains($contents, $needle)) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function bdta_assert_not_contains(string $contents, string $needle, string $message): void
{
    if (str_contains($contents, $needle)) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function bdta_read_accountant_access_file(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, 'Unable to read test fixture: ' . $path . PHP_EOL);
        exit(1);
    }

    return $contents;
}

$root = dirname(__DIR__);
$settings = bdta_read_accountant_access_file($root . '/client/settings.php');
$config = bdta_read_accountant_access_file($root . '/backend/includes/config.php');
$header = bdta_read_accountant_access_file($root . '/backend/includes/header.php');
$invoicesList = bdta_read_accountant_access_file($root . '/client/invoices_list.php');
$expensesList = bdta_read_accountant_access_file($root . '/client/expenses_list.php');
$invoicesView = bdta_read_accountant_access_file($root . '/client/invoices_view.php');

bdta_assert_contains($settings, 'name="new_admin_account_type"', 'Admin settings should expose an account type selector when creating admin users.');
bdta_assert_contains($settings, 'Accountant (read-only accounting)', 'Admin settings should label the accountant account type clearly.');
bdta_assert_contains($config, 'if (!is_array($admin_user)) {', 'Admin config should reject stale admin sessions when the admin user record no longer exists.');
bdta_assert_contains($config, '$session_cookie_path = scalar_string($cookie_params[\'path\'] ?? \'\');', 'Admin config should derive the session cookie path before clearing a stale admin session.');
bdta_assert_contains($config, "if (\$session_cookie_path === '') {", 'Admin config should normalize an empty session cookie path before clearing a stale admin session.');
bdta_assert_contains($config, 'setcookie(', 'Admin config should clear the session cookie before destroying a stale admin session.');
bdta_assert_contains($config, '$_SESSION[\'admin_account_type\'] = $admin_user[\'account_type\'];', 'Admin config should refresh the current session account type from the database.');
bdta_assert_contains($header, 'bdta_session_admin_is_accountant($_SESSION)', 'Sidebar navigation should recognize accountant sessions.');
bdta_assert_contains($invoicesList, '$can_modify_accounting = !bdta_session_admin_is_accountant($_SESSION);', 'Invoice list should compute accountant read-only access.');
bdta_assert_contains($expensesList, 'Your accountant account has read-only expense access.', 'Expense list should block accountant write actions.');
bdta_assert_contains($invoicesView, 'Your accountant account has read-only invoice access.', 'Invoice view should block accountant write actions.');
bdta_assert_contains($invoicesView, '<input type="hidden" name="csrf_token" value="<?= escape($csrf_token_value) ?>">', 'Invoice send form should include a CSRF token.');
bdta_assert_contains($invoicesView, "if (\$csrf_token_value === '' || \$submitted_csrf_token === '' || !hash_equals(\$csrf_token_value, \$submitted_csrf_token)) {", 'Invoice send handler should validate the CSRF token.');
bdta_assert_not_contains($settings, '<?php if (bdta_admin_user_is_accountant($admin_user)): ?>', 'Settings permissions badges should not duplicate the accountant-only branch inside the fallback display.');

fwrite(STDOUT, "Accountant admin access checks passed.\n");
