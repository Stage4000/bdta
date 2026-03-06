<?php
/**
 * Settings Management - Admin Panel
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/settings.php';

requireLogin();

// Helper function to get category icons
function getCategoryIcon($category) {
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
        'advanced' => 'gear'
    ];
    return $icons[$category] ?? 'gear';
}

// Helper function to get select options
function getSelectOptions($key) {
    $options_map = [
        'email_service' => [
            'mail' => 'PHP mail() function',
            'smtp' => 'SMTP',
            'sendgrid' => 'SendGrid',
            'mailgun' => 'Mailgun',
            'ses' => 'Amazon SES'
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
        ],
        'db_type' => [
            'sqlite' => 'SQLite (Development/Testing)',
            'mysql' => 'MySQL (Production)'
        ]
    ];
    
    return $options_map[$key] ?? [];
}

$page_title = 'Settings';

// Get all categories
$categories = Settings::getCategories();
$current_category = $_GET['category'] ?? 'general';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    try {
        // Get all settings for current category to validate keys
        $valid_settings = Settings::getCategory($current_category);
        $valid_keys = array_column($valid_settings, 'key');
        
        // Special handling for database category - only update .env file
        if ($current_category === 'database') {
            $env_updates = [];
            $env_key_map = [
                'db_type' => 'DB_TYPE',
                'db_host' => 'DB_HOST',
                'db_port' => 'DB_PORT',
                'db_name' => 'DB_NAME',
                'db_user' => 'DB_USER',
                'db_password' => 'DB_PASSWORD',
                'sqlite_db_path' => 'SQLITE_DB_PATH'
            ];
            
            foreach ($_POST as $key => $value) {
                if ($key !== 'save_settings' && $key !== 'category' && in_array($key, $valid_keys)) {
                    if (isset($env_key_map[$key])) {
                        $env_updates[$env_key_map[$key]] = $value;
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
                if ($key !== 'save_settings' && $key !== 'category' && in_array($key, $valid_keys)) {
                    // Validate color values to only accept valid hex colors
                    $setting_info = null;
                    foreach ($valid_settings as $s) {
                        if ($s['key'] === $key) { $setting_info = $s; break; }
                    }
                    if ($setting_info && $setting_info['type'] === 'color') {
                        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                            continue; // Skip invalid color values
                        }
                    }
                    Settings::set($key, $value);
                }
            }
            
            // Handle unchecked checkboxes (they don't appear in $_POST)
            foreach ($valid_settings as $setting) {
                if ($setting['type'] === 'checkbox' && !isset($_POST[$setting['key']])) {
                    Settings::set($setting['key'], '0');
                }
            }
            
            setFlashMessage('Settings saved successfully!', 'success');
        }
        
        redirect(ADMIN_URL . 'settings.php?category=' . $current_category);
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
function updateEnvFile($updates) {
    $env_file = __DIR__ . '/../.env';
    $env_example = __DIR__ . '/../.env.example';
    
    // Create .env from .env.example if it doesn't exist
    if (!file_exists($env_file) && file_exists($env_example)) {
        copy($env_example, $env_file);
    }
    
    $lines = [];
    if (file_exists($env_file)) {
        $lines = file($env_file, FILE_IGNORE_NEW_LINES);
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
            if (preg_match('/[\s#]/', $value)) {
                $value = '"' . addslashes($value) . '"';
            }
            $lines[] = "$key=$value";
        }
    }
    
    // Write back to file
    file_put_contents($env_file, implode("\n", $lines) . "\n");
}

// Get settings for current category
$settings = Settings::getCategory($current_category);

// For calendar category: load OAuth connection status and generate CSRF token
$oauth_token_row  = null;
$oauth_configured = false;
if ($current_category === 'calendar') {
    require_once __DIR__ . '/../backend/includes/google_calendar.php';
    $oauth_configured = GoogleCalendarIntegration::isOAuthConfigured();
    $oauth_token_row  = GoogleCalendarIntegration::getOAuthToken((int)$_SESSION['admin_id']);
    // Generate a CSRF token for the disconnect form
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
}

include __DIR__ . '/../backend/includes/header.php';
?>

<?php
// Get theme colors for inline styles that aren't covered by header.php
$st_primary      = (preg_match('/^#[0-9A-Fa-f]{6}$/', Settings::get('theme_primary_color', '#9a0073')))      ? Settings::get('theme_primary_color', '#9a0073')      : '#9a0073';
$st_primary_dark = (preg_match('/^#[0-9A-Fa-f]{6}$/', Settings::get('theme_primary_dark_color', '#7a005a'))) ? Settings::get('theme_primary_dark_color', '#7a005a') : '#7a005a';
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
                                Configure your database backend. The system supports MySQL for production and SQLite for development.
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
                                
                                <?php if ($setting['type'] === 'textarea'): ?>
                                    <textarea 
                                        class="form-control" 
                                        id="<?= escape($setting['key']) ?>" 
                                        name="<?= escape($setting['key']) ?>"
                                        rows="3"><?= escape($setting['actual_value']) ?></textarea>
                                
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
                                        $options = getSelectOptions($setting['key']);
                                        foreach ($options as $value => $label): 
                                        ?>
                                            <option value="<?= escape($value) ?>" <?= $setting['actual_value'] == $value ? 'selected' : '' ?>>
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
                                
                                <?php if ($setting['description']): ?>
                                    <div class="form-text"><?= escape($setting['description']) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                            <button type="submit" name="save_settings" class="btn btn-primary">
                                <i class="fas fa-check-lg"></i> Save Settings
                            </button>
                        </div>
                    </form>
                    
                    <?php if ($current_category === 'database'): ?>
                        <!-- Database Migration Tool -->
                        <hr class="my-4">
                        <div class="card bg-light">
                            <div class="card-header">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-arrow-right-arrow-left"></i> Database Migration Tool
                                </h6>
                            </div>
                            <div class="card-body">
                                <p class="card-text">
                                    Use this tool to migrate data from SQLite to MySQL or vice versa when updating existing installations.
                                </p>
                                <a href="database_migration.php" class="btn btn-warning">
                                    <i class="fas fa-database"></i> Open Migration Tool
                                </a>
                                <a href="<?= ADMIN_URL ?>../backend/MYSQL_MIGRATION.md" target="_blank" class="btn btn-outline-secondary">
                                    <i class="fas fa-book"></i> Migration Guide
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
                                <?php if ($oauth_token_row): ?>
                                    <!-- Connected state -->
                                    <div class="alert alert-success mb-3">
                                        <i class="fas fa-circle-check"></i>
                                        <strong>Connected</strong>
                                        <?php if (!empty($oauth_token_row['google_email'])): ?>
                                            as <strong><?= escape($oauth_token_row['google_email']) ?></strong>
                                        <?php endif; ?>
                                        <br>
                                        <small class="text-muted">
                                            Syncing to calendar:
                                            <strong><?= escape($oauth_token_row['calendar_id'] ?? 'primary') ?></strong>
                                            &mdash; connected <?= escape(date('M j, Y', strtotime($oauth_token_row['created_at']))) ?>
                                        </small>
                                    </div>

                                    <!-- Calendar selector -->
                                    <?php
                                    $user_calendars = GoogleCalendarIntegration::listCalendars((int)$_SESSION['admin_id']);
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
