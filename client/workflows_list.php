<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Get all workflows with enrollment counts
$workflows = $conn->query("
    SELECT w.*, 
           COUNT(DISTINCT we.id) as enrollment_count,
           COUNT(DISTINCT CASE WHEN we.status = 'active' THEN we.id END) as active_enrollments
    FROM workflows w
    LEFT JOIN workflow_enrollments we ON w.id = we.workflow_id
    GROUP BY w.id
    ORDER BY w.name
")->fetchAll(PDO::FETCH_ASSOC);

include '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0"><i class="fas fa-diagram-project me-2"></i>Automated Workflows</h2>
                <a href="workflows_edit.php" class="btn btn-primary">
                    <i class="fas fa-circle-plus"></i> New Workflow
                </a>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (empty($workflows)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-circle-info"></i>
                    No workflows created yet. Create your first automated workflow to streamline client communications.
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($workflows as $workflow): ?>
                        <div class="col-md-6 col-lg-4 mb-4">
                            <div class="card h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <h5 class="card-title mb-0">
                                            <?php echo htmlspecialchars($workflow['name']); ?>
                                        </h5>
                                        <?php if ($workflow['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if ($workflow['description']): ?>
                                        <p class="text-muted small">
                                            <?php echo htmlspecialchars($workflow['description']); ?>
                                        </p>
                                    <?php endif; ?>
                                    
                                    <div class="row mt-3">
                                        <div class="col-6">
                                            <div class="text-center">
                                                <h4 class="mb-0">
                                                    <?php 
                                                    // Get step count
                                                    $stmt = $conn->prepare("SELECT COUNT(*) FROM workflow_steps WHERE workflow_id = ?");
                                                    $stmt->execute([$workflow['id']]);
                                                    echo $stmt->fetchColumn(); 
                                                    ?>
                                                </h4>
                                                <small class="text-muted">Steps</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="text-center">
                                                <h4 class="mb-0 text-primary"><?php echo $workflow['active_enrollments']; ?></h4>
                                                <small class="text-muted">Active Enrollments</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex gap-2 mt-3">
                                        <a href="workflows_edit.php?id=<?php echo $workflow['id']; ?>" 
                                           class="btn btn-sm btn-outline-primary flex-fill">
                                            <i class="fas fa-pencil"></i> Edit
                                        </a>
                                        <a href="workflows_enroll.php?workflow_id=<?php echo $workflow['id']; ?>" 
                                           class="btn btn-sm btn-outline-success flex-fill">
                                            <i class="fas fa-user-plus"></i> Enroll
                                        </a>
                                        <a href="workflows_enrollments.php?workflow_id=<?php echo $workflow['id']; ?>" 
                                           class="btn btn-sm btn-outline-info">
                                            <i class="fas fa-list"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Info Box -->
            <div class="card mt-4 border-info">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>About Automated Workflows</h5>
                </div>
                <div class="card-body">
                    <p>Automated workflows allow you to create sequences of emails that are automatically sent to clients based on time delays or specific dates.</p>
                    
                    <h6>Features:</h6>
                    <ul>
                        <li><strong>Email Sequences:</strong> Create multi-step email campaigns</li>
                        <li><strong>Attachments:</strong> Include contracts, forms, quotes, invoices, or appointment links</li>
                        <li><strong>Time-Based:</strong> Send emails based on delays (e.g., 3 days after enrollment)</li>
                        <li><strong>Date-Based:</strong> Send emails on specific dates</li>
                        <li><strong>Auto-Enrollment:</strong> Automatically enroll clients when they book appointments or complete forms</li>
                    </ul>
                    
                    <h6>Use Cases:</h6>
                    <ul>
                        <li>Welcome series for new clients</li>
                        <li>Pre-appointment preparation sequences</li>
                        <li>Post-service follow-ups</li>
                        <li>Educational email campaigns</li>
                        <li>Re-engagement campaigns</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
