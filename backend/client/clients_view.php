<?php
require_once '../includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    setFlashMessage('Invalid client ID!', 'danger');
    redirect('clients_list.php');
}

// Get client details
$stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    setFlashMessage('Client not found!', 'danger');
    redirect('clients_list.php');
}

// Get client's pets
$stmt = $conn->prepare("SELECT * FROM pets WHERE client_id = ? ORDER BY name");
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
    SELECT fs.*, ft.name as form_name
    FROM form_submissions fs
    JOIN form_templates ft ON fs.template_id = ft.id
    WHERE fs.client_id = ?
    ORDER BY fs.submitted_at DESC
");
$stmt->execute([$id]);
$forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get quotes
$stmt = $conn->prepare("SELECT * FROM quotes WHERE client_id = ? ORDER BY created_at DESC");
$stmt->execute([$id]);
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get invoices
$stmt = $conn->prepare("SELECT * FROM invoices WHERE client_id = ? ORDER BY created_at DESC");
$stmt->execute([$id]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get credit balance
$stmt = $conn->prepare("SELECT * FROM client_credits WHERE client_id = ?");
$stmt->execute([$id]);
$credits = $stmt->fetch(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-person-circle me-2"></i><?= escape($client['name']) ?></h2>
        <div>
            <a href="clients_edit.php?id=<?= $id ?>" class="btn btn-primary me-2">
                <i class="bi bi-pencil"></i> Edit Client
            </a>
            <a href="bookings_create.php?client_id=<?= $id ?>" class="btn btn-success me-2">
                <i class="bi bi-calendar-plus"></i> New Booking
            </a>
            <a href="clients_list.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Back
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

    <div class="row">
        <!-- Client Info Card -->
        <div class="col-md-4 mb-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Client Information</h5>
                </div>
                <div class="card-body">
                    <dl class="mb-0">
                        <dt>Email:</dt>
                        <dd><a href="mailto:<?= escape($client['email']) ?>"><?= escape($client['email']) ?></a></dd>
                        
                        <dt>Phone:</dt>
                        <dd><?= escape($client['phone'] ?: 'Not provided') ?></dd>
                        
                        <dt>Address:</dt>
                        <dd><?= escape($client['address'] ?: 'Not provided') ?></dd>
                        
                        <dt>Member Since:</dt>
                        <dd><?= formatDate($client['created_at']) ?></dd>
                        
                        <?php if ($credits): ?>
                            <dt>Credit Balance:</dt>
                            <dd>
                                <span class="badge bg-<?= $credits['credit_balance'] > 0 ? 'success' : 'secondary' ?> fs-6">
                                    <?= $credits['credit_balance'] ?> credits
                                </span>
                                <div class="mt-2">
                                    <a href="credits_manage.php?client_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-wallet2"></i> Manage Credits
                                    </a>
                                </div>
                            </dd>
                        <?php endif; ?>
                        
                        <?php if ($client['notes']): ?>
                            <dt>Notes:</dt>
                            <dd><?= nl2br(escape($client['notes'])) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>

            <!-- Pets Card -->
            <div class="card mt-3">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-heart me-2"></i>Pets</h5>
                    <a href="pets_edit.php?client_id=<?= $id ?>" class="btn btn-sm btn-light">
                        <i class="bi bi-plus"></i> Add Pet
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
                                <a href="pets_edit.php?id=<?= $pet['id'] ?>" class="btn btn-xs btn-outline-secondary mt-1">
                                    <i class="bi bi-pencil"></i> Edit
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
                        <i class="bi bi-calendar-check"></i> Appointments 
                        <span class="badge bg-primary"><?= count($appointments) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="tab" href="#contracts">
                        <i class="bi bi-file-earmark-text"></i> Contracts 
                        <span class="badge bg-secondary"><?= count($contracts) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="tab" href="#forms">
                        <i class="bi bi-list-check"></i> Forms 
                        <span class="badge bg-secondary"><?= count($forms) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="tab" href="#quotes">
                        <i class="bi bi-file-earmark-ruled"></i> Quotes 
                        <span class="badge bg-secondary"><?= count($quotes) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="tab" href="#invoices">
                        <i class="bi bi-receipt"></i> Invoices 
                        <span class="badge bg-secondary"><?= count($invoices) ?></span>
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
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($upcoming_appointments as $apt): ?>
                                        <tr>
                                            <td><?= formatDate($apt['appointment_date']) ?></td>
                                            <td><?= date('g:i A', strtotime($apt['appointment_time'])) ?></td>
                                            <td><?= escape($apt['appointment_type_name'] ?: $apt['service_type']) ?></td>
                                            <td><span class="badge bg-info"><?= escape($apt['status']) ?></span></td>
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
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($past_appointments, 0, 10) as $apt): ?>
                                        <tr>
                                            <td><?= formatDate($apt['appointment_date']) ?></td>
                                            <td><?= date('g:i A', strtotime($apt['appointment_time'])) ?></td>
                                            <td><?= escape($apt['appointment_type_name'] ?: $apt['service_type']) ?></td>
                                            <td><span class="badge bg-secondary"><?= escape($apt['status']) ?></span></td>
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
                            <i class="bi bi-plus"></i> New Contract
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
                                                    <i class="bi bi-eye"></i> View
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
                    <h5>Form Submissions</h5>
                    <?php if (empty($forms)): ?>
                        <p class="text-muted">No forms submitted</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Form Name</th>
                                        <th>Submitted</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($forms as $form): ?>
                                        <tr>
                                            <td><?= escape($form['form_name']) ?></td>
                                            <td><?= formatDate($form['submitted_at']) ?></td>
                                            <td><span class="badge bg-info"><?= escape($form['status']) ?></span></td>
                                            <td>
                                                <a href="form_submissions_view.php?id=<?= $form['id'] ?>" class="btn btn-xs btn-outline-info">
                                                    <i class="bi bi-eye"></i> View
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
                            <i class="bi bi-plus"></i> New Quote
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
                                            <td>$<?= number_format($quote['amount'], 2) ?></td>
                                            <td>
                                                <?php
                                                $colors = ['draft' => 'secondary', 'sent' => 'info', 'viewed' => 'primary', 'accepted' => 'success', 'declined' => 'danger', 'expired' => 'warning'];
                                                $color = $colors[$quote['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?= $color ?>"><?= strtoupper($quote['status']) ?></span>
                                            </td>
                                            <td><?= formatDate($quote['created_at']) ?></td>
                                            <td>
                                                <a href="quotes_view.php?id=<?= $quote['id'] ?>" class="btn btn-xs btn-outline-info">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
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
                            <i class="bi bi-plus"></i> New Invoice
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
                                            <td>$<?= number_format($invoice['total_amount'], 2) ?></td>
                                            <td>
                                                <?php
                                                $colors = ['draft' => 'secondary', 'sent' => 'info', 'paid' => 'success', 'overdue' => 'danger', 'partial' => 'warning'];
                                                $color = $colors[$invoice['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?= $color ?>"><?= strtoupper($invoice['status']) ?></span>
                                            </td>
                                            <td>
                                                <a href="invoices_view.php?id=<?= $invoice['id'] ?>" class="btn btn-xs btn-outline-info">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
