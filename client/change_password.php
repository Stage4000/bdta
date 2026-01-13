<?php
/**
 * Change Password - Admin users can change their password
 */

require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = 'All fields are required.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'New passwords do not match.';
    } elseif (strlen($new_password) < 8) {
        $error = 'New password must be at least 8 characters long.';
    } else {
        // Check if user is from admin_users or clients table
        if (!isset($_SESSION['user_type'])) {
            $error = 'Invalid session. Please log in again.';
        } else {
            $user_type = $_SESSION['user_type'];
            
            if ($user_type === 'client') {
                // Client user - check clients table
                $stmt = $conn->prepare("SELECT password_hash FROM clients WHERE id = ?");
                $stmt->execute([$_SESSION['admin_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && password_verify($current_password, $user['password_hash'])) {
                    // Update password
                    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE clients SET password_hash = ? WHERE id = ?");
                    $stmt->execute([$new_password_hash, $_SESSION['admin_id']]);
                    
                    setFlashMessage('Password changed successfully!', 'success');
                    redirect('index.php');
                } else {
                    $error = 'Current password is incorrect.';
                }
            } else {
                // Admin user - check admin_users table
                $stmt = $conn->prepare("SELECT password_hash FROM admin_users WHERE id = ?");
                $stmt->execute([$_SESSION['admin_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && password_verify($current_password, $user['password_hash'])) {
                    // Update password
                    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?");
                    $stmt->execute([$new_password_hash, $_SESSION['admin_id']]);
                    
                    setFlashMessage('Password changed successfully!', 'success');
                    redirect('index.php');
                } else {
                    $error = 'Current password is incorrect.';
                }
            }
        }
    }
}

$page_title = 'Change Password';
include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-6 offset-md-3">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="bi bi-key me-2"></i>Change Password</h2>
                <a href="index.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                </a>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?= escape($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?= escape($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password *</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required autofocus>
                        </div>

                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password *</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                            <small class="form-text text-muted">Must be at least 8 characters long.</small>
                        </div>

                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm New Password *</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8">
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="alert alert-info mt-3">
                <i class="bi bi-info-circle"></i> <strong>Security Tips:</strong>
                <ul class="mb-0 mt-2">
                    <li>Use a strong password with a mix of letters, numbers, and symbols</li>
                    <li>Don't reuse passwords from other accounts</li>
                    <li>Consider using a password manager</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
