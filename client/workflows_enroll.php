<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';
require_once '../backend/includes/workflow_helper.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();
$workflow_helper = new WorkflowHelper($conn);

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

// Handle enrollment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll'])) {
    $client_ids = $_POST['client_ids'] ?? [];
    $enrolled_count = 0;
    $errors = [];
    
    foreach ($client_ids as $client_id) {
        $result = $workflow_helper->enrollClient($workflow_id, $client_id, $_SESSION['admin_id']);
        if ($result['success']) {
            $enrolled_count++;
        } else {
            $errors[] = "Client ID {$client_id}: " . $result['message'];
        }
    }
    
    if ($enrolled_count > 0) {
        $_SESSION['success'] = "Successfully enrolled {$enrolled_count} client(s) in workflow";
    }
    if (!empty($errors)) {
        $_SESSION['warning'] = implode('<br>', $errors);
    }
    
    header('Location: workflows_list.php');
    exit;
}

// Get all clients
$clients = $conn->query("
    SELECT c.*, 
           (SELECT COUNT(*) FROM workflow_enrollments 
            WHERE client_id = c.id AND workflow_id = {$workflow_id} AND status = 'active') as is_enrolled
    FROM clients c
    ORDER BY c.name
")->fetchAll(PDO::FETCH_ASSOC);

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

            <h2 class="mb-4">
                <i class="fas fa-user-plus me-2"></i>
                Enroll Clients in "<?php echo htmlspecialchars($workflow['name']); ?>"
            </h2>

            <?php if ($workflow['description']): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <?php echo htmlspecialchars($workflow['description']); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Select Clients to Enroll</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($clients)): ?>
                            <div class="alert alert-warning">
                                No clients found. Please add clients before enrolling them in workflows.
                            </div>
                        <?php else: ?>
                            <div class="mb-3">
                                <input type="text" class="form-control" id="searchClients" 
                                       placeholder="Search clients...">
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-hover" id="clientsTable">
                                    <thead>
                                        <tr>
                                            <th width="50">
                                                <input type="checkbox" id="selectAll">
                                            </th>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($clients as $client): ?>
                                            <tr>
                                                <td>
                                                    <?php if ($client['is_enrolled']): ?>
                                                        <input type="checkbox" disabled title="Already enrolled">
                                                    <?php else: ?>
                                                        <input type="checkbox" name="client_ids[]" 
                                                               value="<?php echo $client['id']; ?>" 
                                                               class="client-checkbox">
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($client['name']); ?></td>
                                                <td><?php echo htmlspecialchars($client['email']); ?></td>
                                                <td>
                                                    <?php if ($client['is_enrolled']): ?>
                                                        <span class="badge bg-success">Already Enrolled</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Not Enrolled</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($clients)): ?>
                        <div class="card-footer">
                            <button type="submit" name="enroll" class="btn btn-primary">
                                <i class="fas fa-user-plus"></i> Enroll Selected Clients
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Select all checkbox
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.client-checkbox').forEach(cb => {
        cb.checked = this.checked;
    });
});

// Search functionality
document.getElementById('searchClients')?.addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const rows = document.querySelectorAll('#clientsTable tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});
</script>

<?php include '../backend/includes/footer.php'; ?>
