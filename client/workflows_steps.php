<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

$workflow_id = isset($_GET['workflow_id']) ? (int)$_GET['workflow_id'] : 0;

// Get workflow details
$stmt = $conn->prepare("SELECT * FROM workflows WHERE id = ?");
$stmt->execute([$workflow_id]);
$workflow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$workflow) {
    $_SESSION['error'] = 'Workflow not found';
    header('Location: workflows_list.php');
    exit;
}

// Get workflow steps
$steps = $conn->prepare("SELECT * FROM workflow_steps WHERE workflow_id = ? ORDER BY step_order");
$steps->execute([$workflow_id]);
$workflow_steps = $steps->fetchAll(PDO::FETCH_ASSOC);

// Handle delete step
if (isset($_GET['delete_step'])) {
    $step_id = (int)$_GET['delete_step'];
    $stmt = $conn->prepare("DELETE FROM workflow_steps WHERE id = ? AND workflow_id = ?");
    $stmt->execute([$step_id, $workflow_id]);
    $_SESSION['success'] = 'Step deleted successfully';
    header('Location: workflows_steps.php?workflow_id=' . $workflow_id);
    exit;
}

// Get options for dropdowns
$contract_templates = $conn->query("SELECT id, name FROM contract_templates ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$form_templates = $conn->query("SELECT id, name FROM form_templates ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$appointment_types = $conn->query("SELECT id, name FROM appointment_types WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

include '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="mb-4">
                <a href="workflows_list.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Workflows
                </a>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-1">
                        Workflow Steps: <?php echo htmlspecialchars($workflow['name']); ?>
                    </h2>
                    <?php if ($workflow['description']): ?>
                        <p class="text-muted"><?php echo htmlspecialchars($workflow['description']); ?></p>
                    <?php endif; ?>
                </div>
                <a href="workflows_steps_edit.php?workflow_id=<?php echo $workflow_id; ?>" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Step
                </a>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (empty($workflow_steps)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-circle-info"></i>
                    No steps defined yet. Add your first email step to start building your workflow sequence.
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($workflow_steps as $index => $step): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card h-100">
                                <div class="card-header bg-light">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong>Step <?php echo $step['step_order']; ?></strong>
                                        <span class="badge bg-primary">
                                            <?php 
                                            switch($step['delay_type']) {
                                                case 'immediate': echo 'Immediate'; break;
                                                case 'after_enrollment': echo 'After Enrollment'; break;
                                                case 'after_previous': echo 'After Previous'; break;
                                                case 'specific_date': echo 'Specific Date'; break;
                                                default: echo ucfirst($step['delay_type']);
                                            }
                                            ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <h5 class="card-title"><?php echo htmlspecialchars($step['step_name']); ?></h5>
                                    <p class="text-muted small mb-2">
                                        <strong>Subject:</strong> <?php echo htmlspecialchars($step['email_subject']); ?>
                                    </p>
                                    
                                    <?php if ($step['delay_value']): ?>
                                        <p class="text-muted small mb-2">
                                            <strong>Delay:</strong> <?php echo htmlspecialchars($step['delay_value']); ?>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <?php if ($step['scheduled_date']): ?>
                                        <p class="text-muted small mb-2">
                                            <strong>Date:</strong> <?php echo htmlspecialchars($step['scheduled_date']); ?>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <!-- Attachments -->
                                    <?php if ($step['attach_contract_id'] || $step['attach_form_id'] || 
                                              $step['attach_quote_id'] || $step['attach_invoice_id'] || 
                                              $step['include_appointment_link']): ?>
                                        <div class="mt-2">
                                            <small class="text-muted"><strong>Includes:</strong></small>
                                            <div class="d-flex flex-wrap gap-1 mt-1">
                                                <?php if ($step['attach_contract_id']): ?>
                                                    <span class="badge bg-info">Contract</span>
                                                <?php endif; ?>
                                                <?php if ($step['attach_form_id']): ?>
                                                    <span class="badge bg-secondary">Form</span>
                                                <?php endif; ?>
                                                <?php if ($step['attach_quote_id']): ?>
                                                    <span class="badge bg-primary">Quote</span>
                                                <?php endif; ?>
                                                <?php if ($step['attach_invoice_id']): ?>
                                                    <span class="badge bg-success">Invoice</span>
                                                <?php endif; ?>
                                                <?php if ($step['include_appointment_link']): ?>
                                                    <span class="badge bg-warning">Appointment Link</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="card-footer bg-light">
                                    <div class="d-flex gap-2">
                                        <a href="workflows_steps_edit.php?workflow_id=<?php echo $workflow_id; ?>&step_id=<?php echo $step['id']; ?>" 
                                           class="btn btn-sm btn-outline-primary flex-fill">
                                            <i class="fas fa-pencil"></i> Edit
                                        </a>
                                        <a href="?workflow_id=<?php echo $workflow_id; ?>&delete_step=<?php echo $step['id']; ?>" 
                                           class="btn btn-sm btn-outline-danger"
                                           onclick="return confirm('Are you sure you want to delete this step?')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0">Workflow Actions</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <a href="workflows_enroll.php?workflow_id=<?php echo $workflow_id; ?>" class="btn btn-success w-100">
                                <i class="fas fa-user-plus"></i> Enroll Clients
                            </a>
                        </div>
                        <div class="col-md-4">
                            <a href="workflows_enrollments.php?workflow_id=<?php echo $workflow_id; ?>" class="btn btn-info w-100">
                                <i class="fas fa-list"></i> View Enrollments
                            </a>
                        </div>
                        <div class="col-md-4">
                            <a href="workflows_edit.php?id=<?php echo $workflow_id; ?>" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-cog"></i> Workflow Settings
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
