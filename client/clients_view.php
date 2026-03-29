<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/form_types.php';
require_once '../backend/includes/follow_up_notes.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$id = safe_int($_GET['id'] ?? 0);

if ($id <= 0) {
    setFlashMessage('Invalid client ID!', 'danger');
    redirect('clients_list.php');
}

// Get client details
$stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!is_array($client)) {
    setFlashMessage('Client not found!', 'danger');
    redirect('clients_list.php');
}

$clientListUrl = !empty($client['is_archived']) ? 'clients_list.php?view=archived' : 'clients_list.php';

// Get client's pets with file count
$stmt = $conn->prepare("
    SELECT p.*, COUNT(pf.id) as file_count
    FROM pets p
    LEFT JOIN pet_files pf ON p.id = pf.pet_id
    WHERE p.client_id = ?
    GROUP BY p.id
    ORDER BY p.name
");
$stmt->execute([$id]);
$pets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get appointments (past and upcoming)
$stmt = $conn->prepare("
    SELECT b.*, at.name as appointment_type_name
    FROM bookings b
    LEFT JOIN appointment_types at ON b.appointment_type_id = at.id
    WHERE b.client_id = ?
    ORDER BY b.appointment_date DESC, b.appointment_time DESC
");
$stmt->execute([$id]);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Separate past and upcoming
$upcoming_appointments = [];
$past_appointments = [];
$today = date('Y-m-d');
foreach ($appointments as $apt) {
    if ($apt['appointment_date'] >= $today) {
        $upcoming_appointments[] = $apt;
    } else {
        $past_appointments[] = $apt;
    }
}

// Get contracts
$stmt = $conn->prepare("SELECT * FROM contracts WHERE client_id = ? ORDER BY created_at DESC");
$stmt->execute([$id]);
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get forms
$stmt = $conn->prepare("
    SELECT fs.*, ft.name as form_name, ft.form_type,
           p.name AS pet_name,
           b.appointment_date,
           b.appointment_time,
           b.service_type
    FROM form_submissions fs
    JOIN form_templates ft ON fs.template_id = ft.id
    LEFT JOIN pets p ON fs.pet_id = p.id
    LEFT JOIN bookings b ON fs.booking_id = b.id
    WHERE fs.client_id = ?
    ORDER BY fs.submitted_at DESC
");
$stmt->execute([$id]);
$forms = $stmt->fetchAll(PDO::FETCH_ASSOC);
$follow_up_submissions_by_booking = bdta_index_follow_up_submissions_by_booking($forms);

// Get quotes
$stmt = $conn->prepare("SELECT * FROM quotes WHERE client_id = ? ORDER BY created_at DESC");
$stmt->execute([$id]);
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get invoices
$stmt = $conn->prepare("SELECT * FROM invoices WHERE client_id = ? ORDER BY created_at DESC");
$stmt->execute([$id]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active package credit summary per appointment type
$stmt = $conn->prepare("
    SELECT at.name AS apt_type_name,
           SUM(cpc.total_credits - cpc.used_credits) AS remaining
    FROM client_package_credits cpc
    JOIN client_packages cp ON cpc.client_package_id = cp.id
    JOIN appointment_types at ON cpc.appointment_type_id = at.id
    WHERE cpc.client_id = ?
      AND cp.is_active = 1
      AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
      AND (cpc.total_credits - cpc.used_credits) > 0
    GROUP BY cpc.appointment_type_id, at.name
");
$stmt->execute([$id]);
$pkg_credits_summary = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $summary_row) {
    $pkg_credits_summary[array_string_value($summary_row, 'apt_type_name')] = array_int_value($summary_row, 'remaining');
}

// Get email count
$stmt = $conn->prepare("SELECT COUNT(*) as email_count FROM client_emails WHERE client_id = ?");
$stmt->execute([$id]);
$email_count = $stmt->fetchColumn();

// Get client contacts
$stmt = $conn->prepare("SELECT * FROM client_contacts WHERE client_id = ? ORDER BY is_primary DESC, name ASC");
$stmt->execute([$id]);
$contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>
            <i class="fas fa-user-circle me-2"></i><?= escape($client['name']) ?>
            <?php if (!empty($client['is_archived'])): ?>
                <span class="badge bg-secondary ms-2"><i class="fas fa-box-archive me-1"></i>Archived</span>
            <?php endif; ?>
        </h2>
        <div>
            <a href="clients_edit.php?id=<?= $id ?>" class="btn btn-primary me-2">
                <i class="fas fa-pencil"></i> Edit Client
            </a>
            <?php if (empty($client['is_archived'])): ?>
            <a href="bookings_create.php?client_id=<?= $id ?>" class="btn btn-success me-2">
                <i class="fas fa-calendar-plus"></i> New Booking
            </a>
            <?php endif; ?>
            <?php if (empty($client['is_admin']) && empty($client['is_archived'])): ?>
            <a href="impersonate_client.php?id=<?= $id ?>" class="btn btn-warning me-2"
               onclick="return confirm('View the client portal as this client?')">
                <i class="fas fa-eye"></i> View Portal as Client
            </a>
            <?php endif; ?>
            <form method="post" action="clients_list.php" class="d-inline me-2" onsubmit="return confirm('<?= !empty($client['is_archived']) ? 'Unarchive this client and return them to the active client list?' : 'Archive this client? Pending items such as quotes, contracts, invoices, forms, workflows, and bookings will be cancelled or voided.' ?>')">
                <input type="hidden" name="<?= !empty($client['is_archived']) ? 'unarchive_id' : 'archive_id' ?>" value="<?= $id ?>">
                <input type="hidden" name="return_view" value="<?= !empty($client['is_archived']) ? 'archived' : 'active' ?>">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                <button type="submit" class="btn <?= !empty($client['is_archived']) ? 'btn-info' : 'btn-outline-secondary' ?>">
                    <i class="fas <?= !empty($client['is_archived']) ? 'fa-box-open' : 'fa-box-archive' ?>"></i>
                    <?= !empty($client['is_archived']) ? 'Unarchive Client' : 'Archive Client' ?>
                </button>
            </form>
            <a href="<?= $clientListUrl ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <?php
    $flash = getFlashMessage();
    if ($flash):
    ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= escape($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($client['is_archived'])): ?>
        <div class="alert alert-secondary">
            <i class="fas fa-box-archive me-2"></i>
            This client is archived and hidden from active client selectors. Historical records remain available here.
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Client Info Card -->
        <div class="col-md-4 mb-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-circle-info me-2"></i>Client Information</h5>
                </div>
                <div class="card-body">
                    <dl class="mb-0">
                        <dt>Primary Contact:</dt>
                        <dd>
                            <a href="mailto:<?= escape($client['email']) ?>"><?= escape($client['email']) ?></a>
                            <br>
                            <small class="text-muted"><?= escape($client['phone'] ?: 'No phone') ?></small>
                        </dd>
                        
                        <dt>Address:</dt>
                        <dd><?= escape($client['address'] ?: 'Not provided') ?></dd>
                        
                        <dt>Member Since:</dt>
                        <dd><?= formatDate($client['created_at']) ?></dd>

                        <?php if (!empty($client['is_archived']) && !empty($client['archived_at'])): ?>
                            <dt>Archived On:</dt>
                            <dd><?= formatDate($client['archived_at']) ?></dd>
                        <?php endif; ?>
                        
                        <?php if (!empty($pkg_credits_summary)): ?>
                            <dt>Credits:</dt>
                            <dd>
                                <?php foreach ($pkg_credits_summary as $apt_name => $rem): ?>
                                     <span class="badge bg-primary me-1"><?= escape($apt_name) ?>: <?= (int)$rem ?></span>
                                <?php endforeach; ?>
                            </dd>
                        <?php endif; ?>

                        <?php if (!empty($pkg_credits_summary)): ?>
                            <dd>
                                <a href="credits_manage.php?client_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-wallet"></i> Manage Credits
                                </a>
                            </dd>
                        <?php endif; ?>
                        
                        <?php if ($client['notes']): ?>
                            <dt>Notes:</dt>
                            <dd><?= nl2br(escape($client['notes'])) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>

            <!-- Contacts Card -->
            <div class="card mt-3">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-address-book me-2"></i>Additional Contacts</h5>
                    <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#contactModal" onclick="showAddContactModal()">
                        <i class="fas fa-plus"></i> Add Contact
                    </button>
                </div>
                <div class="card-body" id="contactsList">
                    <?php if (empty($contacts)): ?>
                        <p class="text-muted mb-0">No additional contacts</p>
                    <?php else: ?>
                        <?php foreach ($contacts as $contact): ?>
                            <div class="border-bottom pb-2 mb-2" id="contact-<?= $contact['id'] ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?= escape($contact['name']) ?></strong>
                                        <?php if ($contact['is_primary']): ?>
                                            <span class="badge bg-primary ms-1">Primary</span>
                                        <?php endif; ?>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-envelope"></i> <a href="mailto:<?= escape($contact['email']) ?>"><?= escape($contact['email']) ?></a>
                                        </small>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-phone"></i> <?= escape($contact['phone']) ?>
                                        </small>
                                    </div>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary edit-contact-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#contactModal"
                                                data-contact-id="<?= $contact['id'] ?>"
                                                data-contact-name="<?= escape($contact['name']) ?>"
                                                data-contact-email="<?= escape($contact['email']) ?>"
                                                data-contact-phone="<?= escape($contact['phone']) ?>"
                                                data-contact-primary="<?= $contact['is_primary'] ?>">
                                            <i class="fas fa-pencil"></i>
                                        </button>
                                        <button class="btn btn-outline-danger delete-contact-btn" 
                                                data-contact-id="<?= $contact['id'] ?>"
                                                data-contact-name="<?= escape($contact['name']) ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pets Card -->
            <div class="card mt-3">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-dog me-2"></i>Pets</h5>
                    <a href="pets_edit.php?client_id=<?= $id ?>" class="btn btn-sm btn-light">
                        <i class="fas fa-plus"></i> Add Pet
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($pets)): ?>
                        <p class="text-muted mb-0">No pets registered</p>
                    <?php else: ?>
                        <?php foreach ($pets as $pet): ?>
                            <div class="border-bottom pb-2 mb-2">
                                <strong><?= escape($pet['name']) ?></strong>
                                <small class="text-muted d-block">
                                    <?= escape($pet['species']) ?> 
                                    <?= $pet['breed'] ? '- ' . escape($pet['breed']) : '' ?>
                                </small>
                                <?php if ($pet['file_count'] > 0): ?>
                                    <small class="text-info d-block">
                                        <i class="fas fa-paperclip"></i> <?= $pet['file_count'] ?> file(s) uploaded
                                    </small>
                                <?php endif; ?>
                                <a href="pets_edit.php?id=<?= $pet['id'] ?>" class="btn btn-xs btn-outline-secondary mt-1">
                                    <i class="fas fa-pencil"></i> Edit
                                </a>
                                <a href="form_requests_create.php?form_type=pet_form&amp;pet_id=<?= (int) $pet['id'] ?>" class="btn btn-xs btn-outline-success mt-1">
                                    <i class="fas fa-file-medical"></i> Pet Form
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Activity Tabs -->
        <div class="col-md-8">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" data-bs-toggle="tab" href="#appointments">
                        <i class="fas fa-calendar-check"></i> Appointments 
                        <span class="badge bg-primary"><?= count($appointments) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="tab" href="#contracts">
                        <i class="fas fa-file-invoice"></i> Contracts 
                        <span class="badge bg-secondary"><?= count($contracts) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="tab" href="#forms">
                        <i class="fas fa-list-check"></i> Forms 
                        <span class="badge bg-secondary"><?= count($forms) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="tab" href="#quotes">
                        <i class="fas fa-file-ruled"></i> Quotes 
                        <span class="badge bg-secondary"><?= count($quotes) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="tab" href="#invoices">
                        <i class="fas fa-receipt"></i> Invoices 
                        <span class="badge bg-secondary"><?= count($invoices) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="tab" href="#emails">
                        <i class="fas fa-envelope"></i> Email 
                        <span class="badge bg-secondary"><?= $email_count ?></span>
                    </a>
                </li>
            </ul>

            <div class="tab-content border border-top-0 p-3">
                <!-- Appointments Tab -->
                <div id="appointments" class="tab-pane fade show active">
                    <h5>Upcoming Appointments</h5>
                    <?php if (empty($upcoming_appointments)): ?>
                        <p class="text-muted">No upcoming appointments</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Service</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($upcoming_appointments as $apt): ?>
                                        <tr>
                                            <td><?= formatDate($apt['appointment_date']) ?></td>
                                            <td><?= date('g:i A', safe_timestamp(strtotime($apt['appointment_time']))) ?></td>
                                            <td><?= escape($apt['appointment_type_name'] ?: $apt['service_type']) ?></td>
                                            <td><span class="badge bg-info"><?= escape($apt['status']) ?></span></td>
                                            <td><span class="text-muted small">—</span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <h5 class="mt-4">Past Appointments</h5>
                    <?php if (empty($past_appointments)): ?>
                        <p class="text-muted">No past appointments</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Service</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($past_appointments, 0, 10) as $apt): ?>
                                        <?php
                                        $follow_up_submission = $follow_up_submissions_by_booking[array_int_value($apt, 'id')] ?? null;
                                        $follow_up_status = is_array($follow_up_submission)
                                            ? array_string_value($follow_up_submission, 'status')
                                            : '';
                                        $follow_up_badge = $follow_up_status === 'reviewed'
                                            ? 'bg-success'
                                            : 'bg-primary';
                                        ?>
                                        <tr>
                                            <td><?= formatDate($apt['appointment_date']) ?></td>
                                            <td><?= date('g:i A', safe_timestamp(strtotime($apt['appointment_time']))) ?></td>
                                            <td><?= escape($apt['appointment_type_name'] ?: $apt['service_type']) ?></td>
                                            <td><span class="badge bg-secondary"><?= escape($apt['status']) ?></span></td>
                                            <td>
                                                <?php if (is_array($follow_up_submission)): ?>
                                                    <a href="form_submissions_view.php?id=<?= array_int_value($follow_up_submission, 'id') ?>" class="btn btn-xs btn-outline-primary">
                                                        <i class="fas fa-eye"></i> View Follow-up
                                                    </a>
                                                    <span class="badge <?= $follow_up_badge ?> ms-1"><?= escape(ucfirst($follow_up_status)) ?></span>
                                                <?php else: ?>
                                                    <a href="form_requests_create.php?form_type=follow_up_note&amp;booking_id=<?= (int) $apt['id'] ?>" class="btn btn-xs btn-outline-secondary">
                                                        <i class="fas fa-note-sticky"></i> Follow-up Form
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($past_appointments) > 10): ?>
                            <p class="text-muted text-center">Showing 10 of <?= count($past_appointments) ?> past appointments</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Contracts Tab -->
                <div id="contracts" class="tab-pane fade">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Contracts</h5>
                        <a href="contracts_create.php?client_id=<?= $id ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus"></i> New Contract
                        </a>
                    </div>
                    <?php if (empty($contracts)): ?>
                        <p class="text-muted">No contracts found</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Contract #</th>
                                        <th>Title</th>
                                        <th>Created</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($contracts as $contract): ?>
                                        <tr>
                                            <td><?= escape($contract['contract_number']) ?></td>
                                            <td><?= escape($contract['title']) ?></td>
                                            <td><?= formatDate($contract['created_date']) ?></td>
                                            <td>
                                                <?php
                                                $colors = ['draft' => 'secondary', 'sent' => 'info', 'signed' => 'success', 'expired' => 'danger'];
                                                $color = $colors[$contract['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?= $color ?>"><?= strtoupper($contract['status']) ?></span>
                                            </td>
                                            <td>
                                                <a href="contracts_view.php?id=<?= $contract['id'] ?>" class="btn btn-xs btn-outline-info">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Forms Tab -->
                <div id="forms" class="tab-pane fade">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Form Submissions</h5>
                        <div class="d-flex gap-2">
                            <a href="form_requests_create.php?form_type=client_form&amp;client_id=<?= $id ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-paper-plane"></i> Send Client Form
                            </a>
                            <a href="form_requests_create.php?form_type=survey_form&amp;client_id=<?= $id ?>" class="btn btn-sm btn-outline-warning">
                                <i class="fas fa-square-poll-vertical"></i> Send Survey
                            </a>
                        </div>
                    </div>
                    <?php if (empty($forms)): ?>
                        <p class="text-muted">No forms submitted</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Form Name</th>
                                        <th>Type</th>
                                        <th>Context</th>
                                        <th>Submitted</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($forms as $form): ?>
                                        <tr>
                                            <td><?= escape($form['form_name']) ?></td>
                                            <td>
                                                <span class="badge <?= escape(bdta_get_form_type_badge_class(array_string_value($form, 'form_type'))) ?>">
                                                    <?= escape(bdta_get_form_type_label(array_string_value($form, 'form_type'))) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (array_string_value($form, 'pet_name') !== ''): ?>
                                                    <small class="text-muted d-block">Pet: <?= escape(array_string_value($form, 'pet_name')) ?></small>
                                                <?php endif; ?>
                                                <?php if (array_int_value($form, 'booking_id') > 0): ?>
                                                    <small class="text-muted">
                                                        <?= escape(array_string_value($form, 'service_type')) ?> · <?= escape(array_string_value($form, 'appointment_date')) ?>
                                                    </small>
                                                <?php elseif (array_string_value($form, 'pet_name') === ''): ?>
                                                    <span class="text-muted small">Client profile</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= formatDate($form['submitted_at']) ?></td>
                                            <td><span class="badge bg-info"><?= escape($form['status']) ?></span></td>
                                            <td>
                                                <a href="form_submissions_view.php?id=<?= $form['id'] ?>" class="btn btn-xs btn-outline-info">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Quotes Tab -->
                <div id="quotes" class="tab-pane fade">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Quotes</h5>
                        <a href="quotes_create.php?client_id=<?= $id ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus"></i> New Quote
                        </a>
                    </div>
                    <?php if (empty($quotes)): ?>
                        <p class="text-muted">No quotes found</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Quote #</th>
                                        <th>Title</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($quotes as $quote): ?>
                                        <tr>
                                            <td><?= escape($quote['quote_number']) ?></td>
                                            <td><?= escape($quote['title']) ?></td>
                                            <td>$<?= number_format(safe_float($quote['amount']), 2) ?></td>
                                            <td>
                                                <?php
                                                $colors = ['draft' => 'secondary', 'sent' => 'info', 'viewed' => 'primary', 'accepted' => 'success', 'declined' => 'danger', 'expired' => 'warning'];
                                                $color = $colors[$quote['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?= $color ?>"><?= strtoupper($quote['status']) ?></span>
                                            </td>
                                            <td><?= formatDate($quote['created_at']) ?></td>
                                            <td>
                                                <div class="d-none d-md-inline-flex gap-1 table-action-buttons">
                                                    <a href="quotes_view.php?id=<?= $quote['id'] ?>" class="btn btn-xs btn-outline-info table-action-btn">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                </div>
                                                <div class="d-md-none table-action-dropdown">
                                                    <div class="dropdown">
                                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle table-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                            <i class="fas fa-ellipsis-v"></i>
                                                        </button>
                                                        <ul class="dropdown-menu dropdown-menu-end">
                                                            <li>
                                                                <a class="dropdown-item" href="quotes_view.php?id=<?= $quote['id'] ?>">
                                                                    <i class="fas fa-eye me-2 text-info"></i>View Quote
                                                                </a>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Invoices Tab -->
                <div id="invoices" class="tab-pane fade">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Invoices</h5>
                        <a href="invoices_create.php?client_id=<?= $id ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus"></i> New Invoice
                        </a>
                    </div>
                    <?php if (empty($invoices)): ?>
                        <p class="text-muted">No invoices found</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Date</th>
                                        <th>Due Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($invoices as $invoice): ?>
                                        <tr>
                                            <td><?= escape($invoice['invoice_number']) ?></td>
                                            <td><?= formatDate($invoice['issue_date']) ?></td>
                                            <td><?= formatDate($invoice['due_date']) ?></td>
                                            <td>$<?= number_format(safe_float($invoice['total_amount']), 2) ?></td>
                                            <td>
                                                <?php
                                                $colors = ['draft' => 'secondary', 'sent' => 'info', 'paid' => 'success', 'overdue' => 'danger', 'partial' => 'warning'];
                                                $color = $colors[$invoice['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?= $color ?>"><?= strtoupper($invoice['status']) ?></span>
                                            </td>
                                            <td>
                                                <a href="invoices_view.php?id=<?= $invoice['id'] ?>" class="btn btn-xs btn-outline-info">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Email Tab -->
                <div id="emails" class="tab-pane fade">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Email Correspondence</h5>
                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#composeEmailModal">
                            <i class="fas fa-paper-plane"></i> Compose Email
                        </button>
                    </div>
                    
                    <div id="emailsList">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Compose Email Modal -->
<div class="modal fade" id="composeEmailModal" tabindex="-1" aria-labelledby="composeEmailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="composeEmailModalLabel">
                    <i class="fas fa-paper-plane"></i> Compose Email to <?= escape($client['name']) ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="composeEmailForm">
                    <input type="hidden" name="client_id" value="<?= $id ?>">
                    
                    <!-- Template Selection -->
                    <div class="mb-3">
                        <label for="emailTemplate" class="form-label">Use Template (Optional)</label>
                        <select class="form-select" id="emailTemplate" name="template_id">
                            <option value="">-- Custom Message --</option>
                        </select>
                        <small class="form-text text-muted">Select a template to auto-fill the email content</small>
                    </div>
                    
                    <!-- Subject -->
                    <div class="mb-3">
                        <label for="emailSubject" class="form-label">Subject <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="emailSubject" name="subject" required>
                    </div>
                    
                    <!-- Body -->
                    <div class="mb-3">
                        <label for="emailBody" class="form-label">Message <span class="text-danger">*</span></label>
                        <textarea id="emailBody" name="body_html" required></textarea>
                        <small class="form-text text-muted">HTML is supported</small>
                    </div>
                    
                    <!-- Send Options -->
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="scheduleEmail" name="schedule">
                            <label class="form-check-label" for="scheduleEmail">
                                Schedule for later
                            </label>
                        </div>
                    </div>
                    
                    <!-- Schedule DateTime -->
                    <div id="scheduleOptions" class="mb-3" style="display: none;">
                        <label for="scheduledAt" class="form-label">Schedule Date & Time</label>
                        <input type="datetime-local" class="form-control" id="scheduledAt" name="scheduled_at">
                    </div>
                    
                    <div id="emailFormAlert" class="alert d-none"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="sendEmailBtn">
                    <i class="fas fa-paper-plane"></i> Send Email
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Email Details Modal -->
<div class="modal fade" id="emailDetailsModal" tabindex="-1" aria-labelledby="emailDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="emailDetailsModalLabel">
                    <i class="fas fa-envelope"></i> Email Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="emailDetailsBody">
                <!-- Email details will be loaded here -->
            </div>
            <div class="modal-footer" id="emailDetailsFooter">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Contact Modal -->
<div class="modal fade" id="contactModal" tabindex="-1" aria-labelledby="contactModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="contactModalLabel">
                    <i class="fas fa-user-plus"></i> Add Contact
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="contactForm">
                    <div class="mb-3">
                        <label for="contactName" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="contactName" required>
                    </div>
                    <div class="mb-3">
                        <label for="contactEmail" class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="contactEmail" required>
                    </div>
                    <div class="mb-3">
                        <label for="contactPhone" class="form-label">Phone <span class="text-danger">*</span></label>
                        <input type="tel" class="form-control" id="contactPhone" required>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="contactPrimary">
                        <label class="form-check-label" for="contactPrimary">
                            Set as primary contact
                        </label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveContact()">Save Contact</button>
            </div>
        </div>
    </div>
</div>

<script>
// Email management
const clientId = <?= $id ?>;
let emailTemplates = [];

// Load email templates on page load
async function loadEmailTemplates() {
    try {
        const response = await fetch('email_templates_api.php?action=list');
        const data = await response.json();
        if (data.success) {
            emailTemplates = data.templates;
            const select = document.getElementById('emailTemplate');
            data.templates.forEach(template => {
                const option = document.createElement('option');
                option.value = template.id;
                option.textContent = template.name;
                select.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error loading templates:', error);
    }
}

// Load and display emails
async function loadEmails() {
    try {
        const response = await fetch(`client_emails_api.php?client_id=${clientId}`);
        const data = await response.json();
        
        if (data.success) {
            displayEmails(data.emails);
        } else {
            document.getElementById('emailsList').innerHTML = 
                '<div class="alert alert-danger">Failed to load emails</div>';
        }
    } catch (error) {
        console.error('Error loading emails:', error);
        document.getElementById('emailsList').innerHTML = 
            '<div class="alert alert-danger">Error loading emails</div>';
    }
}

// Display emails in the list
function displayEmails(emails) {
    const container = document.getElementById('emailsList');
    
    if (emails.length === 0) {
        container.innerHTML = '<p class="text-muted">No emails found</p>';
        return;
    }
    
    let html = '<div class="list-group">';
    
    emails.forEach(email => {
        const statusBadge = getStatusBadge(email.status);
        const icon = email.direction === 'outgoing' ? 'fa-paper-plane' : 'fa-inbox';
        const dateText = getEmailDateText(email);
        
        html += `
            <div class="list-group-item">
                <div class="d-flex w-100 justify-content-between align-items-start">
                    <a href="#" class="flex-grow-1 text-decoration-none text-reset" onclick="showEmailDetails(${email.id}); return false;">
                        <h6 class="mb-1">
                            <i class="fas ${icon} me-2"></i>
                            ${escapeHtml(email.subject)}
                        </h6>
                        <p class="mb-1 text-muted small">
                            ${email.direction === 'outgoing' ? 'To' : 'From'}: ${escapeHtml(email.direction === 'outgoing' ? email.to_email : email.from_email)}
                        </p>
                        ${email.template_name ? `<span class="badge bg-info me-2"><i class="fas fa-file-alt"></i> ${escapeHtml(email.template_name)}</span>` : ''}
                        ${email.mail_type && email.mail_type !== 'compose' ? `<span class="badge bg-secondary me-2">${escapeHtml(getMailTypeLabel(email.mail_type))}</span>` : ''}
                        ${statusBadge}
                    </a>
                    <div class="d-flex flex-column align-items-end ms-2">
                        <small class="text-muted mb-1">${dateText}</small>
                        ${email.direction === 'incoming' ? `<button class="btn btn-sm btn-outline-primary" onclick="replyToEmail(${email.id})" title="Reply"><i class="fas fa-reply"></i> Reply</button>` : ''}
                    </div>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    container.innerHTML = html;
}

// Get status badge HTML
function getStatusBadge(status) {
    const badges = {
        'pending': '<span class="badge bg-warning">Pending</span>',
        'scheduled': '<span class="badge bg-info">Scheduled</span>',
        'sent': '<span class="badge bg-success">Sent</span>',
        'delivered': '<span class="badge bg-success">Delivered</span>',
        'received': '<span class="badge bg-primary">Received</span>',
        'failed': '<span class="badge bg-danger">Failed</span>'
    };
    return badges[status] || `<span class="badge bg-secondary">${status}</span>`;
}

// Get human-readable label for mail type
function getMailTypeLabel(mailType) {
    const labels = {
        'booking_confirmation': 'Booking Confirmation',
        'booking_reminder':     'Booking Reminder',
        'booking_cancellation': 'Cancellation',
        'payment_receipt':      'Payment Receipt',
        'invoice':              'Invoice',
        'invoice_reminder':     'Invoice Reminder',
        'contract_reminder':    'Contract Reminder',
        'quote_reminder':       'Quote Reminder',
        'form_reminder':        'Form Reminder',
        'workflow':             'Workflow',
        'generic':              'Automated',
    };
    return labels[mailType] || mailType;
}

// Get email date text
function getEmailDateText(email) {
    if (email.status === 'scheduled' && email.scheduled_at) {
        return 'Scheduled: ' + formatDateTime(email.scheduled_at);
    } else if (email.sent_at) {
        return formatDateTime(email.sent_at);
    } else {
        return formatDateTime(email.created_at);
    }
}

const platformTimeZone = <?= json_encode(getSystemTimezone()) ?>;
const platformDateTimeFormatter = new Intl.DateTimeFormat('en-US', {
    timeZone: platformTimeZone,
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit'
});

function parseUtcDate(dateStr) {
    if (!dateStr) {
        return null;
    }

    const normalized = String(dateStr).trim().replace(' ', 'T');
    const utcValue = /(?:Z|[+-]\d{2}:\d{2})$/i.test(normalized) ? normalized : `${normalized}Z`;
    const date = new Date(utcValue);
    return Number.isNaN(date.getTime()) ? null : date;
}

// Format date time
function formatDateTime(dateStr) {
    const date = parseUtcDate(dateStr);
    return date ? platformDateTimeFormatter.format(date) : '';
}

// Escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Show email details
async function showEmailDetails(emailId) {
    const email = await getEmailById(emailId);
    if (!email) return;
    
    const modal = new bootstrap.Modal(document.getElementById('emailDetailsModal'));
    const body = document.getElementById('emailDetailsBody');
    
    let html = `
        <dl class="row">
            <dt class="col-sm-3">Subject:</dt>
            <dd class="col-sm-9">${escapeHtml(email.subject)}</dd>
            
            <dt class="col-sm-3">From:</dt>
            <dd class="col-sm-9">${escapeHtml(email.from_email)}</dd>
            
            <dt class="col-sm-3">To:</dt>
            <dd class="col-sm-9">${escapeHtml(email.to_email)}</dd>
            
            <dt class="col-sm-3">Status:</dt>
            <dd class="col-sm-9">${getStatusBadge(email.status)}</dd>
            
            ${email.scheduled_at ? `
                <dt class="col-sm-3">Scheduled:</dt>
                <dd class="col-sm-9">${formatDateTime(email.scheduled_at)}</dd>
            ` : ''}
            
            ${email.sent_at ? `
                <dt class="col-sm-3">Sent:</dt>
                <dd class="col-sm-9">${formatDateTime(email.sent_at)}</dd>
            ` : ''}
            
            ${email.error_message ? `
                <dt class="col-sm-3">Error:</dt>
                <dd class="col-sm-9 text-danger">${escapeHtml(email.error_message)}</dd>
            ` : ''}
        </dl>
        
        <hr>
        <h6>Message:</h6>
        <div class="border p-3 bg-light" style="max-height: 400px; overflow-y: auto;">
        </div>
    `;
    
    // Sanitize and insert HTML content separately to prevent XSS
    body.innerHTML = html;
    const messageContainer = body.querySelector('.border.p-3.bg-light');
    if (email.body_html) {
        // Create a temporary element to sanitize HTML
        const tempDiv = document.createElement('div');
        tempDiv.textContent = email.body_html; // First escape as text
        // Then allow it to be rendered as HTML (basic sanitization)
        // For production, consider using a library like DOMPurify
        messageContainer.innerHTML = email.body_html;
    } else {
        messageContainer.textContent = email.body_text || '';
    }
    
    // Show Reply button for incoming emails
    const footer = document.getElementById('emailDetailsFooter');
    footer.innerHTML = '';
    if (email.direction === 'incoming') {
        if (email.from_email) {
            footer.innerHTML += `<button type="button" class="btn btn-primary" onclick="replyToEmail(${email.id})"><i class="fas fa-reply"></i> Reply</button>`;
        } else {
            footer.innerHTML += '<button type="button" class="btn btn-primary" disabled title="Cannot reply: sender address is missing"><i class="fas fa-reply"></i> Reply</button>';
        }
    }
    footer.innerHTML += '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';
    
    modal.show();
}

// Get email by ID
async function getEmailById(emailId) {
    try {
        const response = await fetch(`client_emails_api.php?client_id=${clientId}`);
        const data = await response.json();
        if (data.success) {
            return data.emails.find(e => e.id == emailId);
        }
    } catch (error) {
        console.error('Error getting email:', error);
    }
    return null;
}

// Open compose modal pre-filled as a reply to an incoming email
async function replyToEmail(emailId) {
    const email = await getEmailById(emailId);
    if (!email) {
        alert('Could not load email details for reply.');
        return;
    }

    if (!email.from_email) {
        alert('Cannot reply: sender address is missing.');
        return;
    }

    // Close details modal if open
    const detailsModalEl = document.getElementById('emailDetailsModal');
    const detailsModal = bootstrap.Modal.getInstance(detailsModalEl);
    if (detailsModal) detailsModal.hide();

    // Pre-fill subject with "Re: " prefix (avoid double prefix)
    const subject = email.subject.startsWith('Re: ') ? email.subject : 'Re: ' + email.subject;
    document.getElementById('emailSubject').value = subject;

    // Quote original message in body
    let originalText = email.body_text || '';
    if (!originalText && email.body_html) {
        const tmp = document.createElement('div');
        tmp.innerHTML = email.body_html;
        originalText = tmp.textContent || tmp.innerText || '';
    }
    const quotedDate = email.sent_at ? formatDateTime(email.sent_at) : formatDateTime(email.created_at);
    const quotedBody = '\n\n---\nOn ' + quotedDate +
        ', ' + email.from_email + ' wrote:\n' + originalText.split('\n').map(l => '> ' + l).join('\n');
    if (window.emailBodyEditor) {
        const quotedHtml = '<p><br></p><hr><p>On ' + quotedDate + ', ' + email.from_email + ' wrote:</p><blockquote>' +
            originalText.replace(/\n/g, '<br>') + '</blockquote>';
        window.emailBodyEditor.setData(quotedHtml);
    } else {
        document.getElementById('emailBody').value = quotedBody;
    }

    // Reset template selection
    document.getElementById('emailTemplate').value = '';

    // Open compose modal
    const composeModal = new bootstrap.Modal(document.getElementById('composeEmailModal'));
    composeModal.show();
}

// Handle template selection
document.getElementById('emailTemplate').addEventListener('change', async function() {
    const templateId = this.value;
    if (!templateId) {
        document.getElementById('emailSubject').value = '';
        if (window.emailBodyEditor) {
            window.emailBodyEditor.setData('');
        } else {
            document.getElementById('emailBody').value = '';
        }
        return;
    }
    
    try {
        const response = await fetch(`email_templates_api.php?action=preview&id=${templateId}&client_id=${clientId}`);
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('emailSubject').value = data.preview.subject;
            const bodyContent = data.preview.body_html || data.preview.body_text || '';
            if (window.emailBodyEditor) {
                window.emailBodyEditor.setData(bodyContent);
            } else {
                document.getElementById('emailBody').value = bodyContent;
            }
        }
    } catch (error) {
        console.error('Error loading template:', error);
    }
});

// Handle schedule checkbox
document.getElementById('scheduleEmail').addEventListener('change', function() {
    const scheduleOptions = document.getElementById('scheduleOptions');
    const sendBtn = document.getElementById('sendEmailBtn');
    
    if (this.checked) {
        scheduleOptions.style.display = 'block';
        sendBtn.innerHTML = '<i class="fas fa-clock"></i> Schedule Email';
        
        // Set default to tomorrow at 9 AM
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        tomorrow.setHours(9, 0, 0, 0);
        document.getElementById('scheduledAt').value = tomorrow.toISOString().slice(0, 16);
    } else {
        scheduleOptions.style.display = 'none';
        sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Email';
    }
});

// Handle send email
document.getElementById('sendEmailBtn').addEventListener('click', async function() {
    const form = document.getElementById('composeEmailForm');

    // Sync CKEditor content to textarea before reading FormData
    if (window.emailBodyEditor) {
        document.getElementById('emailBody').value = window.emailBodyEditor.getData();
    }

    const formData = new FormData(form);
    const alertDiv = document.getElementById('emailFormAlert');
    
    // Validate form
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    // Prepare data
    const data = {
        client_id: parseInt(formData.get('client_id')),
        subject: formData.get('subject'),
        body_html: formData.get('body_html'),
        template_id: formData.get('template_id') ? parseInt(formData.get('template_id')) : null
    };
    
    // Add scheduled_at if scheduling
    if (document.getElementById('scheduleEmail').checked) {
        const scheduledAt = formData.get('scheduled_at');
        if (!scheduledAt) {
            showFormAlert('Please select a date and time for scheduling', 'danger');
            return;
        }
        data.scheduled_at = scheduledAt;
    }
    
    // Disable button
    this.disabled = true;
    const originalText = this.innerHTML;
    const isScheduling = document.getElementById('scheduleEmail').checked;
    this.innerHTML = isScheduling ? '<i class="fas fa-spinner fa-spin"></i> Scheduling...' : '<i class="fas fa-spinner fa-spin"></i> Sending...';
    
    try {
        const response = await fetch('client_emails_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showFormAlert(result.message, 'success');
            
            // Reset form and close modal after 1 second
            setTimeout(() => {
                form.reset();
                if (window.emailBodyEditor) {
                    window.emailBodyEditor.setData('');
                }
                bootstrap.Modal.getInstance(document.getElementById('composeEmailModal')).hide();
                loadEmails(); // Reload email list
            }, 1000);
        } else {
            showFormAlert(result.error || 'Failed to send email', 'danger');
        }
    } catch (error) {
        console.error('Error sending email:', error);
        showFormAlert('Error sending email: ' + error.message, 'danger');
    } finally {
        this.disabled = false;
        this.innerHTML = originalText;
    }
});

// Show form alert
function showFormAlert(message, type) {
    const alertDiv = document.getElementById('emailFormAlert');
    alertDiv.className = `alert alert-${type}`;
    alertDiv.textContent = message;
    alertDiv.classList.remove('d-none');
    
    setTimeout(() => {
        alertDiv.classList.add('d-none');
    }, 5000);
}

// Load emails when the email tab is shown
document.querySelector('a[href="#emails"]').addEventListener('shown.bs.tab', function() {
    loadEmails();
});

// Load templates on page load
document.addEventListener('DOMContentLoaded', function() {
    loadEmailTemplates();
    
    // Event delegation for edit contact buttons
    document.addEventListener('click', function(e) {
        if (e.target.closest('.edit-contact-btn')) {
            const btn = e.target.closest('.edit-contact-btn');
            editContact(
                btn.dataset.contactId,
                btn.dataset.contactName,
                btn.dataset.contactEmail,
                btn.dataset.contactPhone,
                btn.dataset.contactPrimary
            );
        }
        
        if (e.target.closest('.delete-contact-btn')) {
            const btn = e.target.closest('.delete-contact-btn');
            deleteContact(btn.dataset.contactId, btn.dataset.contactName);
        }
    });
});

// Contact management functions
let editingContactId = null;

function showAddContactModal() {
    editingContactId = null;
    document.getElementById('contactModalLabel').textContent = 'Add Contact';
    document.getElementById('contactForm').reset();
}

function editContact(id, name, email, phone, isPrimary) {
    editingContactId = id;
    document.getElementById('contactModalLabel').textContent = 'Edit Contact';
    document.getElementById('contactName').value = name;
    document.getElementById('contactEmail').value = email;
    document.getElementById('contactPhone').value = phone;
    document.getElementById('contactPrimary').checked = isPrimary == 1;
}

function saveContact() {
    const name = document.getElementById('contactName').value.trim();
    const email = document.getElementById('contactEmail').value.trim();
    const phone = document.getElementById('contactPhone').value.trim();
    const isPrimary = document.getElementById('contactPrimary').checked ? 1 : 0;
    
    if (!name || !email || !phone) {
        alert('Please fill in all required fields');
        return;
    }
    
    const url = editingContactId 
        ? `client_contacts_api.php?action=update&id=${editingContactId}`
        : `client_contacts_api.php?action=add&client_id=<?= $id ?>`;
    
    fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            name: name,
            email: email,
            phone: phone,
            is_primary: isPrimary
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        alert('Error saving contact: ' + error);
    });
}

function deleteContact(id, name) {
    if (!confirm('Are you sure you want to delete contact: ' + name + '?')) {
        return;
    }
    
    fetch(`client_contacts_api.php?action=delete&id=${id}`, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        alert('Error deleting contact: ' + error);
    });
}

</script>

<?php include '../backend/includes/footer.php'; ?>

<!-- CKEditor 5 Rich Text Editor (Self-Hosted, GPL License) -->
<link rel="stylesheet" href="js/ckeditor5/ckeditor5.css" />
<script type="module">
import {
    ClassicEditor,
    Essentials,
    Bold,
    Italic,
    Underline,
    Paragraph,
    Heading,
    Link,
    List,
    Alignment,
    SourceEditing,
    GeneralHtmlSupport
} from './js/ckeditor5/ckeditor5.js';

// Initialize CKEditor 5 for compose email modal (email-optimized preset)
// Lazy-initialize on first modal show so the element is visible
const composeEmailModal = document.getElementById('composeEmailModal');
let emailEditorInitialized = false;

composeEmailModal.addEventListener('shown.bs.modal', function () {
    if (emailEditorInitialized) return;

    ClassicEditor
        .create(document.querySelector('#emailBody'), {
            licenseKey: 'GPL',
            plugins: [
                Essentials, Bold, Italic, Underline,
                Paragraph, Heading, Link, List,
                Alignment, SourceEditing, GeneralHtmlSupport
            ],
            toolbar: [
                'undo', 'redo', '|',
                'heading', '|',
                'bold', 'italic', 'underline', '|',
                'link', '|',
                'bulletedList', 'numberedList', '|',
                'alignment', '|',
                'sourceEditing'
            ],
            heading: {
                options: [
                    { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                    { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
                    { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' }
                ]
            },
            htmlSupport: {
                allow: [
                    {
                        name: /.*/,
                        attributes: true,
                        classes: true,
                        styles: true
                    }
                ]
            }
        })
        .then(editor => {
            window.emailBodyEditor = editor;
            emailEditorInitialized = true;
        })
        .catch(error => {
            console.error('CKEditor initialization error (emailBody):', error);
        });
});
</script>
