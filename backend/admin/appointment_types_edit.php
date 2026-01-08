<?php
/**
 * Brook's Dog Training Academy - Add/Edit Appointment Type
 * Configure appointment type with rules and behaviors
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_edit = $id > 0;

// Get existing type data if editing
$type = null;
if ($is_edit) {
    $stmt = $conn->prepare("SELECT * FROM appointment_types WHERE id = ?");
    $stmt->execute([$id]);
    $type = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$type) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Appointment type not found.'];
        header('Location: appointment_types_list.php');
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $description = $_POST['description'] ?? '';
    $duration_minutes = (int)($_POST['duration_minutes'] ?? 60);
    $buffer_before_minutes = (int)($_POST['buffer_before_minutes'] ?? 0);
    $buffer_after_minutes = (int)($_POST['buffer_after_minutes'] ?? 0);
    $advance_booking_min_days = (int)($_POST['advance_booking_min_days'] ?? 1);
    $advance_booking_max_days = (int)($_POST['advance_booking_max_days'] ?? 90);
    $requires_forms = isset($_POST['requires_forms']) ? 1 : 0;
    $requires_contract = isset($_POST['requires_contract']) ? 1 : 0;
    $auto_invoice = isset($_POST['auto_invoice']) ? 1 : 0;
    $invoice_due_days = (int)($_POST['invoice_due_days'] ?? 7);
    $consumes_credits = isset($_POST['consumes_credits']) ? 1 : 0;
    $credit_count = (int)($_POST['credit_count'] ?? 1);
    $is_group_class = isset($_POST['is_group_class']) ? 1 : 0;
    $max_participants = (int)($_POST['max_participants'] ?? 1);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    try {
        if ($is_edit) {
            $stmt = $conn->prepare("
                UPDATE appointment_types SET
                    name = ?,
                    description = ?,
                    duration_minutes = ?,
                    buffer_before_minutes = ?,
                    buffer_after_minutes = ?,
                    advance_booking_min_days = ?,
                    advance_booking_max_days = ?,
                    requires_forms = ?,
                    requires_contract = ?,
                    auto_invoice = ?,
                    invoice_due_days = ?,
                    consumes_credits = ?,
                    credit_count = ?,
                    is_group_class = ?,
                    max_participants = ?,
                    is_active = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $name, $description, $duration_minutes,
                $buffer_before_minutes, $buffer_after_minutes,
                $advance_booking_min_days, $advance_booking_max_days,
                $requires_forms, $requires_contract,
                $auto_invoice, $invoice_due_days,
                $consumes_credits, $credit_count,
                $is_group_class, $max_participants,
                $is_active, $id
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Appointment type updated successfully!'];
        } else {
            $stmt = $conn->prepare("
                INSERT INTO appointment_types (
                    name, description, duration_minutes,
                    buffer_before_minutes, buffer_after_minutes,
                    advance_booking_min_days, advance_booking_max_days,
                    requires_forms, requires_contract,
                    auto_invoice, invoice_due_days,
                    consumes_credits, credit_count,
                    is_group_class, max_participants,
                    is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $description, $duration_minutes,
                $buffer_before_minutes, $buffer_after_minutes,
                $advance_booking_min_days, $advance_booking_max_days,
                $requires_forms, $requires_contract,
                $auto_invoice, $invoice_due_days,
                $consumes_credits, $credit_count,
                $is_group_class, $max_participants,
                $is_active
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Appointment type created successfully!'];
        }
        
        header('Location: appointment_types_list.php');
        exit;
    } catch (PDOException $e) {
        $error = "Error saving appointment type: " . $e->getMessage();
    }
}

$page_title = $is_edit ? "Edit Appointment Type" : "Add Appointment Type";
include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <a href="appointment_types_list.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back to Appointment Types
        </a>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><?= $is_edit ? 'Edit' : 'Add' ?> Appointment Type</h5>
        </div>
        <div class="card-body">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <h6 class="border-bottom pb-2 mb-3">Basic Information</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?= htmlspecialchars($type['name'] ?? '') ?>" required>
                        <div class="form-text">The name of this appointment type</div>
                    </div>
                    <div class="col-md-6">
                        <label for="duration_minutes" class="form-label">Duration (minutes) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="duration_minutes" name="duration_minutes" 
                               value="<?= $type['duration_minutes'] ?? 60 ?>" min="5" step="5" required>
                        <div class="form-text">Length of the appointment</div>
                    </div>
                    <div class="col-12">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?= htmlspecialchars($type['description'] ?? '') ?></textarea>
                        <div class="form-text">Brief description of this appointment type</div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Booking Rules</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="buffer_before_minutes" class="form-label">Buffer Before (minutes)</label>
                        <input type="number" class="form-control" id="buffer_before_minutes" name="buffer_before_minutes" 
                               value="<?= $type['buffer_before_minutes'] ?? 0 ?>" min="0" step="5">
                        <div class="form-text">Time blocked before appointment starts</div>
                    </div>
                    <div class="col-md-6">
                        <label for="buffer_after_minutes" class="form-label">Buffer After (minutes)</label>
                        <input type="number" class="form-control" id="buffer_after_minutes" name="buffer_after_minutes" 
                               value="<?= $type['buffer_after_minutes'] ?? 0 ?>" min="0" step="5">
                        <div class="form-text">Time blocked after appointment ends</div>
                    </div>
                    <div class="col-md-6">
                        <label for="advance_booking_min_days" class="form-label">Minimum Advance Booking (days)</label>
                        <input type="number" class="form-control" id="advance_booking_min_days" name="advance_booking_min_days" 
                               value="<?= $type['advance_booking_min_days'] ?? 1 ?>" min="0">
                        <div class="form-text">Clients must book at least this many days in advance</div>
                    </div>
                    <div class="col-md-6">
                        <label for="advance_booking_max_days" class="form-label">Maximum Advance Booking (days)</label>
                        <input type="number" class="form-control" id="advance_booking_max_days" name="advance_booking_max_days" 
                               value="<?= $type['advance_booking_max_days'] ?? 90 ?>" min="1">
                        <div class="form-text">Clients can book up to this many days in advance</div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Requirements</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="requires_forms" name="requires_forms"
                                   <?= !empty($type['requires_forms']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="requires_forms">
                                Requires Forms
                            </label>
                            <div class="form-text">Client must complete required forms before booking</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="requires_contract" name="requires_contract"
                                   <?= !empty($type['requires_contract']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="requires_contract">
                                Requires Contract
                            </label>
                            <div class="form-text">Client must sign contract before booking</div>
                        </div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Invoice Behavior</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="auto_invoice" name="auto_invoice"
                                   <?= !empty($type['auto_invoice']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="auto_invoice">
                                Auto-Invoice
                            </label>
                            <div class="form-text">Automatically create invoice for this appointment type</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="invoice_due_days" class="form-label">Invoice Due (days after appointment)</label>
                        <input type="number" class="form-control" id="invoice_due_days" name="invoice_due_days" 
                               value="<?= $type['invoice_due_days'] ?? 7 ?>" min="0">
                        <div class="form-text">Invoice due date offset from appointment</div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Credits System</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="consumes_credits" name="consumes_credits"
                                   <?= !empty($type['consumes_credits']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="consumes_credits">
                                Consumes Credits
                            </label>
                            <div class="form-text">This appointment type uses session credits</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="credit_count" class="form-label">Credit Count</label>
                        <input type="number" class="form-control" id="credit_count" name="credit_count" 
                               value="<?= $type['credit_count'] ?? 1 ?>" min="1">
                        <div class="form-text">Number of credits consumed per appointment</div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Group Classes</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_group_class" name="is_group_class"
                                   <?= !empty($type['is_group_class']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_group_class">
                                Is Group Class
                            </label>
                            <div class="form-text">This appointment type supports multiple participants</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="max_participants" class="form-label">Maximum Participants</label>
                        <input type="number" class="form-control" id="max_participants" name="max_participants" 
                               value="<?= $type['max_participants'] ?? 1 ?>" min="1">
                        <div class="form-text">Maximum number of clients for group classes</div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Status</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                                   <?= !isset($type) || !empty($type['is_active']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">
                                Active
                            </label>
                            <div class="form-text">Only active types are available for booking</div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> <?= $is_edit ? 'Update' : 'Create' ?> Appointment Type
                    </button>
                    <a href="appointment_types_list.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
