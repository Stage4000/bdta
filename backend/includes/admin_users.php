<?php
/**
 * Admin user helpers for management UI and per-user permissions.
 */

/**
 * @return list<string>
 */
function bdta_api_key_setting_keys(): array
{
    return [
        'sendgrid_api_key',
        'mailgun_api_key',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'smtp_debug',
        'imap_enabled',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'imap_username',
        'imap_password',
        'imap_folder',
        'imap_sync_days',
        'stripe_test_publishable_key',
        'stripe_test_secret_key',
        'stripe_live_publishable_key',
        'stripe_live_secret_key',
        'google_calendar_enabled',
        'google_calendar_id',
        'google_calendar_credentials_file',
        'google_oauth_client_id',
        'google_oauth_client_secret',
        'google_oauth_redirect_uri',
        'moxie_api_key',
        'tawk_to_property_id',
        'tawk_to_widget_id',
        'db_host',
        'db_port',
        'db_name',
        'db_user',
        'db_password',
    ];
}

function bdta_admin_users_scalar(mixed $value): string
{
    if (function_exists('scalar_string')) {
        return scalar_string($value);
    }

    if (is_string($value)) {
        return $value;
    }

    if (is_scalar($value)) {
        return (string) $value;
    }

    return '';
}

function bdta_admin_users_int(mixed $value): int
{
    if (function_exists('safe_int')) {
        return safe_int($value);
    }

    return is_numeric($value) ? (int) $value : 0;
}

function bdta_is_valid_admin_username(string $username): bool
{
    return preg_match('/^(?=.{3,64}$)[A-Za-z0-9]+(?:[._-][A-Za-z0-9]+)*$/', $username) === 1;
}

function bdta_normalize_admin_account_type(mixed $value, bool $is_main_account = false): string
{
    if ($is_main_account) {
        return 'main';
    }

    $account_type = strtolower(trim(bdta_admin_users_scalar($value)));

    return $account_type === 'accountant' ? 'accountant' : 'standard';
}

/**
 * @param array<string, mixed> $row
 */
function bdta_is_main_admin_account(array $row): bool
{
    $username = strtolower(trim(bdta_admin_users_scalar($row['username'] ?? '')));
    $account_type = strtolower(trim(bdta_admin_users_scalar($row['account_type'] ?? '')));

    return $username === 'admin' || $account_type === 'main';
}

/**
 * @param array<string, mixed> $row
 * @return array{id: int, username: string, email: string, account_type: string, can_manage_admin_users: bool, can_manage_api_keys: bool, is_main_account: bool}
 */
function bdta_normalize_admin_user(array $row): array
{
    $is_main_account = bdta_is_main_admin_account($row);
    $account_type = bdta_normalize_admin_account_type($row['account_type'] ?? 'standard', $is_main_account);
    $is_accountant = $account_type === 'accountant';

    return [
        'id' => bdta_admin_users_int($row['id'] ?? 0),
        'username' => bdta_admin_users_scalar($row['username'] ?? ''),
        'email' => bdta_admin_users_scalar($row['email'] ?? ''),
        'account_type' => $account_type,
        'can_manage_admin_users' => $is_main_account || (!$is_accountant && bdta_admin_users_int($row['can_manage_admin_users'] ?? 0) === 1),
        'can_manage_api_keys' => $is_main_account || (!$is_accountant && bdta_admin_users_int($row['can_manage_api_keys'] ?? 0) === 1),
        'is_main_account' => $is_main_account,
    ];
}

/**
 * @return list<array{id: int, username: string, email: string, account_type: string, can_manage_admin_users: bool, can_manage_api_keys: bool, is_main_account: bool}>
 */
function bdta_list_admin_users(PDO $conn): array
{
    $stmt = $conn->prepare("
        SELECT id, username, email, account_type, can_manage_admin_users, can_manage_api_keys
        FROM admin_users
        ORDER BY CASE WHEN username = 'admin' THEN 0 ELSE 1 END, username ASC, email ASC
    ");
    if (!$stmt instanceof PDOStatement) {
        throw new RuntimeException('Failed to prepare admin user list query.');
    }
    $stmt->execute();

    $users = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }

        /** @var array<string, mixed> $normalized_row */
        $normalized_row = $row;
        $users[] = bdta_normalize_admin_user($normalized_row);
    }

    return $users;
}

/**
 * @return array{id: int, username: string, email: string, account_type: string, can_manage_admin_users: bool, can_manage_api_keys: bool, is_main_account: bool}|null
 */
function bdta_find_admin_user(PDO $conn, int $admin_user_id): ?array
{
    $stmt = $conn->prepare("
        SELECT id, username, email, account_type, can_manage_admin_users, can_manage_api_keys
        FROM admin_users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$admin_user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        return null;
    }

    /** @var array<string, mixed> $normalized_row */
    $normalized_row = $row;
    return bdta_normalize_admin_user($normalized_row);
}

/**
 * @param array<string, mixed> $session
 */
function bdta_session_is_authenticated_admin(array $session): bool
{
    return bdta_admin_users_scalar($session['user_type'] ?? '') === 'admin'
        && bdta_admin_users_int($session['admin_id'] ?? 0) > 0;
}

/**
 * @param array<string, mixed> $session
 * @return array{id: int, username: string, email: string, account_type: string, can_manage_admin_users: bool, can_manage_api_keys: bool, is_main_account: bool}|null
 */
function bdta_current_admin_user(PDO $conn, array $session): ?array
{
    if (!bdta_session_is_authenticated_admin($session)) {
        return null;
    }

    $admin_user_id = bdta_admin_users_int($session['admin_id'] ?? 0);
    return bdta_find_admin_user($conn, $admin_user_id);
}

/**
 * @param array<string, mixed> $session
 */
function bdta_current_admin_can_manage_api_key_settings(PDO $conn, array $session): bool
{
    return bdta_admin_user_can_manage_api_keys(bdta_current_admin_user($conn, $session));
}

/**
 * @param array{id: int, username: string, email: string, account_type: string, can_manage_admin_users: bool, can_manage_api_keys: bool, is_main_account: bool}|null $admin_user
 */
function bdta_admin_user_can_manage_admin_users(?array $admin_user): bool
{
    return is_array($admin_user) && (!empty($admin_user['is_main_account']) || !empty($admin_user['can_manage_admin_users']));
}

/**
 * @param array{id: int, username: string, email: string, account_type: string, can_manage_admin_users: bool, can_manage_api_keys: bool, is_main_account: bool}|null $admin_user
 */
function bdta_admin_user_can_manage_api_keys(?array $admin_user): bool
{
    return is_array($admin_user) && (!empty($admin_user['is_main_account']) || !empty($admin_user['can_manage_api_keys']));
}

/**
 * @param array{id: int, username: string, email: string, account_type: string, can_manage_admin_users: bool, can_manage_api_keys: bool, is_main_account: bool}|null $admin_user
 */
function bdta_admin_user_is_accountant(?array $admin_user): bool
{
    return is_array($admin_user) && ($admin_user['account_type'] ?? '') === 'accountant';
}

/**
 * @param array{id: int, username: string, email: string, account_type: string, can_manage_admin_users: bool, can_manage_api_keys: bool, is_main_account: bool}|null $admin_user
 */
function bdta_admin_user_can_modify_accounting(?array $admin_user): bool
{
    return is_array($admin_user) && !bdta_admin_user_is_accountant($admin_user);
}

/**
 * @return list<string>
 */
function bdta_accountant_allowed_admin_paths(): array
{
    return [
        'change_password.php',
        'expenses_list.php',
        'invoices_list.php',
        'invoices_view.php',
        'logout.php',
        'notification_action.php',
        'notification_redirect.php',
        'reports_export.php',
        'reports_financial.php',
    ];
}

function bdta_is_accountant_allowed_admin_path(string $path): bool
{
    return in_array(basename(trim($path)), bdta_accountant_allowed_admin_paths(), true);
}

/**
 * @param array<string, mixed> $session
 */
function bdta_session_admin_is_accountant(array $session): bool
{
    return bdta_admin_users_scalar($session['user_type'] ?? '') === 'admin'
        && bdta_normalize_admin_account_type($session['admin_account_type'] ?? 'standard') === 'accountant';
}

/**
 * @param list<array<string, mixed>> $settings
 * @return list<array<string, mixed>>
 */
function bdta_filter_api_key_settings(array $settings, bool $can_manage_api_keys): array
{
    if ($can_manage_api_keys) {
        return $settings;
    }

    $restricted_keys = array_fill_keys(bdta_api_key_setting_keys(), true);

    return array_values(array_filter(
        $settings,
        static function (array $setting) use ($restricted_keys): bool {
            $setting_key = bdta_admin_users_scalar($setting['key'] ?? '');
            return $setting_key !== '' && !isset($restricted_keys[$setting_key]);
        }
    ));
}
