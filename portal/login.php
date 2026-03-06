<?php
require_once '../portal/includes/config.php';

// Redirect if already logged in
if (isPortalLoggedIn()) {
    redirect(PORTAL_URL . 'index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $db   = new Database();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("SELECT * FROM clients WHERE email = ? AND (is_admin = 0 OR is_admin IS NULL) AND password_hash IS NOT NULL AND password_hash != ''");
        $stmt->execute([$email]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($client && password_verify($password, $client['password_hash'])) {
            $_SESSION['portal_client_id']    = $client['id'];
            $_SESSION['portal_client_name']  = $client['name'];
            $_SESSION['portal_client_email'] = $client['email'];

            // Update last login
            $stmt = $conn->prepare("UPDATE clients SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$client['id']]);

            logClientActivity($client['id'], 'login', 'Client logged in', $conn);

            setFlashMessage('Welcome back, ' . escape($client['name']) . '!', 'success');
            redirect(PORTAL_URL . 'index.php');
        } else {
            $error = 'Invalid email address or password.';
        }
    } else {
        $error = 'Please enter your email and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title>Client Portal Login - BDTA</title>
    <!-- Dark mode: detect system preference and apply Bootstrap dark theme before any CSS renders -->
    <script>
        (function () {
            'use strict';
            var mq = window.matchMedia('(prefers-color-scheme: dark)');
            document.documentElement.setAttribute('data-bs-theme', mq.matches ? 'dark' : 'light');
            mq.addEventListener('change', function (e) {
                document.documentElement.setAttribute('data-bs-theme', e.matches ? 'dark' : 'light');
            });
        }());
    </script>
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
            <div class="col-md-4">
                <div class="card login-card">
                    <div class="login-header">
                        <h3 class="mb-0">BDTA Client Portal</h3>
                        <small>Brook's Dog Training Academy</small>
                    </div>
                    <div class="card-body p-4">
                        <h5 class="text-center mb-4">Sign In</h5>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo escape($error); ?></div>
                        <?php endif; ?>
                        <form method="POST">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email"
                                       value="<?php echo escape($_POST['email'] ?? ''); ?>" required autofocus>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Login</button>
                        </form>
                        <div class="text-center mt-3">
                            <a href="forgot_password.php" class="text-decoration-none">Forgot Password?</a>
                        </div>
                        <div class="text-center mt-4 pt-3 border-top">
                            <small class="text-muted"><a href="../client/login.php" class="text-muted text-decoration-none">Admin login</a></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
