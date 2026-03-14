<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/email_service.php';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim(scalar_string($_POST['email'] ?? ''));

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
            $result = $emailService->sendGenericEmail($email, $subject, $html_body, $text_body, EmailService::MAIL_TYPE_PASSWORD_RESET);
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
    <meta name="color-scheme" content="light dark">
    <title>Forgot Password - BDTA Client Portal</title>
    <!-- Dark mode: respect saved user preference, fall back to system preference -->
    <script>
        (function () {
            'use strict';
            var saved = localStorage.getItem('bdta-theme');
            var theme = saved ? saved : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-bs-theme', theme);
        }());
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
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
        .login-theme-toggle { z-index: 1100; }
        [data-bs-theme="dark"] body { background: linear-gradient(135deg, #1a1d23 0%, #0d1117 100%); }
        [data-bs-theme="dark"] .login-card { background-color: #1f2937; color: #e5e7eb; }
        [data-bs-theme="dark"] .card-body { background-color: #111827; color: #e5e7eb; }
        [data-bs-theme="dark"] .form-control { background-color: #111827; border-color: #4b5563; color: #f8fafc; }
        [data-bs-theme="dark"] .form-control::placeholder { color: #9ca3af; }
        [data-bs-theme="dark"] .text-muted,
        [data-bs-theme="dark"] .form-text,
        [data-bs-theme="dark"] a:not(.btn) { color: #d8b4fe !important; }
        [data-bs-theme="dark"] a:not(.btn):hover { color: #f5d0fe !important; }
    </style>
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
                        <h1 class="h3 mb-0">BDTA Client Portal</h1>
                        <small>Brook's Dog Training Academy</small>
                    </div>
                    <div class="card-body p-4">
                        <h2 class="h5 text-center mb-4">Forgot Password</h2>
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
                                <input type="email" class="form-control" id="email" name="email" required autofocus autocomplete="username">
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
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    <script>
    (function () {
        'use strict';
        function updateIcon() {
            var isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
            var icon = document.getElementById('darkModeIcon');
            if (icon) icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        }
        updateIcon();
        var btn = document.getElementById('darkModeToggle');
        if (btn) {
            btn.addEventListener('click', function () {
                var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-bs-theme', next);
                localStorage.setItem('bdta-theme', next);
                updateIcon();
            });
        }
    }());
    </script>
</body>
</html>
