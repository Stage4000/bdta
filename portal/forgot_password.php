<?php
require_once '../portal/includes/config.php';
require_once '../backend/includes/email_service.php';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $db   = new Database();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("SELECT id, name, email FROM clients WHERE email = ? AND (is_admin = 0 OR is_admin IS NULL)");
        $stmt->execute([$email]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($client) {
            $token   = bin2hex(random_bytes(32));
            $expires = gmdate('Y-m-d H:i:s', strtotime('+1 hour'));

            $stmt = $conn->prepare("UPDATE clients SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?");
            $stmt->execute([$token, $expires, $client['id']]);

            $reset_link = getDynamicBaseUrl() . '/portal/reset_password.php?token=' . $token;

            $subject = "Password Reset Request - BDTA Client Portal";
            $html_body = "<html><body>"
                . "<p>Hello " . htmlspecialchars($client['name']) . ",</p>"
                . "<p>You requested a password reset for your BDTA Client Portal account.</p>"
                . "<p>Click the link below to reset your password:</p>"
                . "<p><a href='" . htmlspecialchars($reset_link) . "'>" . htmlspecialchars($reset_link) . "</a></p>"
                . "<p>This link will expire in 1 hour.</p>"
                . "<p>If you didn't request this, please ignore this email.</p>"
                . "<p>Brook's Dog Training Academy</p>"
                . "</body></html>";
            $text_body = "Hello " . $client['name'] . ",\n\n"
                . "You requested a password reset for your BDTA Client Portal account.\n\n"
                . "Click the link below to reset your password:\n"
                . $reset_link . "\n\n"
                . "This link will expire in 1 hour.\n\n"
                . "If you didn't request this, please ignore this email.\n\n"
                . "Brook's Dog Training Academy";

            $emailService = new EmailService();
            $result = $emailService->sendGenericEmail($email, $subject, $html_body, $text_body);
            if (!$result['success']) {
                error_log("Portal password reset email failed: " . $result['message']);
            }
        }

        // Always show success for security
        $success = 'If an account exists with that email address, you will receive a password reset link shortly.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - BDTA Client Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #9a0073 0%, #7a005a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card { border: none; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); }
        .login-header { background: linear-gradient(135deg, #9a0073 0%, #7a005a 100%); color: white; padding: 2rem; border-radius: 15px 15px 0 0; text-align: center; }
        .btn-primary { background-color: #9a0073; border-color: #9a0073; }
        .btn-primary:hover { background-color: #7a005a; border-color: #7a005a; }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card login-card">
                    <div class="login-header">
                        <h3 class="mb-0">BDTA Client Portal</h3>
                        <small>Brook's Dog Training Academy</small>
                    </div>
                    <div class="card-body p-4">
                        <h5 class="text-center mb-4">Forgot Password</h5>
                        <p class="text-muted text-center mb-4">Enter your email address and we'll send you a link to reset your password.</p>

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo escape($error); ?></div>
                        <?php endif; ?>
                        <?php if ($success): ?>
                            <div class="alert alert-success"><?php echo escape($success); ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email" required autofocus>
                            </div>
                            <button type="submit" class="btn btn-primary w-100 mb-3">Send Reset Link</button>
                        </form>
                        <div class="text-center">
                            <a href="login.php" class="text-decoration-none">Back to Login</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
