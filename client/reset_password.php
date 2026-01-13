<?php
/**
 * Reset Password - Set new password using reset token
 */

require_once '../backend/includes/config.php';

$error = '';
$success = '';
$token = $_GET['token'] ?? '';
$valid_token = false;
$client = null;

if (empty($token)) {
    $error = 'Invalid password reset link.';
} else {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Verify token and check expiration
    $stmt = $conn->prepare("SELECT id, name, email FROM clients WHERE password_reset_token = ? AND password_reset_expires > datetime('now')");
    $stmt->execute([$token]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($client) {
        $valid_token = true;
        
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
                // Update password and clear reset token
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE clients SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL WHERE id = ?");
                $stmt->execute([$password_hash, $client['id']]);
                
                $success = 'Your password has been reset successfully! You can now log in with your new password.';
            }
        }
    } else {
        $error = 'This password reset link is invalid or has expired. Please request a new one.';
    }
}

$page_title = 'Reset Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - BDTA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #9a0073 0%, #7a005a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .login-header {
            background: linear-gradient(135deg, #9a0073 0%, #7a005a 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px 15px 0 0;
            text-align: center;
        }
        .btn-primary {
            background-color: #9a0073;
            border-color: #9a0073;
        }
        .btn-primary:hover {
            background-color: #7a005a;
            border-color: #7a005a;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card login-card">
                    <div class="login-header">
                        <h3 class="mb-0">BDTA Client Area</h3>
                        <small>Brooks Dog Training Academy</small>
                    </div>
                    <div class="card-body p-4">
                        <h5 class="text-center mb-4">Reset Password</h5>
                        
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo escape($error); ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?php echo escape($success); ?></div>
                            <div class="text-center mt-3">
                                <a href="login.php" class="btn btn-primary">Go to Login</a>
                            </div>
                        <?php elseif ($valid_token): ?>
                            <p class="text-muted text-center mb-4">
                                Enter your new password for <strong><?php echo escape($client['email']); ?></strong>
                            </p>
                            
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8" autofocus>
                                    <small class="form-text text-muted">Must be at least 8 characters long.</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8">
                                </div>
                                
                                <button type="submit" class="btn btn-primary w-100 mb-3">Reset Password</button>
                            </form>
                        <?php else: ?>
                            <div class="text-center mt-3">
                                <a href="forgot_password.php" class="btn btn-primary">Request New Reset Link</a>
                            </div>
                        <?php endif; ?>
                        
                        <div class="text-center mt-3">
                            <a href="login.php" class="text-decoration-none">Back to Login</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
