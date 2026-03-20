<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/form_types.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$submission_id = safe_int($_GET['id'] ?? 0);

if ($submission_id == 0) {
    $_SESSION['flash_message'] = 'Invalid submission ID';
    $_SESSION['flash_type'] = 'danger';
    header('Location: form_submissions_list.php');
    exit;
}

// Handle review action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), scalar_string($_POST['csrf_token']))) {
        $_SESSION['flash_message'] = 'Invalid request.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: form_submissions_view.php?id=' . $submission_id);
        exit;
    }
    if (scalar_string($_POST['action']) == 'review') {
        $notes = trim(scalar_string($_POST['notes'] ?? ''));
        
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
    } elseif (scalar_string($_POST['action']) == 'unreview') {
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
          c.name as client_name,
          c.email as client_email,
          c.phone as client_phone,
          ft.name as form_name,
          ft.form_type,
          ft.fields,
          p.name as pet_name,
          CONCAT(b.appointment_date, ' ', b.appointment_time) as appointment_datetime,
          b.service_type,
          au.username as submitted_by_name,
          au2.username as reviewed_by_name
          FROM form_submissions fs
          LEFT JOIN clients c ON fs.client_id = c.id
          LEFT JOIN form_templates ft ON fs.template_id = ft.id
          LEFT JOIN pets p ON fs.pet_id = p.id
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

/** @var array<string, mixed> $submission */
$submission = $submission;

// Decode JSON fields
$fields = decode_json_assoc_list(array_string_value($submission, 'fields'));
$responses = decode_json_assoc(array_string_value($submission, 'responses'));

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-file-circle-check"></i> View Form Submission</h2>
        <div class="d-flex gap-2">
            <?php if (bdta_normalize_form_type(array_string_value($submission, 'form_type')) === 'survey_form'): ?>
                <a href="form_survey_results.php?template_id=<?= array_int_value($submission, 'template_id') ?>" class="btn btn-outline-info">
                    <i class="fas fa-chart-simple"></i> Survey Results
                </a>
            <?php endif; ?>
            <a href="form_submissions_list.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
    </div>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?= escape($_SESSION['flash_type']) ?> alert-dismissible fade show">
            <?= escape($_SESSION['flash_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-8">
            <!-- Form Responses -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><?= escape(array_string_value($submission, 'form_name')) ?></h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($fields)): ?>
                        <?php foreach ($fields as $index => $field): ?>
                            <?php
                            $field_label = array_string_value($field, 'label');
                            $field_required = array_int_value($field, 'required');
                            $field_mapping = array_string_value($field, 'profile_mapping');
                            $field_type = array_string_value($field, 'type');
                            $response = $responses[(string) $index] ?? null;
                            $response_text = is_array($response) ? '' : scalar_string($response);
                            ?>
                            <div class="mb-4">
                                <label class="fw-bold text-muted d-block mb-2">
                                    <?= escape($field_label) ?>
                                    <?php if ($field_required !== 0): ?>
                                        <span class="text-danger">*</span>
                                    <?php endif; ?>
                                    <?php if ($field_mapping !== ''): ?>
                                        <?php
                                        $mapping_labels = [
                                            'client.name'    => ['Client: Name',      'bg-info'],
                                            'client.email'   => ['Client: Email',     'bg-info'],
                                            'client.phone'   => ['Client: Phone',     'bg-info'],
                                            'client.address' => ['Client: Address',   'bg-info'],
                                        ];
                                        for ($p = 1; $p <= 3; $p++) {
                                            $mapping_labels["pet_{$p}.name"]             = ["Pet {$p}: Name",             'bg-success'];
                                            $mapping_labels["pet_{$p}.species"]          = ["Pet {$p}: Species",          'bg-success'];
                                            $mapping_labels["pet_{$p}.breed"]            = ["Pet {$p}: Breed",            'bg-success'];
                                            $mapping_labels["pet_{$p}.date_of_birth"]    = ["Pet {$p}: Date of Birth",    'bg-success'];
                                            $mapping_labels["pet_{$p}.source"]           = ["Pet {$p}: Source",           'bg-success'];
                                            $mapping_labels["pet_{$p}.spayed_neutered"]  = ["Pet {$p}: Spayed/Neutered",  'bg-success'];
                                            $mapping_labels["pet_{$p}.vaccines_current"] = ["Pet {$p}: Vaccines Current", 'bg-success'];
                                            $mapping_labels["pet_{$p}.vaccine_notes"]    = ["Pet {$p}: Vaccine Notes",    'bg-success'];
                                            $mapping_labels["pet_{$p}.behavior_notes"]   = ["Pet {$p}: Behavior Notes",   'bg-success'];
                                            $mapping_labels["pet_{$p}.medical_notes"]    = ["Pet {$p}: Medical Notes",    'bg-success'];
                                            $mapping_labels["pet_{$p}.training_notes"]   = ["Pet {$p}: Training Notes",   'bg-success'];
                                        }
                                        $ml = $mapping_labels[$field_mapping] ?? [$field_mapping, 'bg-secondary'];
                                        ?>
                                        <span class="badge <?= $ml[1] ?> ms-1" title="Maps to profile field">
                                            <i class="fas fa-link me-1"></i><?= escape($ml[0]) ?>
                                        </span>
                                    <?php endif; ?>
                                </label>
                                <div class="border-start border-3 border-primary ps-3">
                                    <?php
                                    if ($field_type == 'checkbox' && is_array($response)) {
                                        // Checkbox responses (array)
                                        if (!empty($response)) {
                                            echo '<ul class="mb-0">';
                                            foreach ($response as $value) {
                                                echo '<li>' . escape($value) . '</li>';
                                            }
                                            echo '</ul>';
                                        } else {
                                            echo '<span class="text-muted">None selected</span>';
                                        }
                                    } elseif ($field_type == 'textarea') {
                                        // Textarea - preserve line breaks
                                        echo '<p class="mb-0">' . nl2br(escape($response_text)) . '</p>';
                                    } elseif ($field_type == 'file') {
                                        // File upload (show link when implemented)
                                        if ($response_text !== '') {
                                            echo '<i class="fas fa-file"></i> ' . escape($response_text);
                                        } else {
                                            echo '<span class="text-muted">No file uploaded</span>';
                                        }
                                    } else {
                                        // All other fields
                                        if ($response_text !== '') {
                                            echo escape($response_text);
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
            <?php if (array_string_value($submission, 'notes') !== '' || array_string_value($submission, 'status') == 'reviewed'): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Admin Notes</h5>
                    </div>
                    <div class="card-body">
                        <?php if (array_string_value($submission, 'notes') !== ''): ?>
                            <p><?= nl2br(escape(array_string_value($submission, 'notes'))) ?></p>
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
                        $submission_status = array_string_value($submission, 'status');
                        $status_badge = $status_badges[$submission_status] ?? 'bg-secondary';
                        ?>
                        <div><span class="badge <?= $status_badge ?>"><?= ucfirst($submission_status) ?></span></div>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted small">Form Type</label>
                        <div><?= escape(bdta_get_form_type_label(array_string_value($submission, 'form_type'))) ?></div>
                    </div>

                    <div class="mb-3">
                        <label class="text-muted small">Client</label>
                        <div>
                            <a href="clients_view.php?id=<?= array_int_value($submission, 'client_id') ?>">
                                <?= escape(array_string_value($submission, 'client_name')) ?>
                            </a>
                        </div>
                    </div>

                    <?php if (array_int_value($submission, 'booking_id')): ?>
                        <div class="mb-3">
                            <label class="text-muted small">Appointment</label>
                            <div>
                                <?= escape(array_string_value($submission, 'service_type')) ?><br>
                                <small><?= escape(array_string_value($submission, 'appointment_datetime')) ?></small>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (array_string_value($submission, 'pet_name') !== ''): ?>
                        <div class="mb-3">
                            <label class="text-muted small">Pet</label>
                            <div><?= escape(array_string_value($submission, 'pet_name')) ?></div>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="text-muted small">Submitted</label>
                        <div>
                            <?= escape(formatDateTime(array_string_value($submission, 'submitted_at'))) ?>
                            <?php if (array_string_value($submission, 'submitted_by_name') !== ''): ?>
                                <br><small>by <?= escape(array_string_value($submission, 'submitted_by_name')) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (array_string_value($submission, 'reviewed_by_name') !== ''): ?>
                        <div class="mb-3">
                            <label class="text-muted small">Reviewed</label>
                        <div>
                            <?= escape(formatDateTime(array_string_value($submission, 'reviewed_at'))) ?>
                            <br><small>by <?= escape(array_string_value($submission, 'reviewed_by_name')) ?></small>
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
                    <?php if (array_string_value($submission, 'status') != 'reviewed'): ?>
                        <button type="button" class="btn btn-success w-100 mb-2" data-bs-toggle="modal" data-bs-target="#reviewModal">
                            <i class="fas fa-check-circle"></i> Mark as Reviewed
                        </button>
                    <?php else: ?>
                        <form method="POST" onsubmit="return confirm('Remove review status?');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(scalar_string($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="unreview">
                            <button type="submit" class="btn btn-warning w-100 mb-2">
                                <i class="fas fa-circle-xmark"></i> Remove Review
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <a href="clients_view.php?id=<?= array_int_value($submission, 'client_id') ?>" class="btn btn-outline-primary w-100">
                        <i class="fas fa-user"></i> View Client
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
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(scalar_string($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
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
                        <i class="fas fa-check-circle"></i> Mark as Reviewed
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
