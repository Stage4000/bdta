<?php
/**
 * Client Password Management - Set/Reset password for a client
 */

require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
$error = '';
$success = '';

if ($client_id === 0) {
    setFlashMessage('Invalid client ID!', 'danger');
    redirect('clients_list.php');
}

// Get client details
$stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    setFlashMessage('Client not found!', 'danger');
    redirect('clients_list.php');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($new_password) || empty($confirm_password)) {
        $error = 'All fields are required.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($new_password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } else {
        // Set password
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE clients SET password_hash = ? WHERE id = ?");
        $stmt->execute([$password_hash, $client_id]);
        
        setFlashMessage('Password set successfully for ' . escape($client['name']) . '!', 'success');
        redirect('clients_edit.php?id=' . $client_id);
    }
}

$page_title = 'Set Client Password';
include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-6 offset-md-3">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-key me-2"></i>Set Client Password</h2>
                <a href="clients_edit.php?id=<?= $client_id ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Client
                </a>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?= escape($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> Setting a password for <strong><?= escape($client['name']) ?></strong>
                    </div>

                    <form method="POST">
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password *</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8" autofocus>
                            <small class="form-text text-muted">Must be at least 8 characters long.</small>
                        </div>

                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm Password *</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8">
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Set Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="alert alert-warning mt-3">
                <i class="bi bi-exclamation-triangle"></i> <strong>Note:</strong>
                <ul class="mb-0 mt-2">
                    <li>The client can use their <strong>email (<?= escape($client['email']) ?>)</strong> and this password to log in</li>
                    <li>Make sure to communicate this password securely to the client</li>
                    <li>The client must have admin access enabled to log in to the system</li>
                    <li>They can change their password after logging in</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
