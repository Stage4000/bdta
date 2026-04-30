<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';
require_once '../backend/includes/form_types.php';
require_once '../backend/includes/form_link_requests.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

$request_data = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$form_type = bdta_normalize_form_type(scalar_string($request_data['form_type'] ?? 'client_form'));
$client_id = safe_int($request_data['client_id'] ?? 0);
$booking_id = safe_int($request_data['booking_id'] ?? 0);
$pet_id = safe_int($request_data['pet_id'] ?? 0);
$appointment_type_id = safe_int($request_data['appointment_type_id'] ?? 0);
$template_id = safe_int($request_data['template_id'] ?? 0);

$client = [];
$booking = [];
$pet = [];
$appointment_type = [];
$templates = [];
$all_clients = [];
$errors = [];
$generated_link = '';
$success_message = '';

if ($booking_id > 0) {
    $stmt = $conn->prepare("
        SELECT b.*, c.name AS client_profile_name, c.email AS client_profile_email
        FROM bookings b
        LEFT JOIN clients c ON b.client_id = c.id
        WHERE b.id = ?
    ");
    $stmt->execute([$booking_id]);
    $booking = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
    if ($booking === []) {
        $errors[] = 'Appointment not found.';
    } else {
        $client_id = array_int_value($booking, 'client_id');
    }
}

if ($pet_id > 0) {
    $stmt = $conn->prepare("
        SELECT p.*, c.name AS client_name
        FROM pets p
        JOIN clients c ON p.client_id = c.id
        WHERE p.id = ?
    ");
    $stmt->execute([$pet_id]);
    $pet = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
    if ($pet === []) {
        $errors[] = 'Pet not found.';
    } else {
        $client_id = array_int_value($pet, 'client_id');
    }
}

if ($appointment_type_id > 0) {
    $stmt = $conn->prepare("SELECT id, name, unique_link FROM appointment_types WHERE id = ?");
    $stmt->execute([$appointment_type_id]);
    $appointment_type = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
    if ($appointment_type === []) {
        $errors[] = 'Appointment type not found.';
    }
}

if ($client_id > 0) {
    $client = bdta_fetch_active_client($conn, $client_id);
    if ($client === []) {
        $errors[] = 'Client not found.';
    }
}

if ($form_type === 'booking_form') {
    $stmt = $conn->query("SELECT id, name, email FROM clients WHERE COALESCE(is_archived, 0) = 0 ORDER BY name");
    $all_clients = assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
}

if ($form_type !== 'booking_form') {
    $db_values = array_slice(bdta_get_form_type_db_values($form_type), 0, 3);
    $primary_type = $db_values[0] ?? '';
    $secondary_type = $db_values[1] ?? null;
    $tertiary_type = $db_values[2] ?? null;
    $stmt = $conn->prepare("
        SELECT id, name, description, form_type
        FROM form_templates
        WHERE is_active = 1
          AND (
              form_type = ?
              OR (? IS NOT NULL AND form_type = ?)
              OR (? IS NOT NULL AND form_type = ?)
          )
        ORDER BY name
    ");
    $stmt->execute([$primary_type, $secondary_type, $secondary_type, $tertiary_type, $tertiary_type]);
    $templates = assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
}

if ($form_type === 'booking_form' && ($appointment_type === [] || array_string_value($appointment_type, 'unique_link') === '')) {
    $errors[] = 'This booking link is not available because the appointment type is missing its public link.';
}

if ($form_type === 'client_form' || $form_type === 'survey_form') {
    if ($client === []) {
        $errors[] = 'A client is required for this form request.';
    }
}

if ($form_type === 'follow_up_note' && $booking === []) {
    $errors[] = 'An appointment is required for follow-up note forms.';
}

if ($form_type === 'pet_form' && $pet === []) {
    $errors[] = 'A pet is required for pet forms.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), scalar_string($_POST['csrf_token']))) {
        $errors[] = 'Invalid request.';
    } else {
        $action = scalar_string($_POST['request_action'] ?? 'generate');
        $email_subject = trim(scalar_string($_POST['email_subject'] ?? ''));

        if ($form_type === 'booking_form' && $client === [] && $client_id > 0) {
            $client = bdta_fetch_active_client($conn, $client_id);
        }

        if ($form_type !== 'booking_form' && $template_id <= 0) {
            $errors[] = 'Please choose a form template.';
        }

        $selected_template = [];
        if ($template_id > 0) {
            foreach ($templates as $candidate_template) {
                if (array_int_value($candidate_template, 'id') === $template_id) {
                    $selected_template = $candidate_template;
                    break;
                }
            }
            if ($form_type !== 'booking_form' && $selected_template === []) {
                $errors[] = 'The selected form template is not available for this request.';
            }
        }

        if ($action === 'send' && $client === []) {
            $errors[] = 'A client with an email address is required to send this link.';
        }

        if ($action === 'send' && bdta_form_type_forced_internal($form_type)) {
            $errors[] = 'This form type cannot be emailed because it is for admin use only.';
        }

        if (empty($errors)) {
            try {
                if ($form_type === 'booking_form') {
                    $generated_link = bdta_get_public_booking_request_url(array_string_value($appointment_type, 'unique_link'));
                } else {
                    $request = bdta_create_form_request(
                        $conn,
                        $template_id,
                        array_int_value($client, 'id'),
                        $booking === [] ? null : array_int_value($booking, 'id'),
                        $pet === [] ? null : array_int_value($pet, 'id'),
                        $action === 'send' ? date('Y-m-d H:i:s') : null
                    );
                    $generated_link = array_string_value($request, 'url');
                }

                if ($action === 'open') {
                    header('Location: ' . $generated_link);
                    exit;
                }

                if ($action === 'send') {
                    $link_label = $form_type === 'booking_form'
                        ? escape(array_string_value($appointment_type, 'name'))
                        : escape(array_string_value($selected_template, 'name'));
                    $client_name = escape(array_string_value($client, 'name'));
                    $default_subject = $form_type === 'booking_form'
                        ? 'Book your ' . array_string_value($appointment_type, 'name')
                        : 'Please complete your ' . array_string_value($selected_template, 'name');
                    $subject = $email_subject !== '' ? $email_subject : $default_subject;
                    $html_body = $form_type === 'booking_form'
                        ? '<p>Hello ' . $client_name . ',</p><p>Please use the link below to book your <strong>' . $link_label . '</strong>.</p><p><a href="' . escape($generated_link) . '">Open Booking Link</a></p>'
                        : '<p>Hello ' . $client_name . ',</p><p>Please use the link below to complete your <strong>' . $link_label . '</strong>.</p><p><a href="' . escape($generated_link) . '">Open Form</a></p>';
                    $text_body = $form_type === 'booking_form'
                        ? "Hello " . array_string_value($client, 'name') . ",\n\nPlease use the link below to book your " . array_string_value($appointment_type, 'name') . ":\n" . $generated_link
                        : "Hello " . array_string_value($client, 'name') . ",\n\nPlease use the link below to complete your " . array_string_value($selected_template, 'name') . ":\n" . $generated_link;

                    $send_result = bdta_send_client_form_link_email(
                        $conn,
                        array_int_value($client, 'id'),
                        $subject,
                        $html_body,
                        $text_body
                    );

                    if (!$send_result['success']) {
                        $errors[] = array_string_value($send_result, 'message');
                    } else {
                        $success_message = 'Link emailed to ' . array_string_value($client, 'email') . '.';
                    }
                } else {
                    $success_message = 'Link generated successfully.';
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

$back_link = 'form_templates_list.php';
if ($pet !== []) {
    $back_link = 'pets_view.php?id=' . array_int_value($pet, 'id');
} elseif ($client !== []) {
    $back_link = 'clients_view.php?id=' . array_int_value($client, 'id');
} elseif ($appointment_type !== []) {
    $back_link = 'appointment_types_edit.php?id=' . array_int_value($appointment_type, 'id');
}

$page_title = 'Generate Form Link';
include '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2><i class="fas fa-link me-2"></i><?= escape(bdta_get_form_type_label($form_type)) ?> Request</h2>
            <p class="text-muted mb-0"><?= escape(bdta_get_form_type_description($form_type)) ?></p>
        </div>
        <a href="<?= escape($back_link) ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>

    <?php if ($errors !== []): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?= escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success_message !== ''): ?>
        <div class="alert alert-success"><?= escape($success_message) ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Request Details</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="form_type" value="<?= escape($form_type) ?>">
                        <?php if ($all_clients === []): ?>
                            <input type="hidden" name="client_id" value="<?= (int) $client_id ?>">
                        <?php endif; ?>
                        <input type="hidden" name="booking_id" value="<?= (int) $booking_id ?>">
                        <input type="hidden" name="pet_id" value="<?= (int) $pet_id ?>">
                        <input type="hidden" name="appointment_type_id" value="<?= (int) $appointment_type_id ?>">

                        <?php if ($appointment_type !== []): ?>
                            <div class="mb-3">
                                <label class="form-label">Appointment Type</label>
                                <div class="border rounded px-3 py-2 bg-light">
                                    <?= escape(array_string_value($appointment_type, 'name')) ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($booking !== []): ?>
                            <div class="mb-3">
                                <label class="form-label">Appointment</label>
                                <div class="border rounded px-3 py-2 bg-light">
                                    <?= escape(array_string_value($booking, 'service_type')) ?><br>
                                    <small class="text-muted"><?= escape(array_string_value($booking, 'appointment_date')) ?> at <?= escape(array_string_value($booking, 'appointment_time')) ?></small>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($pet !== []): ?>
                            <div class="mb-3">
                                <label class="form-label">Pet</label>
                                <div class="border rounded px-3 py-2 bg-light">
                                    <?= escape(array_string_value($pet, 'name')) ?><br>
                                    <small class="text-muted"><?= escape(array_string_value($pet, 'species')) ?><?= array_string_value($pet, 'breed') !== '' ? ' · ' . escape(array_string_value($pet, 'breed')) : '' ?></small>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($all_clients !== []): ?>
                            <div class="mb-3">
                                <label for="client_id_select" class="form-label">Client</label>
                                <select class="form-select" id="client_id_select" name="client_id">
                                    <option value="">No client preselected</option>
                                    <?php foreach ($all_clients as $select_client): ?>
                                        <option value="<?= array_int_value($select_client, 'id') ?>" <?= $client_id === array_int_value($select_client, 'id') ? 'selected' : '' ?>>
                                            <?= escape(array_string_value($select_client, 'name')) ?> (<?= escape(array_string_value($select_client, 'email')) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Choose a client if you want to email the booking link directly.</small>
                            </div>
                        <?php elseif ($client !== []): ?>
                            <div class="mb-3">
                                <label class="form-label">Client</label>
                                <div class="border rounded px-3 py-2 bg-light">
                                    <?= escape(array_string_value($client, 'name')) ?><br>
                                    <small class="text-muted"><?= escape(array_string_value($client, 'email')) ?></small>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($form_type !== 'booking_form'): ?>
                            <div class="mb-3">
                                <label for="template_id" class="form-label">Form Template</label>
                                <select class="form-select" id="template_id" name="template_id" required>
                                    <option value="">Select a template</option>
                                    <?php foreach ($templates as $template): ?>
                                        <option value="<?= array_int_value($template, 'id') ?>" <?= $template_id === array_int_value($template, 'id') ? 'selected' : '' ?>>
                                            <?= escape(array_string_value($template, 'name')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($templates === []): ?>
                                    <small class="form-text text-danger">No active <?= escape(strtolower(bdta_get_form_type_label($form_type))) ?> templates exist yet.</small>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($client !== [] && !bdta_form_type_forced_internal($form_type)): ?>
                            <div class="mb-3">
                                <label for="email_subject" class="form-label">Email Subject</label>
                                <input type="text" class="form-control" id="email_subject" name="email_subject"
                                       value="<?= escape(scalar_string($_POST['email_subject'] ?? '')) ?>"
                                       placeholder="<?= $form_type === 'booking_form' ? 'Book your appointment' : 'Please complete your form' ?>">
                            </div>
                        <?php endif; ?>

                        <div class="d-flex flex-wrap gap-2">
                            <button type="submit" name="request_action" value="generate" class="btn btn-outline-primary">
                                <i class="fas fa-link me-1"></i> Generate Link
                            </button>
                            <button type="submit" name="request_action" value="open" class="btn btn-primary" formtarget="_blank">
                                <i class="fas fa-up-right-from-square me-1"></i> Open Form
                            </button>
                            <?php if ($client !== [] && !bdta_form_type_forced_internal($form_type)): ?>
                                <button type="submit" name="request_action" value="send" class="btn btn-success">
                                    <i class="fas fa-paper-plane me-1"></i> Send Email
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Generated Link</h5>
                </div>
                <div class="card-body">
                    <?php if ($generated_link !== ''): ?>
                        <div class="input-group">
                            <input type="text" class="form-control" id="generated_form_link" value="<?= escape($generated_link) ?>" readonly>
                            <button class="btn btn-outline-secondary" type="button" onclick="copyGeneratedFormLink()">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                            <a href="<?= escape($generated_link) ?>" class="btn btn-outline-primary" target="_blank" rel="noopener noreferrer">
                                <i class="fas fa-up-right-from-square"></i> Open
                            </a>
                        </div>
                        <div id="generated_form_link_status" class="form-text text-success visually-hidden mt-1">Link copied!</div>
                    <?php else: ?>
                        <p class="text-muted mb-0">Generate or send a request to create the object-linked form URL.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyGeneratedFormLink() {
    const input = document.getElementById('generated_form_link');
    const status = document.getElementById('generated_form_link_status');
    if (!input) {
        return;
    }

    input.select();
    input.setSelectionRange(0, input.value.length);

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value).then(() => {
            status?.classList.remove('visually-hidden');
            setTimeout(() => status?.classList.add('visually-hidden'), 2000);
        });
        return;
    }

    document.execCommand('copy');
    if (status) {
        status.textContent = 'Link copied!';
        status.classList.remove('visually-hidden');
        setTimeout(() => status.classList.add('visually-hidden'), 2000);
        return;
    }

    alert('Copy the link manually from the text field.');
}
</script>

<?php include '../backend/includes/footer.php'; ?>
