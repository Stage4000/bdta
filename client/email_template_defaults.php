<?php
/**
 * Email Template Defaults — Assign system-wide default templates for automated tasks
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';
require_once __DIR__ . '/../backend/includes/settings.php';

requireLogin();

$db   = new Database();
$conn = $db->getConnection();

// Automated task types with human-readable labels
$task_types = [
    'booking_confirmation' => [
        'label'       => 'Booking Confirmation',
        'description' => 'Sent automatically when a booking is created.',
        'setting_key' => 'default_confirmation_template_id',
        'icon'        => 'calendar-check',
        'color'       => 'primary',
    ],
    'booking_reminder' => [
        'label'       => 'Booking Reminder',
        'description' => 'Sent automatically before an upcoming appointment.',
        'setting_key' => 'default_reminder_template_id',
        'icon'        => 'bell',
        'color'       => 'warning',
    ],
    'payment_receipt' => [
        'label'       => 'Payment Receipt',
        'description' => 'Sent when a payment is recorded.',
        'setting_key' => 'default_payment_receipt_template_id',
        'icon'        => 'receipt',
        'color'       => 'success',
    ],
    'booking_cancellation' => [
        'label'       => 'Booking Cancellation',
        'description' => 'Sent automatically when an appointment is cancelled.',
        'setting_key' => 'default_cancellation_template_id',
        'icon'        => 'calendar-xmark',
        'color'       => 'danger',
    ],
];

// Load available templates grouped by type
$all_templates = $conn->query(
    "SELECT id, name, template_type FROM email_templates WHERE is_active = 1 ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

$templates_by_type = [];
foreach ($all_templates as $t) {
    $templates_by_type[$t['template_type']][] = $t;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_defaults'])) {
    foreach ($task_types as $type_key => $type_info) {
        $setting_key = $type_info['setting_key'];
        $value       = !empty($_POST[$setting_key]) ? safe_int($_POST[$setting_key]) : 0;
        Settings::set($setting_key, (string)$value);
    }
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Default email templates saved.'];
    header('Location: email_template_defaults.php');
    exit;
}

$page_title = 'Email Template Defaults';
include __DIR__ . '/../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="fas fa-envelope-open-text me-2"></i>Email Template Defaults
                </h2>
                <a href="email_templates_list.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-list me-1"></i> Manage Templates
                </a>
            </div>

            <div class="alert alert-info">
                <i class="fas fa-circle-info me-1"></i>
                Assign a default email template for each automated task. Individual appointment types can override these
                defaults — configure overrides on the
                <a href="appointment_types_list.php">Appointment Types</a> edit page.
                Select <strong>— Use built-in template —</strong> to keep the system's hardcoded email for any task.
            </div>

            <div class="card">
                <div class="card-body">
                    <form method="POST">
                        <?php foreach ($task_types as $type_key => $type_info): ?>
                            <?php
                            $setting_key     = $type_info['setting_key'];
                            $current_id      = safe_int(Settings::get($setting_key, 0));
                            $available       = $templates_by_type[$type_key] ?? [];
                            ?>
                            <div class="row g-3 mb-4 align-items-start">
                                <div class="col-md-4">
                                    <div class="d-flex align-items-center">
                                        <span class="badge bg-<?= $type_info['color'] ?> me-2 p-2">
                                            <i class="fas fa-<?= $type_info['icon'] ?>"></i>
                                        </span>
                                        <div>
                                            <strong><?= htmlspecialchars($type_info['label']) ?></strong>
                                            <div class="text-muted small"><?= htmlspecialchars($type_info['description']) ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-5">
                                    <select class="form-select" name="<?= htmlspecialchars($setting_key) ?>">
                                        <option value="0">— Use built-in template —</option>
                                        <?php foreach ($available as $tmpl): ?>
                                            <option value="<?= $tmpl['id'] ?>"
                                                <?= $current_id === (int)$tmpl['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($tmpl['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($available)): ?>
                                        <div class="form-text text-warning">
                                            <i class="fas fa-triangle-exclamation"></i>
                                            No active <strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', $type_key))) ?></strong>
                                            templates found.
                                            <a href="email_templates_edit.php">Create one</a>.
                                        </div>
                                    <?php else: ?>
                                        <div class="form-text">
                                            <?= count($available) ?> template(s) available for this task type.
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-3">
                                    <?php if ($current_id > 0): ?>
                                        <a href="email_templates_edit.php?id=<?= $current_id ?>"
                                           class="btn btn-outline-secondary btn-sm">
                                            <i class="fas fa-pencil"></i> Edit selected
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (array_key_last($task_types) !== $type_key): ?>
                                <hr class="my-3">
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <hr class="my-4">
                        <button type="submit" name="save_defaults" class="btn btn-primary">
                            <i class="fas fa-check-circle me-1"></i> Save Defaults
                        </button>
                        <a href="email_templates_list.php" class="btn btn-secondary ms-2">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
