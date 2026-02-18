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
        
        // Track database settings for .env update
        $db_settings_changed = false;
        $env_updates = [];
        
        foreach ($_POST as $key => $value) {
            if ($key !== 'save_settings' && $key !== 'category' && in_array($key, $valid_keys)) {
                Settings::set($key, $value);
                
                // Track database settings for .env file
                if ($current_category === 'database') {
                    $db_settings_changed = true;
                    $env_key_map = [
                        'db_type' => 'DB_TYPE',
                        'db_host' => 'DB_HOST',
                        'db_port' => 'DB_PORT',
                        'db_name' => 'DB_NAME',
                        'db_user' => 'DB_USER',
                        'db_password' => 'DB_PASSWORD',
                        'sqlite_db_path' => 'SQLITE_DB_PATH'
                    ];
                    if (isset($env_key_map[$key])) {
                        $env_updates[$env_key_map[$key]] = $value;
                    }
                }
            }
        }
        
        // Handle unchecked checkboxes (they don't appear in $_POST)
        foreach ($valid_settings as $setting) {
            if ($setting['type'] === 'checkbox' && !isset($_POST[$setting['key']])) {
                Settings::set($setting['key'], '0');
            }
        }
        
        // Update .env file if database settings changed
        if ($db_settings_changed && !empty($env_updates)) {
            updateEnvFile($env_updates);
        }
        
        setFlashMessage('Settings saved successfully!', 'success');
        redirect(ADMIN_URL . 'settings.php?category=' . $current_category);
    } catch (Exception $e) {
        setFlashMessage('Error saving settings: ' . $e->getMessage(), 'danger');
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

include __DIR__ . '/../backend/includes/header.php';
?>

<style>
    /* Custom styling for settings submenu active state */
    .list-group-item.active {
        background-color: #9a0073 !important;
        border-color: #9a0073 !important;
        color: white !important;
    }
    .list-group-item.active:hover {
        background-color: #7a005a !important;
        border-color: #7a005a !important;
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
                    
                    <form method="POST" action="">
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
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
