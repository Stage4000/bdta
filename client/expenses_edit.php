<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$expense = null;

if ($id > 0) {
    $stmt = $conn->prepare("SELECT * FROM expenses WHERE id = ?");
    $stmt->execute([$id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);
}

$clients_stmt = $conn->query("SELECT id, name FROM clients ORDER BY name");
$clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = !empty($_POST['client_id']) ? intval($_POST['client_id']) : null;
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $expense_date = trim($_POST['expense_date'] ?? '');
    $billable = isset($_POST['billable']) ? 1 : 0;
    $notes = trim($_POST['notes'] ?? '');
    
    $receipt_file = null;
    
    // Handle file upload
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../uploads/receipts/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'pdf', 'gif'];
        
        if (in_array($file_extension, $allowed_extensions)) {
            $file_name = uniqid('receipt_') . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['receipt']['tmp_name'], $file_path)) {
                $receipt_file = $file_name;
            }
        }
    }
    
    if (empty($category) || empty($description) || $amount <= 0 || empty($expense_date)) {
        setFlashMessage('All required fields must be filled!', 'danger');
    } else {
        if ($id > 0) {
            // If new file uploaded, delete old one
            if ($receipt_file && $expense['receipt_file']) {
                $old_file = __DIR__ . '/../uploads/receipts/' . $expense['receipt_file'];
                if (file_exists($old_file)) {
                    unlink($old_file);
                }
            }
            
            $stmt = $conn->prepare("
                UPDATE expenses 
                SET client_id = ?, category = ?, description = ?, amount = ?, 
                    expense_date = ?, billable = ?, notes = ?, receipt_file = ?
                WHERE id = ?
            ");
            $stmt->execute([$client_id, $category, $description, $amount, $expense_date, $billable, $notes, 
                           $receipt_file ?: $expense['receipt_file'], $id]);
            setFlashMessage('Expense updated successfully!', 'success');
        } else {
            $stmt = $conn->prepare("
                INSERT INTO expenses (client_id, category, description, amount, expense_date, billable, notes, receipt_file) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$client_id, $category, $description, $amount, $expense_date, $billable, $notes, $receipt_file]);
            setFlashMessage('Expense created successfully!', 'success');
        }
        redirect('expenses_list.php');
    }
}

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><?= $id > 0 ? 'Edit Expense' : 'Add Expense' ?></h2>
                <a href="expenses_list.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>

            <div class="card">
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="category" class="form-label">Category *</label>
                                <input type="text" class="form-control" id="category" name="category" 
                                       value="<?= escape($expense['category'] ?? '') ?>" 
                                       placeholder="e.g., Supplies, Travel" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="amount" class="form-label">Amount ($) *</label>
                                <input type="number" step="0.01" class="form-control" id="amount" name="amount" 
                                       value="<?= $expense['amount'] ?? '' ?>" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="expense_date" class="form-label">Expense Date *</label>
                                <input type="date" class="form-control" id="expense_date" name="expense_date" 
                                       value="<?= $expense['expense_date'] ?? date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="client_id" class="form-label">Client (Optional)</label>
                                <select class="form-select" id="client_id" name="client_id">
                                    <option value="">General / No Client</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= $client['id'] ?>" 
                                            <?= ($expense['client_id'] ?? 0) == $client['id'] ? 'selected' : '' ?>>
                                            <?= escape($client['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description *</label>
                            <input type="text" class="form-control" id="description" name="description" 
                                   value="<?= escape($expense['description'] ?? '') ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"><?= escape($expense['notes'] ?? '') ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="receipt" class="form-label">Receipt Upload</label>
                            <?php if ($expense && $expense['receipt_file']): ?>
                                <div class="mb-2">
                                    <p class="text-muted">Current receipt:</p>
                                    <?php
                                    $ext = strtolower(pathinfo($expense['receipt_file'], PATHINFO_EXTENSION));
                                    $receipt_path = '../uploads/receipts/' . $expense['receipt_file'];
                                    ?>
                                    <?php if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                        <a href="<?= $receipt_path ?>" target="_blank">
                                            <img src="<?= $receipt_path ?>" alt="Receipt" class="img-thumbnail" style="max-width: 200px;">
                                        </a>
                                    <?php else: ?>
                                        <a href="<?= $receipt_path ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-file-earmark-pdf"></i> View Receipt PDF
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <input type="file" class="form-control" id="receipt" name="receipt" accept="image/*,application/pdf">
                            <small class="text-muted">Accepted formats: JPG, PNG, PDF (Max 5MB)</small>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="billable" name="billable" 
                                   <?= ($expense['billable'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="billable">
                                Billable to Client
                            </label>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> <?= $id > 0 ? 'Update Expense' : 'Create Expense' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
