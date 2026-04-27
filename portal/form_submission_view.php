<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/form_types.php';
require_once '../backend/includes/follow_up_notes.php';
requirePortalLogin();

$client_id = portalClientId();
$db = new Database();
$conn = $db->getConnection();

$submission_id = safe_int($_GET['id'] ?? 0);
if ($submission_id <= 0) {
    setFlashMessage('Invalid form submission.', 'error');
    redirect(PORTAL_URL . 'agreements.php');
}

$stmt = $conn->prepare("
    SELECT fs.*, ft.name AS form_name, ft.form_type, ft.fields,
           COALESCE(ft.is_internal, 0) AS template_is_internal,
           ft.show_in_client_portal AS template_show_in_client_portal,
           b.service_type, b.appointment_date, b.appointment_time
    FROM form_submissions fs
    JOIN form_templates ft ON fs.template_id = ft.id
    LEFT JOIN bookings b ON fs.booking_id = b.id
    WHERE fs.id = ? AND fs.client_id = ?
    LIMIT 1
");
$stmt->execute([$submission_id, $client_id]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if (
    !is_array($submission)
    || !bdta_form_submission_requires_client_review(array_string_value($submission, 'form_type'))
    || !bdta_form_submission_is_client_portal_visible($submission)
) {
    setFlashMessage('That follow-up note is not available in the portal.', 'error');
    redirect(PORTAL_URL . 'agreements.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), scalar_string($_POST['csrf_token']))) {
        setFlashMessage('Invalid request.', 'error');
    } elseif (scalar_string($submission['status']) !== 'reviewed') {
        $update_stmt = $conn->prepare("
            UPDATE form_submissions
            SET status = 'reviewed',
                reviewed_by = NULL,
                reviewed_at = CURRENT_TIMESTAMP
            WHERE id = ? AND client_id = ? AND status != 'reviewed'
        ");
        $update_stmt->execute([$submission_id, $client_id]);
        logClientActivity($client_id, 'follow_up_note_reviewed', 'Reviewed follow-up note: ' . array_string_value($submission, 'form_name'), $conn);
        setFlashMessage('Follow-up note marked as reviewed.', 'success');
        redirect(PORTAL_URL . 'form_submission_view.php?id=' . $submission_id);
    }
}

$fields = decode_json_assoc_list(array_string_value($submission, 'fields'));
$responses = decode_json_assoc(array_string_value($submission, 'responses'));
$page_title = 'Follow-up Note';
include '../portal/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1"><?= escape(array_string_value($submission, 'form_name')) ?></h2>
        <p class="text-muted mb-0">Review your follow-up note from your appointment.</p>
    </div>
    <a href="agreements.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i> Back
    </a>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header"><strong>Follow-up Details</strong></div>
            <div class="card-body">
                <?php if ($fields === []): ?>
                    <p class="text-muted mb-0">No follow-up details are available.</p>
                <?php else: ?>
                    <?php foreach ($fields as $index => $field): ?>
                        <?php
                        $field_label = array_string_value($field, 'label', 'Field');
                        $field_type = array_string_value($field, 'type', 'text');
                        $response = $responses[(string) $index] ?? null;
                        $response_text = is_array($response) ? '' : scalar_string($response);
                        ?>
                        <div class="mb-4">
                            <?php if (bdta_form_field_is_display_only($field)): ?>
                                <div class="p-3 rounded border bg-light">
                                    <div class="fw-semibold mb-1"><?= escape($field_label) ?></div>
                                    <?php $text_block_body = bdta_form_field_text_block_body($field); ?>
                                    <?php if ($text_block_body !== ''): ?>
                                        <div class="text-muted small"><?= nl2br(escape($text_block_body)) ?></div>
                                    <?php endif; ?>
                                </div>
                                </div>
                                <?php continue; ?>
                            <?php endif; ?>
                            <label class="fw-bold text-muted d-block mb-2"><?= escape($field_label) ?></label>
                            <div class="border-start border-3 border-primary ps-3">
                                <?php if ($field_type === 'checkbox' && is_array($response)): ?>
                                    <?php if ($response !== []): ?>
                                        <ul class="mb-0">
                                            <?php foreach ($response as $value): ?>
                                                <li><?= escape($value) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        <span class="text-muted">None selected</span>
                                    <?php endif; ?>
                                <?php elseif ($field_type === 'textarea'): ?>
                                    <p class="mb-0"><?= nl2br(escape($response_text)) ?></p>
                                <?php elseif ($response_text !== ''): ?>
                                    <?= escape($response_text) ?>
                                <?php else: ?>
                                    <span class="text-muted">No response</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><strong>Status</strong></div>
            <div class="card-body">
                <?php $submission_status = array_string_value($submission, 'status'); ?>
                <div class="mb-3">
                    <label class="text-muted small">Status</label>
                    <div>
                        <span class="badge <?= $submission_status === 'reviewed' ? 'bg-success' : 'bg-warning text-dark' ?>">
                            <?= escape(ucfirst($submission_status)) ?>
                        </span>
                    </div>
                </div>

                <?php if (array_string_value($submission, 'service_type') !== ''): ?>
                    <div class="mb-3">
                        <label class="text-muted small">Appointment</label>
                        <div>
                            <?= escape(array_string_value($submission, 'service_type')) ?><br>
                            <small><?= escape(trim(array_string_value($submission, 'appointment_date') . ' ' . array_string_value($submission, 'appointment_time'))) ?></small>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="text-muted small">Submitted</label>
                    <div><?= escape(formatDateTime(array_string_value($submission, 'submitted_at'))) ?></div>
                </div>

                <?php if (array_string_value($submission, 'reviewed_at') !== ''): ?>
                    <div class="mb-3">
                        <label class="text-muted small">Reviewed</label>
                        <div><?= escape(formatDateTime(array_string_value($submission, 'reviewed_at'))) ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($submission_status !== 'reviewed'): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(scalar_string($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-success w-100">
                            <i class="fas fa-check-circle me-1"></i> Mark as Reviewed
                        </button>
                    </form>
                <?php else: ?>
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-check-circle me-1"></i> You have reviewed this follow-up note.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../portal/includes/footer.php'; ?>
