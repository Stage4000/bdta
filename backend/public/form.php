<?php
/**
 * Public Form Submission Page
 * Allows clients to complete public form templates outside the admin panel,
 * and allows staff to open internal form templates directly while logged in.
 *
 * Supports two access patterns:
 *  - /backend/public/form.php?template_id=123  (start a new submission)
 *  - /backend/public/form.php?id=456           (complete an existing pending submission)
 */
require_once '../includes/config.php';
require_once '../includes/database.php';
require_once '../includes/form_types.php';
require_once '../includes/public_form_context.php';
require_once '../includes/public_portal_return.php';
require_once '../includes/turnstile.php';
require_once '../includes/workflow_helper.php';
require_once '../includes/follow_up_notes.php';
require_once '../includes/mailjet_newsletter.php';
require_once __DIR__ . '/includes/public_error_page.php';

$db = new Database();
$conn = $db->getConnection();
$portal_login_url = bdta_public_portal_login_url(
    bdta_public_current_path('/backend/public/form.php')
);

$submission_id = safe_int($_GET['id'] ?? 0);
$template_id = safe_int($_GET['template_id'] ?? ($_GET['template'] ?? 0));
$can_apply_query_context = $submission_id === 0 && isLoggedIn();
$requested_client_id = $can_apply_query_context ? safe_int($_GET['client_id'] ?? 0) : 0;
$requested_booking_id = $can_apply_query_context ? safe_int($_GET['booking_id'] ?? 0) : 0;
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
$template_form_type = array_string_value(is_array($template) ? $template : [], 'form_type', 'client_form');
$requires_staff_login = !bdta_form_type_allows_public_submission($template_form_type)
    || array_int_value(is_array($template) ? $template : [], 'is_internal') !== 0;

// Block unavailable templates and keep non-client-facing forms restricted to logged-in staff.
if (
    !$template
    || array_int_value($template, 'is_active') === 0
    || ($requires_staff_login && !isLoggedIn())
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
$booking_id = 0;
$context = ['errors' => []];

if (is_array($submission_row)) {
    $prefill_responses = decode_json_assoc(array_string_value($submission_row, 'responses'));
    $prefill_name = array_string_value($submission_row, 'client_name');
    $prefill_email = array_string_value($submission_row, 'client_email');
    $prefill_phone = array_string_value($submission_row, 'client_phone');
    $client_id = array_int_value($submission_row, 'client_id');
    $booking_id = array_int_value($submission_row, 'booking_id');
} else {
    $context = bdta_resolve_public_form_context($conn, $requested_client_id, $requested_booking_id);
    $prefill_name = array_string_value($context, 'contact_name');
    $prefill_email = array_string_value($context, 'contact_email');
    $prefill_phone = array_string_value($context, 'contact_phone');
    $client_id = array_int_value($context, 'client_id');
    $booking_id = array_int_value($context, 'booking_id');
}

$portal_prefill_pets = [];
if (isPortalLoggedIn()) {
    $portal_client_id = portalClientId();
    if ($portal_client_id > 0) {
        $stmt = $conn->prepare("
            SELECT name, email, phone
            FROM clients
            WHERE id = ?
        ");
        $stmt->execute([$portal_client_id]);
        $portal_client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($portal_client)) {
            if ($prefill_name === '') {
                $prefill_name = array_string_value($portal_client, 'name');
            }
            if ($prefill_email === '') {
                $prefill_email = array_string_value($portal_client, 'email');
            }
            if ($prefill_phone === '') {
                $prefill_phone = array_string_value($portal_client, 'phone');
            }
        }

        if ($client_id <= 0) {
            $client_id = $portal_client_id;
        }

        $stmt = $conn->prepare("
            SELECT id, name, species, breed, date_of_birth, age_years, age_months,
                   source, ownership_length_years, ownership_length_months,
                   spayed_neutered, vaccines_current
            FROM pets
            WHERE client_id = ? AND is_active = 1
            ORDER BY name
        ");
        $stmt->execute([$portal_client_id]);
        $portal_prefill_pets = assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}

$errors = [];
foreach ($context['errors'] as $context_error) {
    $errors[] = scalar_string($context_error);
}
$success_message = '';

/**
 * @return list<string>
 */
function public_form_pet_profile_columns(PDO $conn): array
{
    $stmt = $conn->query('SELECT * FROM pets LIMIT 0');
    if (!$stmt instanceof PDOStatement) {
        return [];
    }

    $columns = [];
    for ($index = 0, $count = $stmt->columnCount(); $index < $count; $index++) {
        $column_meta = $stmt->getColumnMeta($index);
        $column_name = scalar_string($column_meta['name'] ?? '');
        if ($column_name !== '') {
            $columns[] = $column_name;
        }
    }

    return $columns;
}

/**
 * @param list<array<string, mixed>> $fields
 * @param array<int|string, mixed> $responses
 */
function public_form_sync_pet_info_group_profiles(PDO $conn, int $client_id, array $fields, array $responses): void
{
    if ($client_id <= 0) {
        return;
    }

    $pet_columns = public_form_pet_profile_columns($conn);
    if ($pet_columns === []) {
        return;
    }

    $updatable_columns = array_values(array_intersect([
        'name',
        'species',
        'breed',
        'date_of_birth',
        'age_years',
        'age_months',
        'source',
        'ownership_length_years',
        'ownership_length_months',
        'spayed_neutered',
        'vaccines_current',
    ], $pet_columns));

    if ($updatable_columns === []) {
        return;
    }

    $find_pet_stmt = $conn->prepare('SELECT id FROM pets WHERE client_id = ? AND name = ? ORDER BY id ASC LIMIT 1');

    foreach ($fields as $index => $field) {
        if (!bdta_form_field_is_pet_info_group($field)) {
            continue;
        }

        foreach (bdta_form_field_pet_info_group_profile_values($field, $responses[$index] ?? $responses[(string) $index] ?? null) as $pet_profile) {
            $pet_name = trim(scalar_string($pet_profile['name'] ?? ''));
            if ($pet_name === '') {
                continue;
            }

            $resolved_profile = $pet_profile;

            $find_pet_stmt->execute([$client_id, $pet_name]);
            $existing_pet_id = safe_int($find_pet_stmt->fetchColumn());

            $params = [];
            if ($existing_pet_id > 0) {
                $assignments = [];
                foreach ($updatable_columns as $column) {
                    if (!array_key_exists($column, $resolved_profile)) {
                        continue;
                    }
                    $assignments[] = $column . ' = ?';
                    $params[] = $resolved_profile[$column];
                }
                if ($assignments === []) {
                    continue;
                }
                $params[] = $existing_pet_id;
                $conn->prepare(
                    'UPDATE pets SET ' . implode(', ', $assignments) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?'
                )->execute($params);
                continue;
            }

            $insert_columns = ['client_id'];
            $insert_values = ['?'];
            $params[] = $client_id;
            foreach ($updatable_columns as $column) {
                if (!array_key_exists($column, $resolved_profile)) {
                    continue;
                }
                $insert_columns[] = $column;
                $insert_values[] = '?';
                $params[] = $resolved_profile[$column];
            }
            if (in_array('is_active', $pet_columns, true)) {
                $insert_columns[] = 'is_active';
                $insert_values[] = '?';
                $params[] = 1;
            }
            if (in_array('created_at', $pet_columns, true)) {
                $insert_columns[] = 'created_at';
                $insert_values[] = 'CURRENT_TIMESTAMP';
            }
            if (in_array('updated_at', $pet_columns, true)) {
                $insert_columns[] = 'updated_at';
                $insert_values[] = 'CURRENT_TIMESTAMP';
            }

            $conn->prepare(
                'INSERT INTO pets (' . implode(', ', $insert_columns) . ') VALUES (' . implode(', ', $insert_values) . ')'
            )->execute($params);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), scalar_string($_POST['csrf_token']))) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    } else {
        $turnstile_result = bdta_verify_turnstile_submission($_POST, scalar_string($_SERVER['REMOTE_ADDR'] ?? ''));
        if (!$turnstile_result['success']) {
            $errors[] = scalar_string($turnstile_result['error'] ?? 'Please confirm you are not a robot and try again.');
        }

        $submission_id = safe_int($_POST['submission_id'] ?? 0);
        $template_id = safe_int($_POST['template_id'] ?? 0);
        $allow_posted_context = $submission_id === 0 && isLoggedIn();
        $client_id = $allow_posted_context ? safe_int($_POST['client_id'] ?? 0) : 0;
        $booking_id = $allow_posted_context ? safe_int($_POST['booking_id'] ?? 0) : 0;

        $contact_name = trim(scalar_string($_POST['contact_name'] ?? ''));
        $contact_email = trim(scalar_string($_POST['contact_email'] ?? ''));
        $contact_phone = trim(scalar_string($_POST['contact_phone'] ?? ''));

        // Preserve user-entered contact info on re-render by updating prefill variables
        $prefill_name = $contact_name;
        $prefill_email = $contact_email;
        $prefill_phone = $contact_phone;

        if ($contact_name === '' || $contact_email === '') {
            $errors[] = 'Name and email are required.';
        } elseif (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        // Re-load template to ensure current fields
        $fields = [];
        $stmt_tpl = $conn->prepare("SELECT * FROM form_templates WHERE id = ?");
        $stmt_tpl->execute([$template_id]);
        $template = $stmt_tpl->fetch(PDO::FETCH_ASSOC);
        $template_form_type = array_string_value(is_array($template) ? $template : [], 'form_type', 'client_form');
        $requires_staff_login = !bdta_form_type_allows_public_submission($template_form_type)
            || array_int_value(is_array($template) ? $template : [], 'is_internal') !== 0;
        if (
            !$template
            || array_int_value($template, 'is_active') === 0
            || ($requires_staff_login && !isLoggedIn())
        ) {
            $errors[] = 'This form is no longer available.';
        } else {
            $fields = decode_json_assoc_list(array_string_value($template, 'fields'));
        }

        if ($submission_id === 0) {
            $context = bdta_resolve_public_form_context($conn, $client_id, $booking_id);
            $client_id = array_int_value($context, 'client_id');
            $booking_id = array_int_value($context, 'booking_id');
            foreach ($context['errors'] as $context_error) {
                $errors[] = scalar_string($context_error);
            }
        }

        // Collect form responses
        $responses = [];
        foreach ($fields as $index => $field) {
            if (bdta_form_field_is_display_only($field)) {
                continue;
            }

            $field_type = array_string_value($field, 'type', 'text');
            $field_label = array_string_value($field, 'label', 'Field');
            $is_required = array_int_value($field, 'required') === 1;
            $raw_value = $_POST['field'][$index] ?? null;

            if (bdta_form_field_is_pet_info_group($field)) {
                $value = bdta_form_field_pet_info_group_normalize_response($field, $raw_value);
                $group_errors = bdta_form_field_pet_info_group_validate_response($field, $raw_value);
                foreach ($group_errors as $group_error) {
                    $errors[] = $group_error;
                }
                $responses[(string) $index] = $value;
            } elseif ($field_type === 'checkbox') {
                $value = is_array($raw_value) ? array_map('scalar_string', $raw_value) : [];
                if ($is_required && empty($value)) {
                    $errors[] = "Please select at least one option for {$field_label}.";
                }
                $responses[(string)$index] = $value;
            } elseif (bdta_form_field_is_newsletter_opt_in($field)) {
                $responses[(string)$index] = bdta_form_field_newsletter_normalize_value($raw_value);
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
            if ($submission_id > 0 && is_array($submission_row)) {
                $client_id = array_int_value($submission_row, 'client_id');
                $booking_id = array_int_value($submission_row, 'booking_id');
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
            $submitted_by = isLoggedIn() ? safe_int($_SESSION['admin_id'] ?? 0) : 0;
            if ($submission_id > 0 && is_array($submission_row)) {
                $stmt = $conn->prepare("
                    UPDATE form_submissions
                    SET responses = ?, status = 'submitted', submitted_at = CURRENT_TIMESTAMP, submitted_by = ?
                    WHERE id = ? AND status = 'pending'
                ");
                $stmt->execute([$json_responses, $submitted_by > 0 ? $submitted_by : null, $submission_id]);
                $new_submission_id = $submission_id;
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO form_submissions (client_id, template_id, booking_id, responses, status, submitted_at, submitted_by)
                    VALUES (?, ?, ?, ?, 'submitted', CURRENT_TIMESTAMP, ?)
                ");
                $stmt->execute([
                    $client_id,
                    $template_id,
                    $booking_id > 0 ? $booking_id : null,
                    $json_responses,
                    $submitted_by > 0 ? $submitted_by : null,
                ]);
                $new_submission_id = (int) $conn->lastInsertId();
            }

            // Trigger any workflows attached to this form
            $workflow_helper = new WorkflowHelper($conn);
            try {
                $workflow_helper->checkFormTriggers($new_submission_id);
            } catch (Throwable $e) {
                error_log('Form submission workflow check failed for #' . $new_submission_id . ': ' . $e->getMessage());
            }

            public_form_sync_pet_info_group_profiles($conn, $client_id, $fields, $responses);

            if (bdta_form_fields_include_newsletter_opt_in($fields, $responses)) {
                $newsletter_result = bdta_subscribe_mailjet_contact_to_newsletter($contact_email, $contact_name);
                if (!$newsletter_result['success']) {
                    error_log(
                        'Mailjet newsletter opt-in failed for form submission #' . $new_submission_id . ': '
                        . scalar_string($newsletter_result['message'])
                    );
                }
            }

            $success_message = 'Thank you! Your form has been submitted successfully.';
            if (
                bdta_form_submission_requires_client_review($template_form_type)
                && bdta_form_template_is_client_portal_visible(is_array($template) ? $template : [])
            ) {
                $notification_result = bdta_notify_follow_up_note_completed($conn, $new_submission_id);
                if ($notification_result['success']) {
                    $success_message .= ' The client has been notified to review it in the portal.';
                } else {
                    error_log(
                        'Client notification email failed for submission #' . $new_submission_id . ': '
                        . scalar_string($notification_result['message'])
                    );
                    $success_message .= ' The follow-up note was saved, but the client notification email could not be sent at this time.';
                }
            }
        }
    }
}

$page_title = 'Complete Form';
$page_has_turnstile_widget = $success_message === '';
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
                <?php if ($client_id > 0): ?>
                    <input type="hidden" name="client_id" value="<?= (int) $client_id ?>">
                <?php endif; ?>
                <?php if ($booking_id > 0): ?>
                    <input type="hidden" name="booking_id" value="<?= (int) $booking_id ?>">
                <?php endif; ?>
                <?php if ($submission_id > 0): ?>
                    <input type="hidden" name="submission_id" value="<?= (int) $submission_id ?>">
                <?php endif; ?>
                <?php echo bdta_get_turnstile_widget_markup(); ?>

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
                    <?php if (bdta_form_field_is_display_only($field)): ?>
                        <div class="mb-4 p-3 rounded border bg-light">
                            <div class="fw-semibold mb-1"><?= htmlspecialchars($field_label) ?></div>
                            <?php $text_block_body = bdta_form_field_text_block_body($field); ?>
                            <?php if ($text_block_body !== ''): ?>
                                <div class="text-muted small"><?= nl2br(htmlspecialchars($text_block_body)) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php continue; ?>
                    <?php endif; ?>
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
                        <?php elseif (bdta_form_field_is_pet_info_group($field)): ?>
                            <?php
                                $pet_group_value = bdta_form_field_pet_info_group_normalize_response($field, $existing_value);
                                $pet_group_config = bdta_form_field_pet_info_group_config($field);
                                $pet_group_config_json = json_encode($pet_group_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                                $pet_group_value_json = json_encode($pet_group_value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                                $pet_group_existing_pets = isPortalLoggedIn()
                                    ? bdta_form_field_pet_info_group_prefill_pets($field, $portal_prefill_pets)
                                    : [];
                                $pet_group_existing_pets_json = json_encode($pet_group_existing_pets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                            ?>
                            <div class="pet-info-group border rounded p-3 bg-light"
                                 data-field-index="<?= (int) $index ?>"
                                 data-field-name="<?= htmlspecialchars($field_name, ENT_QUOTES, 'UTF-8') ?>"
                                 data-pet-info-config="<?= htmlspecialchars($pet_group_config_json === false ? '{}' : $pet_group_config_json, ENT_QUOTES, 'UTF-8') ?>"
                                 data-pet-info-value="<?= htmlspecialchars($pet_group_value_json === false ? '[]' : $pet_group_value_json, ENT_QUOTES, 'UTF-8') ?>"
                                 data-existing-pets="<?= htmlspecialchars($pet_group_existing_pets_json === false ? '[]' : $pet_group_existing_pets_json, ENT_QUOTES, 'UTF-8') ?>"
                                 data-login-url="<?= htmlspecialchars(isPortalLoggedIn() ? '' : $portal_login_url, ENT_QUOTES, 'UTF-8') ?>">
                                <?php if (!isPortalLoggedIn()): ?>
                                    <div class="small text-muted mb-3">
                                        Already a client with us?
                                        <a href="<?= htmlspecialchars($portal_login_url) ?>">Login</a>
                                        to skip the forms!
                                    </div>
                                <?php endif; ?>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label" for="petCountForm<?= (int) $index ?>">Number of Pets <span class="text-danger">*</span></label>
                                        <input type="text" id="petCountForm<?= (int) $index ?>" inputmode="numeric" pattern="[0-9]*" class="form-control" data-pet-count value="<?= max(1, count($pet_group_value)) ?>">
                                        <div class="form-text d-none" id="petCountFormLimit<?= (int) $index ?>" data-pet-limit-message aria-live="polite"></div>
                                    </div>
                                    <div class="col-md-auto d-flex align-items-end">
                                        <button type="button" class="btn btn-outline-primary" data-add-pet-button>Add New Pet</button>
                                    </div>
                                </div>
                                <div class="mt-3 d-none" data-existing-pets-section></div>
                                <div class="mt-3" data-pet-list></div>
                            </div>
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
                        <?php elseif (bdta_form_field_is_newsletter_opt_in($field)): ?>
                            <?php $newsletter_choice = bdta_form_field_newsletter_checkbox_label(); ?>
                            <input type="hidden" name="<?= $field_name ?>" value="">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       name="<?= $field_name ?>"
                                       id="field_<?= $index ?>_newsletter"
                                       value="<?= htmlspecialchars($newsletter_choice) ?>"
                                       <?= bdta_form_field_newsletter_is_opted_in($existing_value) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="field_<?= $index ?>_newsletter">
                                    <?= htmlspecialchars($newsletter_choice) ?>
                                </label>
                            </div>
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

<?php if ($page_has_turnstile_widget): ?>
<?php echo bdta_get_turnstile_assets_html(); ?>
<?php endif; ?>
<script>
document.querySelectorAll('.pet-info-group').forEach(function (group) {
    let config = {};
    let initialPets = [];
    let existingPets = [];
    let selectedExistingIds = [];
    let existingSelectionInitialized = false;
    try {
        config = JSON.parse(group.dataset.petInfoConfig || '{}') || {};
    } catch (err) {
        config = {};
    }
    try {
        initialPets = JSON.parse(group.dataset.petInfoValue || '[]') || [];
    } catch (err) {
        initialPets = [];
    }
    try {
        existingPets = JSON.parse(group.dataset.existingPets || '[]') || [];
    } catch (err) {
        existingPets = [];
    }

    const fieldName = group.dataset.fieldName || 'field[0]';
    const countInput = group.querySelector('[data-pet-count]');
    const list = group.querySelector('[data-pet-list]');
    const addPetButton = group.querySelector('[data-add-pet-button]');
    const existingPetsSection = group.querySelector('[data-existing-pets-section]');
    const limitMessage = group.querySelector('[data-pet-limit-message]');

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (char) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char] || char;
        });
    }

    function petIdValue(value) {
        return Number.parseInt(String(value || '0'), 10) || 0;
    }

    function sanitizePetCountInput() {
        countInput.value = countInput.value.replace(/[^\d]/g, '');
    }

    function petLimitMessageText(configuredMax) {
        return 'This session allows up to ' + configuredMax + ' pet' + (configuredMax === 1 ? '' : 's') + '.';
    }

    function clonePet(pet) {
        return {
            existing_pet_id: petIdValue(pet?.existing_pet_id),
            name: String(pet?.name || ''),
            age_or_dob: String(pet?.age_or_dob || ''),
            breed: String(pet?.breed || ''),
            vaccines_current: String(pet?.vaccines_current || ''),
            spayed_neutered: String(pet?.spayed_neutered || ''),
            source: String(pet?.source || ''),
            ownership_length: String(pet?.ownership_length || ''),
            species: String(pet?.species || '')
        };
    }

    function blankPet() {
        return {
            existing_pet_id: 0,
            name: '',
            age_or_dob: '',
            breed: '',
            vaccines_current: '',
            spayed_neutered: '',
            source: '',
            ownership_length: '',
            species: config.default_species || (config.dog_only_species ? 'Dog' : '')
        };
    }

    function maxPets() {
        const parsed = Number.parseInt(String(config.max_pets || '0'), 10) || 0;
        return parsed > 0 ? parsed : 0;
    }

    function petHasMeaningfulData(pet) {
        return ['name', 'age_or_dob', 'breed', 'vaccines_current', 'spayed_neutered', 'source', 'ownership_length', 'species']
            .some(function (key) {
                return String(pet?.[key] || '').trim() !== '';
            });
    }

    function meaningfulNewPets(pets) {
        return pets
            .filter(function (pet) { return petIdValue(pet?.existing_pet_id) <= 0; })
            .filter(petHasMeaningfulData)
            .map(clonePet);
    }

    function syncInitialExistingSelection() {
        if (existingSelectionInitialized || existingPets.length === 0) {
            return;
        }
        existingSelectionInitialized = true;

        if (selectedExistingIds.length > 0) {
            return;
        }

        const initialExistingIds = initialPets
            .map(function (pet) { return petIdValue(pet?.existing_pet_id); })
            .filter(Boolean);
        if (initialExistingIds.length > 0) {
            selectedExistingIds = initialExistingIds;
            return;
        }

        if (initialPets.length === 0) {
            const allowed = maxPets();
            selectedExistingIds = existingPets
                .slice(0, allowed > 0 ? Math.min(1, allowed) : 1)
                .map(function (pet) { return petIdValue(pet?.existing_pet_id); })
                .filter(Boolean);
            if (selectedExistingIds.length > 0) {
                initialPets = selectedExistingIds
                    .map(function (id) {
                        return existingPets.find(function (pet) { return petIdValue(pet?.existing_pet_id) === id; }) || null;
                    })
                    .filter(Boolean)
                    .map(clonePet);
            }
        }
    }

    function readPetsFromDom() {
        return Array.from(list.querySelectorAll('[data-pet-row]')).map(function (row) {
            return {
                existing_pet_id: petIdValue(row.querySelector('[data-pet-existing-id]')?.value || '0'),
                name: row.querySelector('[data-pet-attr="name"]')?.value || '',
                age_or_dob: row.querySelector('[data-pet-attr="age_or_dob"]')?.value || '',
                breed: row.querySelector('[data-pet-attr="breed"]')?.value || '',
                vaccines_current: row.querySelector('[data-pet-attr="vaccines_current"]')?.value || '',
                spayed_neutered: row.querySelector('[data-pet-attr="spayed_neutered"]')?.value || '',
                source: row.querySelector('[data-pet-attr="source"]')?.value || '',
                ownership_length: row.querySelector('[data-pet-attr="ownership_length"]')?.value || '',
                species: row.querySelector('[data-pet-attr="species"]')?.value || ''
            };
        });
    }

    function renderExistingPets(currentPets, requestedCount) {
        if (!existingPetsSection) {
            return;
        }

        if (existingPets.length === 0) {
            existingPetsSection.classList.add('d-none');
            existingPetsSection.innerHTML = '';
            return;
        }

        const configuredMax = maxPets();
        const selectedCount = selectedExistingIds.length;
        existingPetsSection.classList.remove('d-none');
        existingPetsSection.innerHTML = `
            <div class="small fw-semibold mb-2">Pets already on file</div>
            <div class="small text-muted mb-2">Select the pets attending and we’ll keep the pet count in sync automatically.</div>
            <div class="d-flex flex-column gap-2">
                ${existingPets.map(function (pet) {
                    const petId = petIdValue(pet?.existing_pet_id);
                    const isSelected = selectedExistingIds.includes(petId);
                    const disableUnchecked = configuredMax > 0 && !isSelected && selectedCount >= configuredMax;
                    const currentPet = currentPets.find(function (rowPet) { return petIdValue(rowPet?.existing_pet_id) === petId; }) || pet;
                    const breedLabel = currentPet.breed ? ` <span class="text-muted small">(${escapeHtml(currentPet.breed)})</span>` : '';
                    return `
                        <label class="form-check border rounded px-3 py-2 bg-white">
                            <input class="form-check-input me-2" type="checkbox" data-existing-pet-checkbox value="${petId}" ${isSelected ? 'checked' : ''} ${disableUnchecked ? 'disabled' : ''}>
                            <span class="fw-semibold">${escapeHtml(currentPet.name || 'Pet')}</span>${breedLabel}
                        </label>
                    `;
                }).join('')}
            </div>
        `;

        existingPetsSection.querySelectorAll('[data-existing-pet-checkbox]').forEach(function (checkbox) {
            checkbox.addEventListener('change', function () {
                const currentNewPetCount = meaningfulNewPets(readPetsFromDom().map(clonePet)).length;
                const petId = petIdValue(checkbox.value);
                if (checkbox.checked) {
                    if (!selectedExistingIds.includes(petId)) {
                        selectedExistingIds.push(petId);
                    }
                } else {
                    selectedExistingIds = selectedExistingIds.filter(function (id) { return id !== petId; });
                }
                countInput.value = String(Math.max(1, selectedExistingIds.length + currentNewPetCount));
                renderPets();
            });
        });
    }

    function renderPets() {
        syncInitialExistingSelection();
        const currentPets = list.children.length ? readPetsFromDom().map(clonePet) : initialPets.map(clonePet);
        const newPets = meaningfulNewPets(currentPets);

        let requestedCount = Number.parseInt(String(countInput.value || ''), 10);
        if (!Number.isFinite(requestedCount) || requestedCount <= 0) {
            requestedCount = Math.max(1, currentPets.length || selectedExistingIds.length || initialPets.length || 1);
        }
        requestedCount = Math.max(1, requestedCount);
        const configuredMax = maxPets();
        if (configuredMax > 0) {
            requestedCount = Math.min(requestedCount, configuredMax);
        }
        const validExistingPetIds = new Set(existingPets.map(function (pet) {
            return petIdValue(pet?.existing_pet_id);
        }));
        selectedExistingIds = selectedExistingIds.filter(function (petId) {
            return validExistingPetIds.has(petId);
        });
        if (selectedExistingIds.length > requestedCount) {
            selectedExistingIds = selectedExistingIds.slice(0, requestedCount);
        }
        const selectedExistingPets = selectedExistingIds
            .map(function (petId) {
                return currentPets.find(function (pet) { return petIdValue(pet.existing_pet_id) === petId; })
                    || existingPets.find(function (pet) { return petIdValue(pet.existing_pet_id) === petId; })
                    || null;
            })
            .filter(Boolean)
            .map(clonePet);
        countInput.value = String(requestedCount);

        const editablePets = newPets.slice(0, Math.max(0, requestedCount - selectedExistingPets.length));
        while (editablePets.length < Math.max(0, requestedCount - selectedExistingPets.length)) {
            editablePets.push(blankPet());
        }
        initialPets = selectedExistingPets.concat(editablePets).map(clonePet);

        if (limitMessage) {
            if (configuredMax > 0) {
                limitMessage.classList.remove('d-none');
                limitMessage.textContent = petLimitMessageText(configuredMax);
            } else {
                limitMessage.classList.add('d-none');
                limitMessage.textContent = '';
            }
        }
        if (addPetButton) {
            addPetButton.disabled = configuredMax > 0 && requestedCount >= configuredMax;
        }
        renderExistingPets(currentPets, requestedCount);

        const existingPetCards = selectedExistingPets.map(function (pet, index) {
            const speciesSummary = config.include_species && (pet.species || config.default_species)
                ? ` • ${escapeHtml(pet.species || config.default_species)}`
                : '';
            return `
                <div class="card mb-3 border-secondary-subtle" data-pet-row data-selected-existing-pet>
                    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                        <span>Pet ${index + 1}</span>
                        <span class="badge text-bg-secondary">On File</span>
                    </div>
                    <div class="card-body py-3">
                        <div class="small">
                            <span class="fw-semibold">${escapeHtml(pet.name || 'Pet')}</span>
                            ${pet.breed ? `<span class="text-muted"> • ${escapeHtml(pet.breed)}</span>` : ''}
                            ${speciesSummary ? `<span class="text-muted">${speciesSummary}</span>` : ''}
                        </div>
                        <div class="small text-muted mt-1">Details already on file. Use Add New Pet to enter a different pet.</div>
                        <input type="hidden" name="${fieldName}[pets][${index}][existing_pet_id]" value="${petIdValue(pet.existing_pet_id)}" data-pet-existing-id>
                        <input type="hidden" name="${fieldName}[pets][${index}][name]" value="${escapeHtml(pet.name)}" data-pet-attr="name">
                        <input type="hidden" name="${fieldName}[pets][${index}][age_or_dob]" value="${escapeHtml(pet.age_or_dob)}" data-pet-attr="age_or_dob">
                        <input type="hidden" name="${fieldName}[pets][${index}][breed]" value="${escapeHtml(pet.breed)}" data-pet-attr="breed">
                        <input type="hidden" name="${fieldName}[pets][${index}][vaccines_current]" value="${escapeHtml(pet.vaccines_current)}" data-pet-attr="vaccines_current">
                        <input type="hidden" name="${fieldName}[pets][${index}][spayed_neutered]" value="${escapeHtml(pet.spayed_neutered)}" data-pet-attr="spayed_neutered">
                        <input type="hidden" name="${fieldName}[pets][${index}][source]" value="${escapeHtml(pet.source)}" data-pet-attr="source">
                        <input type="hidden" name="${fieldName}[pets][${index}][ownership_length]" value="${escapeHtml(pet.ownership_length)}" data-pet-attr="ownership_length">
                        <input type="hidden" name="${fieldName}[pets][${index}][species]" value="${escapeHtml(pet.species || config.default_species || '')}" data-pet-attr="species">
                    </div>
                </div>
            `;
        }).join('');

        const editablePetCards = editablePets.map(function (pet, editableIndex) {
            const index = selectedExistingPets.length + editableIndex;
            const speciesField = config.include_species
                ? (config.dog_only_species
                    ? `<div class="col-md-6">
                            <label class="form-label">Species</label>
                            <input type="text" class="form-control" value="Dog" readonly>
                            <input type="hidden" name="${fieldName}[pets][${index}][species]" value="Dog" data-pet-attr="species">
                       </div>`
                    : `<div class="col-md-6">
                            <label class="form-label">Species</label>
                            <input type="text" class="form-control" name="${fieldName}[pets][${index}][species]" value="${escapeHtml(pet.species || config.default_species || '')}" data-pet-attr="species">
                       </div>`)
                : '';
            return `
                <div class="card mb-3" data-pet-row>
                    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                        <span>Pet ${index + 1}</span>
                    </div>
                    <div class="card-body">
                        <input type="hidden" name="${fieldName}[pets][${index}][existing_pet_id]" value="${petIdValue(pet.existing_pet_id)}" data-pet-existing-id>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Pet Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="${fieldName}[pets][${index}][name]" value="${escapeHtml(pet.name)}" data-pet-attr="name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Age or Date of Birth <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="${fieldName}[pets][${index}][age_or_dob]" value="${escapeHtml(pet.age_or_dob)}" data-pet-attr="age_or_dob" placeholder="e.g. 2 years, 6 months or 2021-04-15" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Breed <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="${fieldName}[pets][${index}][breed]" value="${escapeHtml(pet.breed)}" data-pet-attr="breed" required>
                                <div class="form-text">If breed is unknown, describe the pet’s color, pattern, or identifying features.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Vaccine Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="${fieldName}[pets][${index}][vaccines_current]" data-pet-attr="vaccines_current" required>
                                    <option value="">— Select —</option>
                                    <option value="yes" ${pet.vaccines_current === 'yes' ? 'selected' : ''}>Current</option>
                                    <option value="no" ${pet.vaccines_current === 'no' ? 'selected' : ''}>Not Current</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Spay/Neuter Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="${fieldName}[pets][${index}][spayed_neutered]" data-pet-attr="spayed_neutered" required>
                                    <option value="">— Select —</option>
                                    <option value="yes" ${pet.spayed_neutered === 'yes' ? 'selected' : ''}>Yes, spayed/neutered</option>
                                    <option value="no" ${pet.spayed_neutered === 'no' ? 'selected' : ''}>No, intact</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Where did you acquire this pet from? <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="${fieldName}[pets][${index}][source]" value="${escapeHtml(pet.source)}" data-pet-attr="source" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">How long have you had this pet? <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="${fieldName}[pets][${index}][ownership_length]" value="${escapeHtml(pet.ownership_length)}" data-pet-attr="ownership_length" placeholder="e.g. 1 year, 3 months" required>
                            </div>
                            ${speciesField}
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        list.innerHTML = existingPetCards + editablePetCards;
    }

    countInput.addEventListener('input', function () {
        sanitizePetCountInput();
    });
    countInput.addEventListener('change', renderPets);
    countInput.addEventListener('blur', renderPets);
    if (addPetButton) {
        addPetButton.addEventListener('click', function () {
            const currentCount = Number.parseInt(String(countInput.value || '0'), 10) || readPetsFromDom().length || initialPets.length || 0;
            countInput.value = String(currentCount + 1);
            renderPets();
        });
    }
    renderPets();
});
</script>
<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
