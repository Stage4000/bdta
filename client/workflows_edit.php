<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

$workflow_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_edit = $workflow_id > 0;

// Get workflow if editing
$workflow = null;
if ($is_edit) {
    $stmt = $conn->prepare("SELECT * FROM workflows WHERE id = ?");
    $stmt->execute([$workflow_id]);
    $workflow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$workflow) {
        $_SESSION['error'] = 'Workflow not found';
        header('Location: workflows_list.php');
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($name)) {
        $error = 'Workflow name is required';
    } else {
        if ($is_edit) {
            // Update existing workflow
            $stmt = $conn->prepare("
                UPDATE workflows 
                SET name = ?, description = ?, is_active = ?, updated_at = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $description, $is_active, date('Y-m-d H:i:s'), $workflow_id]);
            $_SESSION['success'] = 'Workflow updated successfully';
        } else {
            // Create new workflow
            $stmt = $conn->prepare("
                INSERT INTO workflows (name, description, is_active)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$name, $description, $is_active]);
            $workflow_id = $conn->lastInsertId();
            $_SESSION['success'] = 'Workflow created successfully';
        }
        
        header('Location: workflows_steps.php?workflow_id=' . $workflow_id);
        exit;
    }
}

include '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="mb-4">
                <a href="workflows_list.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Workflows
                </a>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="mb-0">
                        <?php echo $is_edit ? 'Edit Workflow' : 'Create New Workflow'; ?>
                    </h3>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label for="name" class="form-label">Workflow Name *</label>
                            <input type="text" class="form-control" id="name" name="name" 
                                   value="<?php echo htmlspecialchars($workflow['name'] ?? ''); ?>" 
                                   required>
                            <small class="form-text text-muted">
                                Give your workflow a descriptive name (e.g., "New Client Welcome Series")
                            </small>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" 
                                      rows="3"><?php echo htmlspecialchars($workflow['description'] ?? ''); ?></textarea>
                            <small class="form-text text-muted">
                                Describe the purpose of this workflow
                            </small>
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="is_active" name="is_active" 
                                   <?php echo (!$is_edit || $workflow['is_active']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">
                                Active (workflow will process enrollments)
                            </label>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" name="save" class="btn btn-primary">
                                <i class="fas fa-save"></i>
                                <?php echo $is_edit ? 'Update Workflow' : 'Create Workflow'; ?>
                            </button>
                            <a href="workflows_list.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($is_edit): ?>
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0">Next Steps</h5>
                    </div>
                    <div class="card-body">
                        <p>After saving your workflow details:</p>
                        <ol>
                            <li>
                                <strong>Add Steps:</strong> 
                                <a href="workflows_steps.php?workflow_id=<?php echo $workflow_id; ?>">
                                    Configure the email sequence
                                </a>
                            </li>
                            <li>
                                <strong>Set Triggers:</strong> Configure automatic enrollment based on appointments or forms
                            </li>
                            <li>
                                <strong>Enroll Clients:</strong> 
                                <a href="workflows_enroll.php?workflow_id=<?php echo $workflow_id; ?>">
                                    Manually add clients to this workflow
                                </a>
                            </li>
                        </ol>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
