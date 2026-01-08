<?php
/**
 * Settings Management - Admin Panel
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/settings.php';

requireLogin();

// Helper function to get category icons
function getCategoryIcon($category) {
    $icons = [
        'general' => 'info-circle',
        'email' => 'envelope',
        'payment' => 'credit-card',
        'booking' => 'calendar-check',
        'calendar' => 'calendar3',
        'invoice' => 'file-earmark-text',
        'time_tracking' => 'clock',
        'social' => 'share',
        'advanced' => 'gear-fill'
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
        
        foreach ($_POST as $key => $value) {
            if ($key !== 'save_settings' && $key !== 'category' && in_array($key, $valid_keys)) {
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
        redirect(ADMIN_URL . 'settings.php?category=' . $current_category);
    } catch (Exception $e) {
        setFlashMessage('Error saving settings: ' . $e->getMessage(), 'danger');
    }
}

// Get settings for current category
$settings = Settings::getCategory($current_category);

include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h2><i class="bi bi-gear"></i> Settings</h2>
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
                            <i class="bi bi-<?= getCategoryIcon($category) ?>"></i>
                            <?= escape(ucwords(str_replace('_', ' ', $category))) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <h6 class="card-title"><i class="bi bi-info-circle"></i> Help</h6>
                    <p class="card-text small text-muted">
                        Changes to settings take effect immediately. Settings marked with 
                        <i class="bi bi-shield-lock"></i> are sensitive and will be masked.
                    </p>
                </div>
            </div>
        </div>

        <!-- Settings Form -->
        <div class="col-md-9">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-<?= getCategoryIcon($current_category) ?>"></i>
                        <?= escape(ucwords(str_replace('_', ' ', $current_category))) ?> Settings
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="category" value="<?= escape($current_category) ?>">
                        
                        <?php foreach ($settings as $setting): ?>
                            <div class="mb-3">
                                <label for="<?= escape($setting['key']) ?>" class="form-label">
                                    <?= escape($setting['label']) ?>
                                    <?php if ($setting['is_secret']): ?>
                                        <i class="bi bi-shield-lock text-warning" title="Sensitive data"></i>
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
                                <i class="bi bi-check-lg"></i> Save Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
