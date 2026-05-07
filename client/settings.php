<?php
/**
 * Settings Management - Admin Panel
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/settings.php';
require_once __DIR__ . '/../backend/includes/admin_users.php';

requireLogin();

// Helper function to get category icons
function getCategoryIcon(string $category): string {
    $icons = [
        'general' => 'circle-info',
        'email' => 'envelope',
        'payment' => 'credit-card',
        'booking' => 'calendar-check',
        'calendar' => 'calendar-days',
        'invoice' => 'file-invoice',
        'time_tracking' => 'clock',
        'social' => 'share-nodes',
        'database' => 'database',
        'theme' => 'palette',
        'advanced' => 'gear',
        'admins' => 'users'
    ];
    return $icons[$category] ?? 'gear';
}

// Helper function to get select options
/**
 * @return array<int|string, string>
 */
function getSelectOptions(string $key): array {
    $options_map = [
        'email_service' => [
            'mail' => 'PHP mail() function',
            'smtp' => 'SMTP',
            'sendgrid' => 'SendGrid (SMTP)',
            'mailgun' => 'Mailgun (SMTP)',
            'ses' => 'Amazon SES (SMTP)'
        ],
        'smtp_encryption' => [
            'tls' => 'TLS',
            'ssl' => 'SSL',
            'none' => 'None'
        ],
        'imap_encryption' => [
            'ssl' => 'SSL',
            'tls' => 'TLS',
            'none' => 'None'
        ],
        'stripe_mode' => [
            'test' => 'Test Mode',
            'live' => 'Live Mode'
        ],
        'time_rounding' => [
            '0' => 'No rounding',
            '5' => '5 minutes',
            '10' => '10 minutes',
            '15' => '15 minutes',
            '30' => '30 minutes'
        ]
    ];
    
    $options = [];
    foreach (($options_map[$key] ?? []) as $option_key => $label) {
        $options[(string) $option_key] = $label;
    }

    return $options;
}

$page_title = 'Settings';
$db = new Database();
$conn = $db->getConnection();
$current_admin_user = bdta_current_admin_user($conn, $_SESSION);
$is_main_admin_account = is_array($current_admin_user) && !empty($current_admin_user['is_main_account']);
$can_manage_admin_users = bdta_admin_user_can_manage_admin_users($current_admin_user);
$can_manage_api_keys = bdta_admin_user_can_manage_api_keys($current_admin_user);

// Get all categories
$categories = Settings::getCategories();
$categories[] = 'admins';
$categories = array_values(array_unique($categories));
sort($categories);
if (!$can_manage_api_keys) {
    $categories = array_values(array_filter(
        $categories,
        static fn (string $category): bool => $category !== 'database'
    ));
}
$current_category = scalar_string($_GET['category'] ?? 'general');

if (!$can_manage_api_keys && $current_category === 'database') {
    setFlashMessage('You do not have permission to access database settings.', 'danger');
    redirect(ADMIN_URL . 'settings.php?category=general');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_admin_user'])) {
    requireValidCsrfToken(ADMIN_URL . 'settings.php?category=admins');

    if (!$can_manage_admin_users) {
        setFlashMessage('You do not have permission to add admin users.', 'danger');
        redirect(ADMIN_URL . 'settings.php?category=admins');
    }

    $username = trim(scalar_string($_POST['new_admin_username'] ?? ''));
    $email = trim(scalar_string($_POST['new_admin_email'] ?? ''));
    $password = scalar_string($_POST['new_admin_password'] ?? '');
    $requested_account_type = scalar_string($_POST['new_admin_account_type'] ?? 'standard');

    if ($username === '' || !bdta_is_valid_admin_username($username)) {
        setFlashMessage('Username must be 3-64 characters and contain only letters, numbers, dots, underscores, or dashes.', 'danger');
        redirect(ADMIN_URL . 'settings.php?category=admins');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        setFlashMessage('Enter a valid email address for the admin user.', 'danger');
        redirect(ADMIN_URL . 'settings.php?category=admins');
    }

    if (strlen($password) < 8) {
        setFlashMessage('Admin user passwords must be at least 8 characters long.', 'danger');
        redirect(ADMIN_URL . 'settings.php?category=admins');
    }

    if (!in_array($requested_account_type, bdta_valid_admin_account_types(), true)) {
        setFlashMessage('Select a valid admin account type.', 'danger');
        redirect(ADMIN_URL . 'settings.php?category=admins');
    }

    $account_type = bdta_normalize_admin_account_type($requested_account_type);

    $existing_admin_stmt = $conn->prepare('SELECT id FROM admin_users WHERE username = ? LIMIT 1');
    $existing_admin_stmt->execute([$username]);
    if ($existing_admin_stmt->fetch() !== false) {
        setFlashMessage('That admin username is already in use.', 'danger');
        redirect(ADMIN_URL . 'settings.php?category=admins');
    }

    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $create_admin_stmt = $conn->prepare("
        INSERT INTO admin_users (username, password_hash, email, account_type, can_manage_admin_users, can_manage_api_keys)
        VALUES (?, ?, ?, ?, 0, 0)
    ");
    $create_admin_stmt->execute([$username, $password_hash, $email, $account_type]);

    setFlashMessage('Admin user created successfully.', 'success');
    redirect(ADMIN_URL . 'settings.php?category=admins');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_admin_permissions'])) {
    requireValidCsrfToken(ADMIN_URL . 'settings.php?category=admins');

    if (!$is_main_admin_account) {
        setFlashMessage('Only the main admin account can change admin permissions.', 'danger');
        redirect(ADMIN_URL . 'settings.php?category=admins');
    }

    $target_admin_user_id = safe_int($_POST['target_admin_user_id'] ?? 0);
    $target_admin_user = bdta_find_admin_user($conn, $target_admin_user_id);

    if ($target_admin_user === null || !empty($target_admin_user['is_main_account'])) {
        setFlashMessage('The main admin account permissions cannot be changed.', 'danger');
        redirect(ADMIN_URL . 'settings.php?category=admins');
    }

    if (bdta_admin_user_is_accountant($target_admin_user)) {
        setFlashMessage('Accountant account permissions cannot be modified because they always use fixed read-only accounting access.', 'danger');
        redirect(ADMIN_URL . 'settings.php?category=admins');
    }

    $update_permissions_stmt = $conn->prepare("
        UPDATE admin_users
        SET can_manage_admin_users = ?, can_manage_api_keys = ?
        WHERE id = ?
    ");
    $update_permissions_stmt->execute([
        isset($_POST['can_manage_admin_users']) ? 1 : 0,
        isset($_POST['can_manage_api_keys']) ? 1 : 0,
        $target_admin_user_id,
    ]);

    setFlashMessage('Admin permissions updated.', 'success');
    redirect(ADMIN_URL . 'settings.php?category=admins');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_admin_user'])) {
    requireValidCsrfToken(ADMIN_URL . 'settings.php?category=admins');

    if (!$can_manage_admin_users) {
        setFlashMessage('You do not have permission to delete admin users.', 'danger');
        redirect(ADMIN_URL . 'settings.php?category=admins');
    }

    $target_admin_user_id = safe_int($_POST['target_admin_user_id'] ?? 0);
    $target_admin_user = bdta_find_admin_user($conn, $target_admin_user_id);
    $current_admin_user_id = safe_int($_SESSION['admin_id'] ?? 0);

    if ($target_admin_user === null) {
        setFlashMessage('That admin user could not be found.', 'danger');
        redirect(ADMIN_URL . 'settings.php?category=admins');
    }

    if (!empty($target_admin_user['is_main_account'])) {
        setFlashMessage('The main admin account cannot be deleted.', 'danger');
        redirect(ADMIN_URL . 'settings.php?category=admins');
    }

    if ($target_admin_user_id === $current_admin_user_id) {
        setFlashMessage('You cannot delete the admin account you are currently using.', 'danger');
        redirect(ADMIN_URL . 'settings.php?category=admins');
    }

    $conn->beginTransaction();
    $delete_step = 'unassign appointment types';
    try {
        $conn->prepare('UPDATE appointment_types SET admin_user_id = NULL WHERE admin_user_id = ?')->execute([$target_admin_user_id]);
        $delete_step = 'unassign bookings';
        $conn->prepare('UPDATE bookings SET admin_user_id = NULL WHERE admin_user_id = ?')->execute([$target_admin_user_id]);
        $delete_step = 'delete admin user';
        $conn->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$target_admin_user_id]);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollBack();
        error_log('settings.php: failed to ' . $delete_step . ' for admin user ' . $target_admin_user_id . ': ' . $e->getMessage());
        setFlashMessage('Unable to delete that admin user right now.', 'danger');
        redirect(ADMIN_URL . 'settings.php?category=admins');
    }

    setFlashMessage('Admin user deleted successfully.', 'success');
    redirect(ADMIN_URL . 'settings.php?category=admins');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    try {
        // Get all settings for current category to validate keys
        $category_settings = Settings::getCategory($current_category);
        $valid_settings = bdta_filter_api_key_settings($category_settings, $can_manage_api_keys);
        if (
            !$can_manage_api_keys
            && count($category_settings) > 0
            && count($valid_settings) === 0
        ) {
            setFlashMessage('You do not have permission to change API-key or global integration settings.', 'danger');
            redirect(ADMIN_URL . 'settings.php?category=' . urlencode($current_category));
        }
        $valid_keys = array_column($valid_settings, 'key');
        
        // Special handling for database category - only update .env file
        if ($current_category === 'database') {
            $env_updates = [];
            $env_key_map = [
                'db_host' => 'DB_HOST',
                'db_port' => 'DB_PORT',
                'db_name' => 'DB_NAME',
                'db_user' => 'DB_USER',
                'db_password' => 'DB_PASSWORD'
            ];
            
            foreach ($_POST as $key => $value) {
                $setting_key = scalar_string($key);
                if ($setting_key !== 'save_settings' && $setting_key !== 'category' && in_array($setting_key, $valid_keys, true)) {
                    if (isset($env_key_map[$setting_key])) {
                        $env_updates[$env_key_map[$setting_key]] = scalar_string($value);
                    }
                }
            }
            
            if (!empty($env_updates)) {
                updateEnvFile($env_updates);
                setFlashMessage('Database settings saved to .env file successfully! You may need to restart the application for changes to take effect.', 'success');
            } else {
                setFlashMessage('No changes to save.', 'info');
            }
        } else {
            // Handle non-database settings normally
            foreach ($_POST as $key => $value) {
                $setting_key = scalar_string($key);
                if ($setting_key !== 'save_settings' && $setting_key !== 'category' && in_array($setting_key, $valid_keys, true)) {
                    // Validate color values to only accept valid hex colors
                    $setting_info = null;
                    foreach ($valid_settings as $s) {
                        if (array_string_value($s, 'key') === $setting_key) { $setting_info = $s; break; }
                    }
                    if ($setting_info && array_string_value($setting_info, 'type') === 'color') {
                        if (preg_match('/^#[0-9A-Fa-f]{6}$/', scalar_string($value)) !== 1) {
                            continue; // Skip invalid color values
                        }
                    }
                    Settings::set($setting_key, scalar_string($value));
                }
            }
            
            // Handle unchecked checkboxes (they don't appear in $_POST)
            foreach ($valid_settings as $setting) {
                $setting_key = array_string_value($setting, 'key');
                if (array_string_value($setting, 'type') === 'checkbox' && !isset($_POST[$setting_key])) {
                    Settings::set($setting_key, '0');
                }
            }
            
            setFlashMessage('Settings saved successfully!', 'success');
        }
        
        redirect(ADMIN_URL . 'settings.php?category=' . urlencode($current_category));
    } catch (Exception $e) {
        setFlashMessage('Error saving settings: ' . $e->getMessage(), 'danger');
    }
}

// Handle theme reset to defaults
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_theme'])) {
    try {
        $defaults = Settings::getDefaultThemeColors();
        foreach ($defaults as $key => $value) {
            Settings::set($key, $value);
        }
        setFlashMessage('Theme colors reset to defaults successfully!', 'success');
        redirect(ADMIN_URL . 'settings.php?category=theme');
    } catch (Exception $e) {
        setFlashMessage('Error resetting theme: ' . $e->getMessage(), 'danger');
    }
}

/**
 * Update .env file with new values
 */
/**
 * @param array<string, string> $updates
 */
function updateEnvFile(array $updates): void {
    $env_file = __DIR__ . '/../.env';
    $env_example = __DIR__ . '/../.env.example';
    
    // Create .env from .env.example if it doesn't exist
    if (!file_exists($env_file) && file_exists($env_example)) {
        copy($env_example, $env_file);
    }
    
    $lines = [];
    if (file_exists($env_file)) {
        $lines = file($env_file, FILE_IGNORE_NEW_LINES) ?: [];
    }
    
    $updated_keys = [];
    
    // Update existing keys
    foreach ($lines as $i => $line) {
        // Skip comments and empty lines
        if (empty(trim($line)) || strpos(trim($line), '#') === 0) {
            continue;
        }
        
        if (strpos($line, '=') !== false) {
            list($key, $old_value) = explode('=', $line, 2);
            $key = trim($key);
            
            if (isset($updates[$key])) {
                $new_value = $updates[$key];
                // Quote value if it contains spaces or special characters
                if (preg_match('/[\s#]/', $new_value)) {
                    $new_value = '"' . addslashes($new_value) . '"';
                }
                $lines[$i] = "$key=$new_value";
                $updated_keys[] = $key;
            }
        }
    }
    
    // Add new keys that weren't found
    foreach ($updates as $key => $value) {
        if (!in_array($key, $updated_keys)) {
            // Quote value if needed
            if (preg_match('/[\s#]/', $value) === 1) {
                $value = '"' . addslashes($value) . '"';
            }
            $lines[] = "$key=$value";
        }
    }
    
    // Write back to file
    file_put_contents($env_file, implode("\n", $lines) . "\n");
}

// Get settings for current category
$settings = $current_category === 'admins'
    ? []
    : bdta_filter_api_key_settings(Settings::getCategory($current_category), $can_manage_api_keys);
$admin_users = $current_category === 'admins' ? bdta_list_admin_users($conn) : [];

// For booking category: load available booking intake form templates for the dropdown
$booking_form_templates = [];
if ($current_category === 'booking') {
    $db_settings = new Database();
    $conn_settings = $db_settings->getConnection();
    $stmt_bft = $conn_settings->prepare("SELECT id, name FROM form_templates WHERE form_type = 'booking_form' AND is_active = 1 ORDER BY name");
    $stmt_bft->execute();
    $booking_form_templates = $stmt_bft->fetchAll(PDO::FETCH_ASSOC);
}

// For calendar category: load OAuth connection status and generate CSRF token
$oauth_token_row  = null;
$oauth_configured = false;
$oauth_failure_notice = null;
$oauth_connection_needs_attention = false;
if ($current_category === 'calendar') {
    require_once __DIR__ . '/../backend/includes/google_calendar.php';
    $oauth_configured = GoogleCalendarIntegration::isOAuthConfigured();
    $calendar_admin_user_id = safe_int($_SESSION['admin_id'] ?? 0);
    $oauth_token_row  = GoogleCalendarIntegration::getOAuthToken($calendar_admin_user_id);
    $oauth_failure_notice = GoogleCalendarIntegration::getActiveOAuthFailureNotification($calendar_admin_user_id);
    $oauth_connection_needs_attention = is_array($oauth_failure_notice);
    // Generate a CSRF token for the disconnect form
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
}

include __DIR__ . '/../backend/includes/header.php';
?>

<?php
// Get theme colors for inline styles that aren't covered by header.php
$theme_primary = scalar_string(Settings::get('theme_primary_color', '#9a0073'));
$theme_primary_dark = scalar_string(Settings::get('theme_primary_dark_color', '#7a005a'));
$st_primary = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_primary) === 1 ? $theme_primary : '#9a0073';
$st_primary_dark = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_primary_dark) === 1 ? $theme_primary_dark : '#7a005a';
?>
<style>
    /* Custom styling for settings submenu active state */
    .list-group-item.active {
        background-color: <?= $st_primary ?> !important;
        border-color: <?= $st_primary ?> !important;
        color: white !important;
    }
    .list-group-item.active:hover {
        background-color: <?= $st_primary_dark ?> !important;
        border-color: <?= $st_primary_dark ?> !important;
    }
</style>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="fas fa-gear"></i> Settings</h2>
            <p class="text-muted">Configure your Brook's Dog Training Academy settings</p>
        </div>
    </div>

    <?php 
    $flash = getFlashMessage();
    if ($flash): 
    ?>
        <div class="alert alert-<?= escape($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= escape($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Category Navigation -->
        <div class="col-md-3 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Categories</h5>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($categories as $category): ?>
                        <a href="?category=<?= urlencode($category) ?>" 
                           class="list-group-item list-group-item-action <?= $category === $current_category ? 'active' : '' ?>">
                            <i class="fas fa-<?= getCategoryIcon($category) ?>"></i>
                            <?= escape(ucwords(str_replace('_', ' ', $category))) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <h6 class="card-title"><i class="fas fa-circle-info"></i> Help</h6>
                    <p class="card-text small text-muted">
                        Changes to settings take effect immediately. Settings marked with 
                        <i class="fas fa-shield-halved"></i> are sensitive and will be masked.
                    </p>
                </div>
            </div>
        </div>

        <!-- Settings Form -->
        <div class="col-md-9">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-<?= getCategoryIcon($current_category) ?>"></i>
                        <?= escape(ucwords(str_replace('_', ' ', $current_category))) ?> Settings
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($current_category === 'database'): ?>
                        <!-- Database Category Special Section -->
                        <div class="alert alert-info mb-4">
                            <h6><i class="fas fa-circle-info"></i> Database Configuration</h6>
                            <p class="mb-0 small">
                                Configure the MySQL connection used by the application.
                                Changes to database settings require restarting your web server to take effect.
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ($current_category === 'theme'): ?>
                        <!-- Theme Category Info -->
                        <div class="alert alert-info mb-4">
                            <h6><i class="fas fa-palette"></i> Theme Customization</h6>
                            <p class="mb-0 small">
                                Customize the color scheme of the admin panel and client portal. Changes take effect immediately after saving.
                                Use the live preview below to see how your selected colors will look before saving.
                                Ensure colors have sufficient contrast for accessibility and readability.
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ($current_category === 'social'): ?>
                        <div class="alert alert-info mb-4">
                            <h6><i class="fas fa-share-nodes"></i> Social Links</h6>
                            <p class="mb-0 small">
                                Any social link left blank will stay hidden on the public website. Custom links use the label field for the button name and a generic link icon on the front-end.
                            </p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($current_category === 'admins'): ?>
                        <div class="alert alert-info mb-4">
                            <h6><i class="fas fa-users"></i> Admin User Management</h6>
                            <p class="mb-0 small">
                                The default <strong>admin</strong> account is created automatically if one does not exist.
                                The main admin account always keeps admin-user and API-key access. Other admin users can be granted those permissions here.
                            </p>
                        </div>

                        <?php if ($can_manage_admin_users): ?>
                            <div class="card border-0 bg-light mb-4">
                                <div class="card-body">
                                    <h6 class="card-title mb-3"><i class="fas fa-user-plus"></i> Add Admin User</h6>
                                    <form method="POST" action="?category=admins" class="row g-3">
                                        <?= csrfInput() ?>
                                        <div class="col-md-4">
                                            <label for="new_admin_username" class="form-label">Username</label>
                                            <input type="text" class="form-control" id="new_admin_username" name="new_admin_username" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="new_admin_email" class="form-label">Email</label>
                                            <input type="email" class="form-control" id="new_admin_email" name="new_admin_email" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="new_admin_password" class="form-label">Temporary Password</label>
                                            <input type="password" class="form-control" id="new_admin_password" name="new_admin_password" minlength="8" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label for="new_admin_account_type" class="form-label">Account Type</label>
                                            <select class="form-select" id="new_admin_account_type" name="new_admin_account_type">
                                                <option value="standard">Standard</option>
                                                <option value="accountant">Accountant (read-only accounting)</option>
                                            </select>
                                        </div>
                                        <div class="col-12 d-flex justify-content-end">
                                            <button type="submit" name="add_admin_user" class="btn btn-primary">
                                                <i class="fas fa-user-plus"></i> Add Admin User
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th scope="col">Admin User</th>
                                        <th scope="col">Account Type</th>
                                        <th scope="col">Permissions</th>
                                        <th scope="col" class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($admin_users as $admin_user): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold"><?= escape($admin_user['username']) ?></div>
                                                <?php if ($admin_user['email'] !== ''): ?>
                                                    <div class="text-muted small"><?= escape($admin_user['email']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge text-bg-<?= $admin_user['is_main_account'] ? 'primary' : 'secondary' ?>">
                                                    <?= escape(ucfirst($admin_user['account_type'])) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($admin_user['is_main_account']): ?>
                                                    <div class="small text-muted">Fixed for the default/main admin account.</div>
                                                    <div class="d-flex flex-wrap gap-2 mt-2">
                                                        <span class="badge text-bg-success">Manage admin users</span>
                                                        <span class="badge text-bg-success">Access API-key settings</span>
                                                    </div>
                                                <?php elseif (bdta_admin_user_is_accountant($admin_user)): ?>
                                                    <div class="small text-muted">Fixed for accountant accounts.</div>
                                                    <div class="d-flex flex-wrap gap-2 mt-2">
                                                        <span class="badge text-bg-info">Read-only invoices &amp; expenses</span>
                                                        <span class="badge text-bg-info">Financial report export access</span>
                                                    </div>
                                                <?php elseif ($is_main_admin_account): ?>
                                                    <form method="POST" action="?category=admins" class="d-flex flex-column gap-2">
                                                        <?= csrfInput() ?>
                                                        <input type="hidden" name="target_admin_user_id" value="<?= (int) $admin_user['id'] ?>">
                                                        <div class="form-check">
                                                            <input
                                                                class="form-check-input"
                                                                type="checkbox"
                                                                id="can_manage_admin_users_<?= (int) $admin_user['id'] ?>"
                                                                name="can_manage_admin_users"
                                                                value="1"
                                                                <?= $admin_user['can_manage_admin_users'] ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="can_manage_admin_users_<?= (int) $admin_user['id'] ?>">
                                                                Manage admin users
                                                            </label>
                                                        </div>
                                                        <div class="form-check">
                                                            <input
                                                                class="form-check-input"
                                                                type="checkbox"
                                                                id="can_manage_api_keys_<?= (int) $admin_user['id'] ?>"
                                                                name="can_manage_api_keys"
                                                                value="1"
                                                                <?= $admin_user['can_manage_api_keys'] ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="can_manage_api_keys_<?= (int) $admin_user['id'] ?>">
                                                                Access API-key settings
                                                            </label>
                                                        </div>
                                                        <div>
                                                            <button type="submit" name="update_admin_permissions" class="btn btn-sm btn-outline-primary">
                                                                <i class="fas fa-save"></i> Save Permissions
                                                            </button>
                                                        </div>
                                                    </form>
                                                <?php else: ?>
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <span class="badge text-bg-<?= $admin_user['can_manage_admin_users'] ? 'success' : 'light' ?>">
                                                            <?= $admin_user['can_manage_admin_users'] ? 'Can manage admin users' : 'Cannot manage admin users' ?>
                                                        </span>
                                                        <span class="badge text-bg-<?= $admin_user['can_manage_api_keys'] ? 'success' : 'light' ?>">
                                                            <?= $admin_user['can_manage_api_keys'] ? 'Can access API-key settings' : 'No API-key settings access' ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if (
                                                    !$admin_user['is_main_account']
                                                    && $can_manage_admin_users
                                                    && (int) $admin_user['id'] !== safe_int($_SESSION['admin_id'] ?? 0)
                                                ): ?>
                                                    <form method="POST" action="?category=admins" onsubmit="return confirm('Delete this admin user? Their appointment assignments will be cleared.');">
                                                        <?= csrfInput() ?>
                                                        <input type="hidden" name="target_admin_user_id" value="<?= (int) $admin_user['id'] ?>">
                                                        <button type="submit" name="delete_admin_user" class="btn btn-sm btn-outline-danger">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-muted small">No changes available</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="form-text">
                            Google Calendar connection controls remain available in the <a href="?category=calendar">Calendar</a> settings for each admin account.
                            API-key and global integration settings stay limited to admins with API-key access.
                        </div>
                    <?php else: ?>
                        <?php if (!$can_manage_api_keys && $current_category === 'calendar'): ?>
                            <div class="alert alert-info mb-4">
                                <i class="fas fa-circle-info"></i>
                                Global Google Calendar credentials and other API-key settings are managed separately.
                                Your personal Google Calendar connection controls remain available below.
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" id="settings-form">
                            <input type="hidden" name="category" value="<?= escape($current_category) ?>">
                            
                            <?php foreach ($settings as $setting): ?>
                                <div class="mb-3">
                                    <label for="<?= escape($setting['key']) ?>" class="form-label">
                                        <?= escape($setting['label']) ?>
                                        <?php if ($setting['is_secret']): ?>
                                            <i class="fas fa-shield-halved text-warning" title="Sensitive data"></i>
                                        <?php endif; ?>
                                    </label>
                                    
                                    <?php if ($setting['key'] === 'default_booking_form_id'): ?>
                                        <select class="form-select" id="default_booking_form_id" name="default_booking_form_id">
                                            <option value="0" <?= safe_int($setting['actual_value']) === 0 ? 'selected' : '' ?>>— Use default fields (Name, Email, Phone, Pet Name, Notes) —</option>
                                            <?php foreach ($booking_form_templates as $bft): ?>
                                                <option value="<?= (int)$bft['id'] ?>" <?= safe_int($setting['actual_value']) === (int)$bft['id'] ? 'selected' : '' ?>>
                                                    <?= escape($bft['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (empty($booking_form_templates)): ?>
                                        <div class="form-text text-muted">
                                            No Booking Intake Form templates exist yet.
                                            <a href="form_templates_edit.php">Create one</a> with Form Type set to <strong>Booking Intake Form</strong>.
                                        </div>
                                        <?php else: ?>
                                        <div class="form-text"><?= escape($setting['description']) ?> <a href="form_templates_list.php">Manage form templates</a>.</div>
                                        <?php endif; ?>

                                    <?php elseif ($setting['type'] === 'textarea'): ?>
                                        <textarea 
                                            class="form-control" 
                                            id="<?= escape($setting['key']) ?>" 
                                            name="<?= escape($setting['key']) ?>"
                                            rows="3"><?= escape($setting['actual_value']) ?></textarea>
                                        <?php if ($setting['key'] === 'newsletter_embed_html'): ?>
                                        <div class="form-text text-warning">
                                            Trusted admins only: this embed code is rendered as-is on public site pages. Only paste official embed code from trusted providers, because malicious scripts could create XSS risk for visitors.
                                        </div>
                                        <?php endif; ?>
                                     
                                    <?php elseif ($setting['type'] === 'checkbox'): ?>
                                        <div class="form-check">
                                            <input 
                                                class="form-check-input" 
                                                type="checkbox" 
                                                id="<?= escape($setting['key']) ?>" 
                                                name="<?= escape($setting['key']) ?>"
                                                value="1"
                                                <?= $setting['actual_value'] ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="<?= escape($setting['key']) ?>">
                                                Enabled
                                            </label>
                                        </div>
                                    
                                    <?php elseif ($setting['type'] === 'select'): ?>
                                        <select class="form-select" id="<?= escape($setting['key']) ?>" name="<?= escape($setting['key']) ?>">
                                            <?php 
                                            $options = getSelectOptions(array_string_value($setting, 'key'));
                                            foreach ($options as $value => $label): 
                                            ?>
                                                <option value="<?= escape(scalar_string($value)) ?>" <?= $setting['actual_value'] == $value ? 'selected' : '' ?>>
                                                    <?= escape($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                    <?php elseif ($setting['type'] === 'color'): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <input 
                                                type="color" 
                                                class="form-control form-control-color theme-color-input"
                                                id="<?= escape($setting['key']) ?>" 
                                                name="<?= escape($setting['key']) ?>"
                                                value="<?= escape($setting['actual_value']) ?>"
                                                data-color-key="<?= escape($setting['key']) ?>"
                                                title="Choose color">
                                            <input 
                                                type="text"
                                                class="form-control font-monospace color-hex-display"
                                                style="max-width: 110px;"
                                                value="<?= escape($setting['actual_value']) ?>"
                                                data-for="<?= escape($setting['key']) ?>"
                                                placeholder="#000000"
                                                maxlength="7"
                                                aria-label="Hex color value for <?= escape($setting['label']) ?>">
                                        </div>
                                    
                                    <?php else: ?>
                                        <input 
                                            type="<?= escape($setting['type']) ?>" 
                                            class="form-control" 
                                            id="<?= escape($setting['key']) ?>" 
                                            name="<?= escape($setting['key']) ?>"
                                            value="<?= escape($setting['actual_value']) ?>">
                                    <?php endif; ?>
                                    
                                    <?php if ($setting['description'] && $setting['key'] !== 'default_booking_form_id'): ?>
                                        <div class="form-text"><?= escape($setting['description']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if ($settings !== []): ?>
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                                    <button type="submit" name="save_settings" class="btn btn-primary">
                                        <i class="fas fa-check-lg"></i> Save Settings
                                    </button>
                                </div>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($current_category === 'database'): ?>
                        <!-- Database Utilities -->
                        <hr class="my-4">
                        <div class="card bg-light">
                            <div class="card-header">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-database"></i> Database Utilities
                                </h6>
                            </div>
                            <div class="card-body">
                                <p class="card-text">
                                    Open the database tools page to verify connectivity and download a MySQL backup.
                                </p>
                                <a href="database_migration.php" class="btn btn-warning">
                                    <i class="fas fa-database"></i> Open Database Tools
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($current_category === 'calendar'): ?>
                        <!-- Google Calendar OAuth Connection -->
                        <hr class="my-4">
                        <div class="card bg-light">
                            <div class="card-header">
                                <h6 class="card-title mb-0">
                                    <i class="fab fa-google"></i> Google Calendar – OAuth Connection
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info mb-3">
                                    <i class="fas fa-circle-info"></i>
                                    <strong>Important:</strong>
                                    If your Google OAuth app audience is <strong>External</strong> and the publishing status is still <strong>Testing</strong>,
                                    Google issues Calendar refresh tokens that expire after <strong>7 days</strong>.
                                    Before live use, switch the OAuth consent screen to <strong>Production</strong>
                                    (or use an <strong>Internal</strong> app for Google Workspace), then reconnect Google Calendar.
                                </div>
                                <?php if ($oauth_token_row): ?>
                                    <!-- Connected state -->
                                    <div class="alert <?= $oauth_connection_needs_attention ? 'alert-warning' : 'alert-success' ?> mb-3">
                                        <i class="fas <?= $oauth_connection_needs_attention ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i>
                                        <strong><?= $oauth_connection_needs_attention ? 'Connection needs attention' : 'Connected' ?></strong>
                                        <?php if (!empty($oauth_token_row['google_email'])): ?>
                                            as <strong><?= escape($oauth_token_row['google_email']) ?></strong>
                                        <?php endif; ?>
                                        <br>
                                        <small class="text-muted">
                                            Syncing to calendar:
                                            <strong><?= escape($oauth_token_row['calendar_id'] ?? 'primary') ?></strong>
                                            &mdash; connected <?= escape(formatDate(array_string_value($oauth_token_row, 'created_at'), 'M j, Y')) ?>
                                        </small>
                                        <?php if ($oauth_connection_needs_attention && !empty($oauth_failure_notice['message'])): ?>
                                            <div class="mt-2 small">
                                                <?= escape(array_string_value($oauth_failure_notice, 'message')) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Calendar selector -->
                                    <?php
                                    $user_calendars = GoogleCalendarIntegration::listCalendars(safe_int($_SESSION['admin_id'] ?? 0));
                                    if (!empty($user_calendars)):
                                    ?>
                                    <form method="POST" action="google_oauth_select_calendar.php" class="mb-3">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <label class="form-label fw-semibold">
                                            <i class="fas fa-calendar-days"></i> Choose Booking Calendar
                                        </label>
                                        <div class="input-group">
                                            <select name="calendar_id" class="form-select">
                                                <?php foreach ($user_calendars as $cal): ?>
                                                    <option value="<?= escape($cal['id']) ?>"
                                                        <?= ($oauth_token_row['calendar_id'] === $cal['id']) ? 'selected' : '' ?>>
                                                        <?= escape($cal['summary']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn btn-outline-primary">
                                                <i class="fas fa-check"></i> Save
                                            </button>
                                        </div>
                                        <div class="form-text">New bookings will be added to the selected calendar.</div>
                                    </form>
                                    <?php endif; ?>

                                    <!-- Disconnect button -->
                                    <form method="POST" action="../backend/public/google_oauth_revoke.php"
                                          onsubmit="return confirm('Disconnect Google Calendar? Existing events will not be removed.');">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                        <button type="submit" class="btn btn-outline-danger">
                                            <i class="fas fa-link-slash"></i> Disconnect Google Calendar
                                        </button>
                                    </form>

                                <?php elseif ($oauth_configured): ?>
                                    <!-- Not connected, but credentials are saved -->
                                    <p class="card-text">
                                        Your OAuth credentials are saved. Click below to authorise access to your Google Calendar.
                                        New bookings will automatically appear in your connected calendar.
                                    </p>
                                    <a href="../backend/public/google_oauth_initiate.php" class="btn btn-primary">
                                        <i class="fab fa-google"></i> Connect Google Calendar
                                    </a>

                                <?php else: ?>
                                    <!-- Credentials not yet configured -->
                                    <p class="card-text">
                                        To enable per-user Google Calendar sync, enter your
                                        <strong>OAuth Client ID</strong>, <strong>Client Secret</strong>, and
                                        <strong>Redirect URI</strong> in the settings form above, then click
                                        <em>Save Settings</em> and return here to connect.
                                    </p>
                                    <p class="card-text small text-muted">
                                        Need help? See the
                                        <a href="../backend/CALENDAR_INTEGRATION.md" target="_blank">Calendar Integration Guide</a>
                                        for step-by-step OAuth setup instructions.
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($current_category === 'theme'): ?>
                        <!-- Theme Live Preview -->
                        <hr class="my-4">
                        <div class="card bg-light" id="theme-preview-card">
                            <div class="card-header">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-eye"></i> Live Preview
                                </h6>
                            </div>
                            <div class="card-body">
                                <p class="card-text small text-muted mb-3">
                                    This preview updates in real time as you adjust the colors above.
                                </p>
                                <div id="theme-preview" style="border-radius: 8px; overflow: hidden; border: 1px solid #dee2e6;">
                                    <!-- Sidebar preview -->
                                    <div id="preview-sidebar" style="padding: 1rem; color: white;">
                                        <div style="font-weight: bold; margin-bottom: 0.75rem; font-size: 0.9rem;">
                                            <i class="fas fa-paw me-1"></i> BDTA Admin
                                        </div>
                                        <div id="preview-nav-link" style="padding: 0.5rem 0.75rem; border-radius: 4px; font-size: 0.85rem; margin-bottom: 0.25rem; opacity: 0.85;">
                                            <i class="fas fa-gauge me-2"></i> Dashboard
                                        </div>
                                        <div style="padding: 0.5rem 0.75rem; font-size: 0.85rem; margin-bottom: 0.25rem; opacity: 0.7;">
                                            <i class="fas fa-calendar-check me-2"></i> Bookings
                                        </div>
                                        <div style="padding: 0.5rem 0.75rem; font-size: 0.85rem; opacity: 0.7;">
                                            <i class="fas fa-users me-2"></i> Clients
                                        </div>
                                    </div>
                                    <!-- Content preview -->
                                    <div style="background: #f8f9fa; padding: 1rem;">
                                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                                            <button id="preview-btn-primary" style="padding: 0.4rem 0.9rem; border: none; border-radius: 6px; color: white; font-size: 0.85rem; cursor: default;">
                                                <i class="fas fa-check me-1"></i> Primary Button
                                            </button>
                                            <button id="preview-btn-secondary" style="padding: 0.4rem 0.9rem; border: none; border-radius: 6px; color: white; font-size: 0.85rem; cursor: default;">
                                                <i class="fas fa-save me-1"></i> Secondary Button
                                            </button>
                                            <span id="preview-badge" style="padding: 0.25rem 0.6rem; border-radius: 999px; color: white; font-size: 0.75rem;">Badge</span>
                                            <a id="preview-link" href="#" style="font-size: 0.85rem;" onclick="return false;">Sample link</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Reset to Defaults -->
                        <hr class="my-4">
                        <div class="card border-warning">
                            <div class="card-header bg-warning bg-opacity-10">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-rotate-left"></i> Reset to Default Colors
                                </h6>
                            </div>
                            <div class="card-body">
                                <p class="card-text small">
                                    Restore all theme colors to the original BDTA brand colors
                                    (<strong>Purple</strong> primary, <strong>Teal</strong> secondary).
                                </p>
                                <form method="POST" action="?category=theme"
                                      onsubmit="return confirm('Reset all theme colors to defaults? This cannot be undone.');">
                                    <input type="hidden" name="category" value="theme">
                                    <button type="submit" name="reset_theme" value="1" class="btn btn-outline-warning">
                                        <i class="fas fa-rotate-left"></i> Reset to Defaults
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($current_category === 'theme'): ?>
<script>
(function () {
    // Map setting keys to preview element roles
    const previewMap = {
        theme_primary_color:      ['#preview-btn-primary', '#preview-badge', '#preview-link'],
        theme_primary_dark_color: [],
        theme_secondary_color:    ['#preview-btn-secondary'],
        theme_sidebar_bg_start:   null, // handled via gradient
        theme_sidebar_bg_end:     null,
    };

    function hexToRgb(hex) {
        const r = parseInt(hex.slice(1,3),16);
        const g = parseInt(hex.slice(3,5),16);
        const b = parseInt(hex.slice(5,7),16);
        return {r, g, b};
    }

    // Darken a hex color by a percentage
    function darken(hex, pct) {
        const {r, g, b} = hexToRgb(hex);
        const factor = 1 - pct / 100;
        const to2 = v => Math.round(Math.max(0, v * factor)).toString(16).padStart(2,'0');
        return '#' + to2(r) + to2(g) + to2(b);
    }

    function getVal(key) {
        const el = document.getElementById(key);
        return el ? el.value : null;
    }

    function updatePreview() {
        const primary      = getVal('theme_primary_color')      || '#9a0073';
        const secondary    = getVal('theme_secondary_color')    || '#0a9a9c';
        const sideStart    = getVal('theme_sidebar_bg_start')   || primary;
        const sideEnd      = getVal('theme_sidebar_bg_end')     || darken(primary, 20);

        const sidebar = document.getElementById('preview-sidebar');
        if (sidebar) {
            sidebar.style.background = 'linear-gradient(135deg, ' + sideStart + ' 0%, ' + sideEnd + ' 100%)';
        }

        const btnPrimary = document.getElementById('preview-btn-primary');
        if (btnPrimary) btnPrimary.style.backgroundColor = primary;

        const badge = document.getElementById('preview-badge');
        if (badge) badge.style.backgroundColor = primary;

        const link = document.getElementById('preview-link');
        if (link) link.style.color = primary;

        const navLink = document.getElementById('preview-nav-link');
        if (navLink) navLink.style.backgroundColor = 'rgba(255,255,255,0.2)';

        const btnSecondary = document.getElementById('preview-btn-secondary');
        if (btnSecondary) btnSecondary.style.backgroundColor = secondary;
    }

    // Sync hex text input <-> color picker
    document.querySelectorAll('.theme-color-input').forEach(function(picker) {
        const key = picker.dataset.colorKey;
        const hexInput = document.querySelector('.color-hex-display[data-for="' + key + '"]');

        picker.addEventListener('input', function() {
            if (hexInput) hexInput.value = picker.value;
            updatePreview();
        });

        if (hexInput) {
            hexInput.addEventListener('input', function() {
                const val = hexInput.value.trim();
                if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
                    picker.value = val;
                    updatePreview();
                }
            });
        }
    });

    // Initial preview render
    updatePreview();
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
