<?php
/**
 * Public Form Submission Page
 * Allows clients to complete form templates outside the admin panel.
 *
 * Supports two access patterns:
 *  - /backend/public/form.php?template_id=123  (start a new submission)
 *  - /backend/public/form.php?id=456           (complete an existing pending submission)
 */
require_once '../includes/config.php';
require_once '../includes/database.php';
require_once '../includes/workflow_helper.php';
require_once __DIR__ . '/includes/public_error_page.php';

$db = new Database();
$conn = $db->getConnection();

$submission_id = safe_int($_GET['id'] ?? 0);
$template_id = safe_int($_GET['template_id'] ?? ($_GET['template'] ?? 0));
$submission_row = null;

// If a submission ID is provided, load it (includes template + client info for prefill)
if ($submission_id > 0) {
    $stmt = $conn->prepare("
        SELECT fs.*, ft.name AS template_name, ft.description AS template_description,
               ft.fields AS template_fields, ft.is_active AS template_active, ft.is_internal AS template_internal,
               c.name AS client_name, c.email AS client_email, c.phone AS client_phone
        FROM form_submissions fs
        JOIN form_templates ft ON fs.template_id = ft.id
        LEFT JOIN clients c ON fs.client_id = c.id
        WHERE fs.id = ? AND fs.status = 'pending'
    ");
    $stmt->execute([$submission_id]);
    $submission_row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$submission_row) {
        renderPublicErrorPage(
            'Form Not Found',
            'Form Not Found',
            'The form you are looking for does not exist or is no longer available.',
            404
        );
    }
    $template_id = array_int_value($submission_row, 'template_id');
}

// Load template
$stmt_tpl = $conn->prepare("SELECT * FROM form_templates WHERE id = ?");
$stmt_tpl->execute([$template_id]);
$template = $stmt_tpl->fetch(PDO::FETCH_ASSOC);

// Block unavailable or internal templates from this public endpoint
if (
    !$template
    || array_int_value($template, 'is_active') === 0
    || array_int_value($template, 'is_internal') !== 0
) {
    renderPublicErrorPage(
        'Form Unavailable',
        'Form Unavailable',
        'This form is not available right now. Please contact us if you think this is an error.',
        404
    );
}

$template_name = array_string_value($template, 'name');
$template_description = array_string_value($template, 'description');
$fields = decode_json_assoc_list(array_string_value($template, 'fields'));

// Prefill from submission/client when available
$prefill_responses = [];
$prefill_name = '';
$prefill_email = '';
$prefill_phone = '';
$client_id = 0;

if (is_array($submission_row)) {
    $prefill_responses = decode_json_assoc(array_string_value($submission_row, 'responses'));
    $prefill_name = array_string_value($submission_row, 'client_name');
    $prefill_email = array_string_value($submission_row, 'client_email');
    $prefill_phone = array_string_value($submission_row, 'client_phone');
    $client_id = array_int_value($submission_row, 'client_id');
}

$errors = [];
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), scalar_string($_POST['csrf_token']))) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    } else {
        $submission_id = safe_int($_POST['submission_id'] ?? 0);
        $template_id = safe_int($_POST['template_id'] ?? 0);

        $contact_name = trim(scalar_string($_POST['contact_name'] ?? ''));
        $contact_email = trim(scalar_string($_POST['contact_email'] ?? ''));
        $contact_phone = trim(scalar_string($_POST['contact_phone'] ?? ''));

        if ($contact_name === '' || $contact_email === '') {
            $errors[] = 'Name and email are required.';
        } elseif (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        // Re-load template to ensure current fields
        $stmt_tpl = $conn->prepare("SELECT * FROM form_templates WHERE id = ?");
        $stmt_tpl->execute([$template_id]);
        $template = $stmt_tpl->fetch(PDO::FETCH_ASSOC);
        if (!$template || array_int_value($template, 'is_active') === 0) {
            $errors[] = 'This form is no longer available.';
        } else {
            $fields = decode_json_assoc_list(array_string_value($template, 'fields'));
        }

        // Collect form responses
        $responses = [];
        foreach ($fields as $index => $field) {
            $field_type = array_string_value($field, 'type', 'text');
            $field_label = array_string_value($field, 'label', 'Field');
            $is_required = array_int_value($field, 'required') === 1;
            $raw_value = $_POST['field'][$index] ?? null;

            if ($field_type === 'checkbox') {
                $value = is_array($raw_value) ? array_map('scalar_string', $raw_value) : [];
                if ($is_required && empty($value)) {
                    $errors[] = "Please select at least one option for {$field_label}.";
                }
                $responses[(string)$index] = $value;
            } else {
                $value = is_array($raw_value) ? '' : trim(scalar_string($raw_value));
                if ($is_required && $value === '') {
                    $errors[] = "Please complete {$field_label}.";
                }
                $responses[(string)$index] = $value;
            }
        }

        // Preserve entered answers on validation errors
        $prefill_responses = $responses;

        // Persist when no validation errors
        if (empty($errors)) {
            // Resolve or create client
            $client_id = 0;
            if ($submission_id > 0 && is_array($submission_row)) {
                $client_id = array_int_value($submission_row, 'client_id');
            }

            if ($client_id === 0) {
                // Find by email or create new client record
                $stmt = $conn->prepare("SELECT id FROM clients WHERE email = ?");
                $stmt->execute([$contact_email]);
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($existing) {
                    $client_id = array_int_value($existing, 'id');
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO clients (name, email, phone, created_at, updated_at)
                        VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ");
                    $stmt->execute([$contact_name, $contact_email, $contact_phone]);
                    $client_id = (int) $conn->lastInsertId();
                }
            } else {
                // Update stored contact info for the known client
                $stmt_update = $conn->prepare("
                    UPDATE clients
                    SET name = ?, email = ?, phone = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt_update->execute([$contact_name, $contact_email, $contact_phone, $client_id]);
            }

            // Save submission
            $json_responses = json_encode($responses);
            if ($submission_id > 0 && is_array($submission_row)) {
                $stmt = $conn->prepare("
                    UPDATE form_submissions
                    SET responses = ?, status = 'submitted', submitted_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$json_responses, $submission_id]);
                $new_submission_id = $submission_id;
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO form_submissions (client_id, template_id, booking_id, responses, status, submitted_at)
                    VALUES (?, ?, NULL, ?, 'submitted', CURRENT_TIMESTAMP)
                ");
                $stmt->execute([$client_id, $template_id, $json_responses]);
                $new_submission_id = (int) $conn->lastInsertId();
            }

            // Trigger any workflows attached to this form
            $workflow_helper = new WorkflowHelper($conn);
            try {
                $workflow_helper->checkFormTriggers($new_submission_id);
            } catch (Throwable $e) {
                error_log('Form submission workflow check failed for #' . $new_submission_id . ': ' . $e->getMessage());
            }

            $success_message = 'Thank you! Your form has been submitted successfully.';
        }
    }
}

$page_title = 'Complete Form';
require_once __DIR__ . '/includes/public_head.php';
?>

<style>
    body { background: #f8f9fa; }
    .form-card { max-width: 820px; margin: 2rem auto; }
</style>

<div class="container form-card">
    <div class="text-center mb-4">
        <h1 class="h3 mb-2"><i class="fas fa-clipboard-check me-2"></i><?= htmlspecialchars($template_name) ?></h1>
        <?php if ($template_description !== ''): ?>
            <p class="text-muted mb-0"><?= nl2br(htmlspecialchars($template_description)) ?></p>
        <?php endif; ?>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <strong>Please fix the following:</strong>
            <ul class="mb-0">
                <?php foreach ($errors as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success_message !== ''): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
        </div>
    <?php else: ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST" class="needs-validation" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(scalar_string($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="template_id" value="<?= (int) $template_id ?>">
                <?php if ($submission_id > 0): ?>
                    <input type="hidden" name="submission_id" value="<?= (int) $submission_id ?>">
                <?php endif; ?>

                <h5 class="mb-3">Your Information</h5>
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <label class="form-label">Name *</label>
                        <input type="text" name="contact_name" class="form-control" value="<?= htmlspecialchars($prefill_name) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email *</label>
                        <input type="email" name="contact_email" class="form-control" value="<?= htmlspecialchars($prefill_email) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="contact_phone" class="form-control" value="<?= htmlspecialchars($prefill_phone) ?>">
                    </div>
                </div>

                <?php if (!empty($fields)): ?>
                    <h5 class="mb-3">Form Questions</h5>
                <?php endif; ?>

                <?php foreach ($fields as $index => $field): ?>
                    <?php
                        $field_label = array_string_value($field, 'label', 'Field');
                        $field_type = array_string_value($field, 'type', 'text');
                        $field_placeholder = array_string_value($field, 'placeholder');
                        $field_description = array_string_value($field, 'description');
                        $field_options = isset($field['options']) && is_array($field['options']) ? array_map('scalar_string', $field['options']) : [];
                        $is_required = array_int_value($field, 'required') === 1;
                        $field_name = "field[{$index}]";
                        $existing_value = $prefill_responses[(string)$index] ?? '';
                    ?>
                    <div class="mb-3">
                        <label class="form-label">
                            <?= htmlspecialchars($field_label) ?>
                            <?php if ($is_required): ?><span class="text-danger">*</span><?php endif; ?>
                        </label>
                        <?php if ($field_description !== ''): ?>
                            <div class="text-muted small mb-1"><?= htmlspecialchars($field_description) ?></div>
                        <?php endif; ?>

                        <?php if ($field_type === 'textarea'): ?>
                            <textarea name="<?= $field_name ?>" class="form-control" rows="3" placeholder="<?= htmlspecialchars($field_placeholder) ?>" <?= $is_required ? 'required' : '' ?>><?= htmlspecialchars(scalar_string($existing_value)) ?></textarea>
                        <?php elseif ($field_type === 'select'): ?>
                            <select name="<?= $field_name ?>" class="form-select" <?= $is_required ? 'required' : '' ?>>
                                <option value="">-- Select --</option>
                                <?php foreach ($field_options as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt) ?>" <?= scalar_string($existing_value) === $opt ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($opt) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($field_type === 'checkbox'): ?>
                            <?php $selected = is_array($existing_value) ? $existing_value : []; ?>
                            <?php foreach ($field_options as $opt_idx => $opt): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox"
                                           name="field[<?= $index ?>][]"
                                           id="field_<?= $index ?>_<?= $opt_idx ?>"
                                           value="<?= htmlspecialchars($opt) ?>"
                                           <?= in_array($opt, $selected, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="field_<?= $index ?>_<?= $opt_idx ?>">
                                        <?= htmlspecialchars($opt) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif ($field_type === 'radio'): ?>
                            <?php foreach ($field_options as $opt_idx => $opt): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio"
                                           name="<?= $field_name ?>"
                                           id="field_<?= $index ?>_<?= $opt_idx ?>"
                                           value="<?= htmlspecialchars($opt) ?>"
                                           <?= scalar_string($existing_value) === $opt ? 'checked' : '' ?>
                                           <?= $is_required ? 'required' : '' ?>>
                                    <label class="form-check-label" for="field_<?= $index ?>_<?= $opt_idx ?>">
                                        <?= htmlspecialchars($opt) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php
                                $input_type = match ($field_type) {
                                    'email' => 'email',
                                    'phone' => 'tel',
                                    'date'  => 'date',
                                    default => 'text',
                                };
                            ?>
                            <input type="<?= $input_type ?>" name="<?= $field_name ?>" class="form-control"
                                   placeholder="<?= htmlspecialchars($field_placeholder) ?>"
                                   value="<?= htmlspecialchars(scalar_string($existing_value)) ?>"
                                   <?= $is_required ? 'required' : '' ?>>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-1"></i> Submit Form
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
