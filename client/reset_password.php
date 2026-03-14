<?php
/**
 * Reset Password - Set new password using reset token
 */

require_once '../backend/includes/config.php';

$error = '';
$success = '';
$token = scalar_string($_GET['token'] ?? '');
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
            $new_password = scalar_string($_POST['new_password'] ?? '');
            $confirm_password = scalar_string($_POST['confirm_password'] ?? '');
            
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
                $stmt->execute([$password_hash, array_int_value($client, 'id')]);
                
                $success = 'Your password has been reset successfully! You can now log in with your new password.';
            }
        }
    } else {
        $error = 'This password reset link is invalid or has expired. Please request a new one.';
    }
}

$page_title = 'Reset Password';
$client_email = $valid_token && is_array($client) ? array_string_value($client, 'email') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title>Reset Password - BDTA</title>
    <script src="/assets/js/theme-init.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="/assets/css/auth-pages.css">
</head>
<body>
    <!-- Dark mode toggle (floating) -->
    <button id="darkModeToggle" class="btn btn-outline-light btn-sm position-fixed top-0 end-0 m-3 login-theme-toggle" title="Toggle dark mode" aria-label="Toggle dark mode">
        <i class="fas fa-moon" id="darkModeIcon"></i>
    </button>
    <main class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card login-card">
                    <div class="login-header">
                        <h1 class="h3 mb-0">BDTA Client Area</h1>
                        <small>Brook's Dog Training Academy</small>
                    </div>
                    <div class="card-body p-4">
                        <h2 class="h5 text-center mb-4">Reset Password</h2>
                        
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
                                Enter your new password for <strong><?php echo escape($client_email); ?></strong>
                            </p>
                            
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8" autofocus autocomplete="new-password">
                                    <small class="form-text text-muted">Must be at least 8 characters long.</small>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
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
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    <script src="/assets/js/auth-theme-toggle.js"></script>
    <?php
    require_once '../backend/includes/tawk_to.php';
    bdta_render_tawk_to_widget();
    ?>
</body>
</html>
