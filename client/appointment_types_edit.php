<?php
/**
 * Brook's Dog Training Academy - Add/Edit Appointment Type
 * Configure appointment type with rules and behaviors
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';

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

// Get base URL for building booking link dynamically from current request
$base_url = getDynamicBaseUrl();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $description = $_POST['description'] ?? '';
    $duration_minutes = (int)($_POST['duration_minutes'] ?? 60);
    $buffer_before_minutes = (int)($_POST['buffer_before_minutes'] ?? 0);
    $buffer_after_minutes = (int)($_POST['buffer_after_minutes'] ?? 0);
    $use_travel_time_buffer = isset($_POST['use_travel_time_buffer']) ? 1 : 0;
    $travel_time_minutes = (int)($_POST['travel_time_minutes'] ?? 0);
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
                    use_travel_time_buffer = ?,
                    travel_time_minutes = ?,
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
                $use_travel_time_buffer, $travel_time_minutes,
                $advance_booking_min_days, $advance_booking_max_days,
                $requires_forms, $requires_contract,
                $auto_invoice, $invoice_due_days,
                $consumes_credits, $credit_count,
                $is_group_class, $max_participants,
                $is_active, $id
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Appointment type updated successfully!'];
        } else {
            // Generate unique link for new appointment type with collision detection
            do {
                $unique_link = bin2hex(random_bytes(16));
                $check_stmt = $conn->prepare("SELECT COUNT(*) FROM appointment_types WHERE unique_link = ?");
                $check_stmt->execute([$unique_link]);
                $exists = $check_stmt->fetchColumn();
            } while ($exists > 0);
            
            $stmt = $conn->prepare("
                INSERT INTO appointment_types (
                    name, description, duration_minutes,
                    buffer_before_minutes, buffer_after_minutes,
                    use_travel_time_buffer, travel_time_minutes,
                    advance_booking_min_days, advance_booking_max_days,
                    requires_forms, requires_contract,
                    auto_invoice, invoice_due_days,
                    consumes_credits, credit_count,
                    is_group_class, max_participants,
                    is_active, unique_link
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $description, $duration_minutes,
                $buffer_before_minutes, $buffer_after_minutes,
                $use_travel_time_buffer, $travel_time_minutes,
                $advance_booking_min_days, $advance_booking_max_days,
                $requires_forms, $requires_contract,
                $auto_invoice, $invoice_due_days,
                $consumes_credits, $credit_count,
                $is_group_class, $max_participants,
                $is_active, $unique_link
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
include __DIR__ . '/../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <a href="appointment_types_list.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Back to Appointment Types
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

            <?php if ($is_edit && !empty($type['unique_link'])): ?>
                <div class="alert alert-info">
                    <h6 class="alert-heading"><i class="fas fa-link"></i> Unique Booking Link</h6>
                    <p class="mb-2">Share this link with clients to book this appointment type directly:</p>
                    <div class="input-group">
                        <input type="text" class="form-control" id="booking-link" 
                               value="<?= htmlspecialchars($base_url . '/backend/public/book.php?link=' . $type['unique_link']) ?>" 
                               readonly>
                        <button class="btn btn-outline-secondary" type="button" onclick="copyBookingLink(event)">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                    </div>
                    <small class="text-muted">This link was automatically generated and is unique to this appointment type.</small>
                </div>
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
                </div>
                
                <!-- Phase 2: Travel Time Buffer -->
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="use_travel_time_buffer" name="use_travel_time_buffer" 
                                   value="1" <?= ($type['use_travel_time_buffer'] ?? 0) ? 'checked' : '' ?>
                                   onchange="toggleTravelTime()">
                            <label class="form-check-label" for="use_travel_time_buffer">
                                Use Travel Time Buffer (Phase 2 Feature)
                            </label>
                            <div class="form-text">Automatically calculate buffers based on travel time instead of fixed values</div>
                        </div>
                    </div>
                </div>
                
                <div class="row mb-3" id="travel_time_section" style="display: none;">
                    <div class="col-md-6">
                        <label for="travel_time_minutes" class="form-label">Travel Time (minutes)</label>
                        <input type="number" class="form-control" id="travel_time_minutes" name="travel_time_minutes" 
                               value="<?= $type['travel_time_minutes'] ?? 0 ?>" min="0" step="5">
                        <div class="form-text">Time needed for travel to/from appointment location</div>
                    </div>
                </div>
                
                <div class="row mb-3">
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
                        <i class="fas fa-check-circle"></i> <?= $is_edit ? 'Update' : 'Create' ?> Appointment Type
                    </button>
                    <a href="appointment_types_list.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Phase 2: Travel Time Buffer Toggle
function toggleTravelTime() {
    const checkbox = document.getElementById('use_travel_time_buffer');
    const section = document.getElementById('travel_time_section');
    section.style.display = checkbox.checked ? 'block' : 'none';
}

// Copy booking link to clipboard
function copyBookingLink(event) {
    const linkInput = document.getElementById('booking-link');
    
    navigator.clipboard.writeText(linkInput.value).then(function() {
        // Show success feedback
        const btn = event.target.closest('button');
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-success');
        
        setTimeout(function() {
            btn.innerHTML = originalHTML;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-secondary');
        }, 2000);
    }).catch(function(err) {
        // Fallback: select the text so user can copy manually
        linkInput.select();
        linkInput.setSelectionRange(0, 99999);
        alert('Could not copy automatically. The link is now selected - please press Ctrl+C (or Cmd+C) to copy.');
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleTravelTime();
});
</script>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
