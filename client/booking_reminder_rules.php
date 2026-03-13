<?php
/**
 * Booking Reminder Rules — configure per-rule reminder timing and email templates
 *
 * Each rule defines:
 *   • How many hours before the appointment the reminder is sent
 *   • An optional email template override for that specific reminder
 *
 * The cron BookingReminderTask iterates over all active rules and sends
 * a separate email per rule per booking.
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';

requireLogin();

$db   = new Database();
$conn = $db->getConnection();

// -------------------------------------------------------------------
// Handle POST actions: add, update, delete, toggle
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = scalar_string($_POST['action'] ?? '');

    if ($action === 'delete') {
        $del_id = safe_int($_POST['rule_id'] ?? 0);
        if ($del_id > 0) {
            $conn->prepare("DELETE FROM booking_reminder_rules WHERE id = ? AND appointment_type_id IS NULL")->execute([$del_id]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Reminder rule deleted.'];
        }
        header('Location: booking_reminder_rules.php');
        exit;
    }

    if ($action === 'toggle') {
        $tog_id = safe_int($_POST['rule_id'] ?? 0);
        if ($tog_id > 0) {
            $conn->prepare("UPDATE booking_reminder_rules SET is_active = NOT is_active, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND appointment_type_id IS NULL")
                 ->execute([$tog_id]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Rule status updated.'];
        }
        header('Location: booking_reminder_rules.php');
        exit;
    }

    if (in_array($action, ['add', 'update'], true)) {
        $name        = trim(scalar_string($_POST['name'] ?? ''));
        $hours_before = safe_int($_POST['hours_before'] ?? 0);
        $template_id  = !empty($_POST['template_id']) ? safe_int($_POST['template_id']) : null;
        $is_active    = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $hours_before < 1) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Name and hours before are required (minimum 1 hour).'];
            header('Location: booking_reminder_rules.php' . ($action === 'update' ? '?edit=' . safe_int($_POST['rule_id'] ?? 0) : ''));
            exit;
        }

        if ($action === 'update') {
            $rule_id = safe_int($_POST['rule_id'] ?? 0);
            $conn->prepare("
                UPDATE booking_reminder_rules
                SET name = ?, hours_before = ?, template_id = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND appointment_type_id IS NULL
            ")->execute([$name, $hours_before, $template_id, $is_active, $rule_id]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Reminder rule updated.'];
        } else {
            $conn->prepare("
                INSERT INTO booking_reminder_rules (appointment_type_id, name, hours_before, template_id, is_active)
                VALUES (NULL, ?, ?, ?, ?)
            ")->execute([$name, $hours_before, $template_id, $is_active]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Reminder rule added.'];
        }

        header('Location: booking_reminder_rules.php');
        exit;
    }
}

// -------------------------------------------------------------------
// Load data — global rules only (appointment_type_id IS NULL)
// -------------------------------------------------------------------
$rules = $conn->query(
    "SELECT r.*, et.name AS template_name
     FROM booking_reminder_rules r
     LEFT JOIN email_templates et ON et.id = r.template_id
     WHERE r.appointment_type_id IS NULL
     ORDER BY r.hours_before ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$reminder_templates = $conn->query(
    "SELECT id, name FROM email_templates WHERE template_type = 'booking_reminder' AND is_active = 1 ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

// If editing an existing global rule, pre-load it
$edit_rule = null;
$editing   = false;
if (isset($_GET['edit'])) {
    $edit_id = safe_int($_GET['edit']);
    $stmt    = $conn->prepare("SELECT * FROM booking_reminder_rules WHERE id = ? AND appointment_type_id IS NULL");
    $stmt->execute([$edit_id]);
    $edit_rule = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($edit_rule) {
        $editing = true;
    }
}

/**
 * Convert hours to a human-readable label.
 */
function hoursLabel(int $hours): string {
    if ($hours >= 168 && $hours % 168 === 0) {
        $w = $hours / 168;
        return $w . ' week' . ($w !== 1 ? 's' : '') . ' before';
    }
    if ($hours >= 24 && $hours % 24 === 0) {
        $d = $hours / 24;
        return $d . ' day' . ($d !== 1 ? 's' : '') . ' before';
    }
    return $hours . ' hour' . ($hours !== 1 ? 's' : '') . ' before';
}

$page_title = 'Booking Reminder Rules';
include __DIR__ . '/../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="fas fa-bell me-2"></i>Booking Reminder Rules
                </h2>
                <a href="email_template_defaults.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-envelope-open-text me-1"></i> Template Defaults
                </a>
            </div>

            <div class="alert alert-info">
                <i class="fas fa-circle-info me-1"></i>
                These are <strong>global default</strong> reminder rules — they apply to all appointment types that don't have their own per-type rules configured.
                Each active rule sends a separate reminder email at the configured time.
                You can assign a different email template to each rule — for example, a gentle teaser 2 days out
                and a detailed checklist the day before.<br>
                <small>To configure reminder rules for a specific appointment type, edit the appointment type and use the <strong>Reminder Rules</strong> section there.</small>
            </div>

            <!-- Add / Edit Rule Form -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <?= $editing ? '<i class="fas fa-pencil me-1"></i> Edit Rule' : '<i class="fas fa-circle-plus me-1"></i> Add Reminder Rule' ?>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
                        <?php if ($editing): ?>
                            <input type="hidden" name="rule_id" value="<?= is_array($edit_rule) ? array_int_value($edit_rule, 'id') : 0 ?>">
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Rule Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" required
                                       value="<?= htmlspecialchars($edit_rule['name'] ?? '') ?>"
                                       placeholder="e.g., Day Before, 2 Days Before">
                                <div class="form-text">A short label for this reminder rule.</div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Hours Before Appointment <span class="text-danger">*</span></label>
                                <input type="number" name="hours_before" class="form-control" required
                                       min="1" step="1"
                                       value="<?= (int)($edit_rule['hours_before'] ?? 24) ?>">
                                <div class="form-text">
                                    24 = 1 day &bull; 48 = 2 days &bull; 72 = 3 days &bull; 168 = 1 week
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Email Template</label>
                                <select name="template_id" class="form-select">
                                    <option value="">— Use system default —</option>
                                    <?php foreach ($reminder_templates as $tmpl): ?>
                                        <option value="<?= $tmpl['id'] ?>"
                                            <?= (isset($edit_rule['template_id']) && $edit_rule['template_id'] == $tmpl['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($tmpl['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    Template to use for this reminder.
                                    <?php if (empty($reminder_templates)): ?>
                                        <span class="text-warning">
                                            <i class="fas fa-triangle-exclamation"></i>
                                            No <em>booking_reminder</em> templates found.
                                            <a href="email_templates_edit.php">Create one</a>.
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <div class="form-check form-switch mb-1">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="form_is_active"
                                           <?= (!$editing || !empty($edit_rule['is_active'])) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="form_is_active">Active</label>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-<?= $editing ? 'check' : 'plus' ?>-circle me-1"></i>
                                <?= $editing ? 'Update Rule' : 'Add Rule' ?>
                            </button>
                            <?php if ($editing): ?>
                                <a href="booking_reminder_rules.php" class="btn btn-secondary ms-2">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Rules List -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-list me-1"></i> Configured Rules</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($rules)): ?>
                        <div class="alert alert-warning mb-0">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            No reminder rules configured. Add a rule above to start sending reminders.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Timing</th>
                                        <th>Email Template</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rules as $rule): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($rule['name']) ?></strong></td>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?= (int)$rule['hours_before'] ?>h
                                                </span>
                                                <small class="text-muted ms-1"><?= hoursLabel((int)$rule['hours_before']) ?></small>
                                            </td>
                                            <td>
                                                <?php if ($rule['template_name']): ?>
                                                    <a href="email_templates_edit.php?id=<?= (int)$rule['template_id'] ?>"
                                                       class="text-decoration-none">
                                                        <i class="fas fa-envelope-open-text text-primary me-1"></i>
                                                        <?= htmlspecialchars($rule['template_name']) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted fst-italic">System default</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($rule['is_active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="booking_reminder_rules.php?edit=<?= (int)$rule['id'] ?>"
                                                       class="btn btn-outline-primary" title="Edit">
                                                        <i class="fas fa-pencil"></i>
                                                    </a>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action"  value="toggle">
                                                        <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-secondary"
                                                                title="<?= $rule['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                                            <i class="fas fa-<?= $rule['is_active'] ? 'pause' : 'play' ?>"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="d-inline"
                                                          onsubmit="return confirm('Delete this reminder rule?')">
                                                        <input type="hidden" name="action"  value="delete">
                                                        <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-danger" title="Delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3 text-muted small">
                            <i class="fas fa-circle-info me-1"></i>
                            Rules are processed by the <strong>Booking Reminder</strong> cron task.
                            Each active rule sends one email per booking at its configured time.
                            See <a href="scheduled_tasks_list.php">Scheduled Tasks</a> for cron setup.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
