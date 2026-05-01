<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/form_types.php';
require_once '../backend/includes/follow_up_notes.php';
requirePortalLogin();

$client_id = portalClientId();
$submission_id = safe_int($_GET['id'] ?? 0);

if ($submission_id <= 0) {
    setFlashMessage('Invalid form submission.', 'error');
    redirect(PORTAL_URL . 'agreements.php');
}

$db   = new Database();
$conn = $db->getConnection();

$stmt = $conn->prepare("
    SELECT fs.*,
           ft.name AS form_name,
           ft.form_type,
           ft.fields,
           COALESCE(ft.is_internal, 0) AS template_is_internal,
           ft.show_in_client_portal AS template_show_in_client_portal,
           p.name AS pet_name,
           b.appointment_date,
           b.appointment_time
    FROM form_submissions fs
    LEFT JOIN form_templates ft ON fs.template_id = ft.id
    LEFT JOIN pets p ON fs.pet_id = p.id
    LEFT JOIN bookings b ON fs.booking_id = b.id
    WHERE fs.id = ?
      AND fs.client_id = ?
      AND fs.status IN ('submitted', 'reviewed')
    LIMIT 1
");
$stmt->execute([$submission_id, $client_id]);
$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if (
    !$submission
    || bdta_form_submission_requires_client_review(array_string_value($submission, 'form_type'))
    || !bdta_form_submission_is_client_portal_visible($submission)
) {
    setFlashMessage('Form submission not found.', 'error');
    redirect(PORTAL_URL . 'agreements.php');
}

$fields = decode_json_assoc_list(array_string_value($submission, 'fields'));
$responses = decode_json_assoc(array_string_value($submission, 'responses'));
$decode_warning = '';
$raw_fields = array_string_value($submission, 'fields');
$raw_responses = array_string_value($submission, 'responses');
if (
    ($raw_fields !== '' && !is_array(json_decode($raw_fields, true)))
    || ($raw_responses !== '' && !is_array(json_decode($raw_responses, true)))
) {
    $decode_warning = 'Some saved form data could not be parsed and may not display completely.';
}

$page_title = 'View Form';
include 'includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="fas fa-file-circle-check me-2"></i><?php echo escape(array_string_value($submission, 'form_name', 'Form Submission')); ?></h2>
    <a href="<?php echo PORTAL_URL; ?>agreements.php" class="btn btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i>Back
    </a>
</div>

<?php if ($decode_warning !== ''): ?>
<div class="alert alert-warning"><?php echo escape($decode_warning); ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="text-muted small">Submitted</div>
                <div><?php echo escape(array_string_value($submission, 'submitted_at')); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Status</div>
                <div><?php echo escape(ucfirst(array_string_value($submission, 'status', 'submitted'))); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Form Type</div>
                <div><?php echo escape(bdta_get_form_type_label(array_string_value($submission, 'form_type', 'client_form'))); ?></div>
            </div>
            <?php if (array_string_value($submission, 'pet_name') !== ''): ?>
            <div class="col-md-4">
                <div class="text-muted small">Pet</div>
                <div><?php echo escape(array_string_value($submission, 'pet_name')); ?></div>
            </div>
            <?php endif; ?>
            <?php if (array_string_value($submission, 'appointment_date') !== ''): ?>
            <div class="col-md-4">
                <div class="text-muted small">Appointment</div>
                <div>
                    <?php
                    $appointment = array_string_value($submission, 'appointment_date');
                    $appt_time = array_string_value($submission, 'appointment_time');
                    if ($appt_time !== '') {
                        $appointment .= ' ' . $appt_time;
                    }
                    echo escape($appointment);
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Responses</strong></div>
    <div class="card-body">
        <?php if (empty($fields)): ?>
            <p class="text-muted mb-0">No form fields are available for this submission.</p>
        <?php else: ?>
            <?php foreach ($fields as $index => $field): ?>
                <?php
                $label = array_string_value($field, 'label', 'Field');
                $type = array_string_value($field, 'type', 'text');
                $response = $responses[(string) $index] ?? null;
                $response_text = is_array($response) ? '' : scalar_string($response);
                ?>
                <div class="mb-3">
                    <?php if (bdta_form_field_is_display_only($field)): ?>
                        <div class="p-3 rounded border bg-light">
                            <div class="fw-semibold mb-1"><?php echo escape($label); ?></div>
                            <?php $text_block_body = bdta_form_field_text_block_body($field); ?>
                            <?php if ($text_block_body !== ''): ?>
                                <div class="text-muted small"><?php echo nl2br(escape($text_block_body)); ?></div>
                            <?php endif; ?>
                        </div>
                        </div>
                        <?php continue; ?>
                    <?php endif; ?>
                    <label class="fw-bold text-muted d-block mb-1"><?php echo escape($label); ?></label>
                    <div class="border-start border-3 border-primary ps-3">
                        <?php if ($type === 'checkbox' && is_array($response)): ?>
                            <?php if ($response !== []): ?>
                                <ul class="mb-0">
                                    <?php foreach ($response as $value): ?>
                                        <li><?php echo escape(scalar_string($value)); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <span class="text-muted">None selected</span>
                            <?php endif; ?>
                        <?php elseif (bdta_form_field_is_pet_info_group($field) && is_array($response)): ?>
                            <?php $pets = bdta_form_field_pet_info_group_normalize_response($field, $response); ?>
                            <?php if ($pets !== []): ?>
                                <?php foreach ($pets as $pet_index => $pet): ?>
                                    <div class="card mb-2">
                                        <div class="card-header py-2 fw-semibold">Pet <?= $pet_index + 1 ?></div>
                                        <div class="card-body py-2 small">
                                            <div><strong>Name:</strong> <?php echo escape($pet['name']); ?></div>
                                            <div><strong>Age or DOB:</strong> <?php echo escape($pet['age_or_dob']); ?></div>
                                            <div><strong>Breed:</strong> <?php echo escape($pet['breed']); ?></div>
                                            <div><strong>Vaccine Status:</strong> <?php echo escape($pet['vaccines_current']); ?></div>
                                            <div><strong>Spay/Neuter Status:</strong> <?php echo escape($pet['spayed_neutered']); ?></div>
                                            <div><strong>Acquired From:</strong> <?php echo escape($pet['source']); ?></div>
                                            <div><strong>Ownership Length:</strong> <?php echo escape($pet['ownership_length']); ?></div>
                                            <?php if ($pet['species'] !== ''): ?>
                                                <div><strong>Species:</strong> <?php echo escape($pet['species']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted">No pets provided</span>
                            <?php endif; ?>
                        <?php elseif ($type === 'textarea'): ?>
                            <p class="mb-0"><?php echo nl2br(escape($response_text)); ?></p>
                        <?php else: ?>
                            <?php if ($response_text !== ''): ?>
                                <?php echo escape($response_text); ?>
                            <?php else: ?>
                                <span class="text-muted">No response</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
