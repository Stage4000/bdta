<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$submission_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($submission_id == 0) {
    $_SESSION['flash_message'] = 'Invalid submission ID';
    $_SESSION['flash_type'] = 'danger';
    header('Location: form_submissions_list.php');
    exit;
}

// Handle review action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'review') {
        $notes = trim($_POST['notes'] ?? '');
        
        $update_query = "UPDATE form_submissions 
                        SET status = 'reviewed',
                            reviewed_by = ?,
                            reviewed_at = CURRENT_TIMESTAMP,
                            notes = ?
                        WHERE id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->execute([$_SESSION['user_id'], $notes, $submission_id]);
        
        $_SESSION['flash_message'] = 'Form marked as reviewed';
        $_SESSION['flash_type'] = 'success';
        header('Location: form_submissions_view.php?id=' . $submission_id);
        exit;
    } elseif ($_POST['action'] == 'unreview') {
        $update_query = "UPDATE form_submissions 
                        SET status = 'submitted',
                            reviewed_by = NULL,
                            reviewed_at = NULL
                        WHERE id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->execute([$submission_id]);
        
        $_SESSION['flash_message'] = 'Review status removed';
        $_SESSION['flash_type'] = 'success';
        header('Location: form_submissions_view.php?id=' . $submission_id);
        exit;
    }
}

// Get submission details
$query = "SELECT fs.*, 
          c.first_name || ' ' || c.last_name as client_name,
          c.email as client_email,
          c.phone as client_phone,
          ft.name as form_name,
          ft.form_type,
          ft.fields,
          b.appointment_date || ' ' || b.appointment_time as appointment_datetime,
          b.service_type,
          au.username as submitted_by_name,
          au2.username as reviewed_by_name
          FROM form_submissions fs
          LEFT JOIN clients c ON fs.client_id = c.id
          LEFT JOIN form_templates ft ON fs.template_id = ft.id
          LEFT JOIN bookings b ON fs.booking_id = b.id
          LEFT JOIN admin_users au ON fs.submitted_by = au.id
          LEFT JOIN admin_users au2 ON fs.reviewed_by = au2.id
          WHERE fs.id = ?";

$stmt = $conn->prepare($query);
$stmt->execute([$submission_id]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$submission) {
    $_SESSION['flash_message'] = 'Submission not found';
    $_SESSION['flash_type'] = 'danger';
    header('Location: form_submissions_list.php');
    exit;
}

// Decode JSON fields
$fields = json_decode($submission['fields'], true);
$responses = json_decode($submission['responses'], true);

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-file-earmark-check"></i> View Form Submission</h2>
        <a href="form_submissions_list.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to List
        </a>
    </div>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?= $_SESSION['flash_type'] ?> alert-dismissible fade show">
            <?= $_SESSION['flash_message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-8">
            <!-- Form Responses -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><?= htmlspecialchars($submission['form_name']) ?></h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($fields)): ?>
                        <?php foreach ($fields as $index => $field): ?>
                            <div class="mb-4">
                                <label class="fw-bold text-muted d-block mb-2">
                                    <?= htmlspecialchars($field['label']) ?>
                                    <?php if (!empty($field['required'])): ?>
                                        <span class="text-danger">*</span>
                                    <?php endif; ?>
                                </label>
                                <div class="border-start border-3 border-primary ps-3">
                                    <?php
                                    $response = $responses[$index] ?? '';
                                    
                                    if ($field['type'] == 'checkbox' && is_array($response)) {
                                        // Checkbox responses (array)
                                        if (!empty($response)) {
                                            echo '<ul class="mb-0">';
                                            foreach ($response as $value) {
                                                echo '<li>' . htmlspecialchars($value) . '</li>';
                                            }
                                            echo '</ul>';
                                        } else {
                                            echo '<span class="text-muted">None selected</span>';
                                        }
                                    } elseif ($field['type'] == 'textarea') {
                                        // Textarea - preserve line breaks
                                        echo '<p class="mb-0">' . nl2br(htmlspecialchars($response)) . '</p>';
                                    } elseif ($field['type'] == 'file') {
                                        // File upload (show link when implemented)
                                        if (!empty($response)) {
                                            echo '<i class="bi bi-file-earmark"></i> ' . htmlspecialchars($response);
                                        } else {
                                            echo '<span class="text-muted">No file uploaded</span>';
                                        }
                                    } else {
                                        // All other fields
                                        if (!empty($response)) {
                                            echo htmlspecialchars($response);
                                        } else {
                                            echo '<span class="text-muted">No response</span>';
                                        }
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-muted">No form fields defined.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Admin Notes -->
            <?php if (!empty($submission['notes']) || $submission['status'] == 'reviewed'): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Admin Notes</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($submission['notes'])): ?>
                            <p><?= nl2br(htmlspecialchars($submission['notes'])) ?></p>
                        <?php else: ?>
                            <p class="text-muted">No notes added.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-md-4">
            <!-- Submission Details -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Details</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="text-muted small">Status</label>
                        <?php
                        $status_badges = [
                            'draft' => 'bg-secondary',
                            'submitted' => 'bg-warning text-dark',
                            'reviewed' => 'bg-success'
                        ];
                        $status_badge = $status_badges[$submission['status']] ?? 'bg-secondary';
                        ?>
                        <div><span class="badge <?= $status_badge ?>"><?= ucfirst($submission['status']) ?></span></div>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted small">Form Type</label>
                        <div><?= ucwords(str_replace('_', ' ', $submission['form_type'])) ?></div>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted small">Client</label>
                        <div>
                            <a href="clients_edit.php?id=<?= $submission['client_id'] ?>">
                                <?= htmlspecialchars($submission['client_name']) ?>
                            </a>
                        </div>
                    </div>

                    <?php if ($submission['booking_id']): ?>
                        <div class="mb-3">
                            <label class="text-muted small">Appointment</label>
                            <div>
                                <?= htmlspecialchars($submission['service_type']) ?><br>
                                <small><?= htmlspecialchars($submission['appointment_datetime']) ?></small>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="text-muted small">Submitted</label>
                        <div>
                            <?= date('M j, Y g:i A', strtotime($submission['submitted_at'])) ?>
                            <?php if ($submission['submitted_by_name']): ?>
                                <br><small>by <?= htmlspecialchars($submission['submitted_by_name']) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($submission['reviewed_by_name']): ?>
                        <div class="mb-3">
                            <label class="text-muted small">Reviewed</label>
                            <div>
                                <?= date('M j, Y g:i A', strtotime($submission['reviewed_at'])) ?>
                                <br><small>by <?= htmlspecialchars($submission['reviewed_by_name']) ?></small>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Actions -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Actions</h5>
                </div>
                <div class="card-body">
                    <?php if ($submission['status'] != 'reviewed'): ?>
                        <button type="button" class="btn btn-success w-100 mb-2" data-bs-toggle="modal" data-bs-target="#reviewModal">
                            <i class="bi bi-check-circle"></i> Mark as Reviewed
                        </button>
                    <?php else: ?>
                        <form method="POST" onsubmit="return confirm('Remove review status?');">
                            <input type="hidden" name="action" value="unreview">
                            <button type="submit" class="btn btn-warning w-100 mb-2">
                                <i class="bi bi-x-circle"></i> Remove Review
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <a href="clients_edit.php?id=<?= $submission['client_id'] ?>" class="btn btn-outline-primary w-100">
                        <i class="bi bi-person"></i> View Client
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Mark as Reviewed</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="review">
                    <div class="mb-3">
                        <label for="notes" class="form-label">Admin Notes (Optional)</label>
                        <textarea class="form-control" id="notes" name="notes" rows="4" 
                                  placeholder="Add any notes about this submission..."></textarea>
                        <small class="text-muted">These notes are internal only and not visible to the client.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Mark as Reviewed
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
