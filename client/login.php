<?php
require_once '../backend/includes/config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = scalar_string($_POST['username'] ?? '');
    $password = scalar_string($_POST['password'] ?? '');
    
    if ($username && $password) {
        $db = new Database();
        $conn = $db->getConnection();
        
        // First check admin_users table
        $stmt = $conn->prepare("SELECT * FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (is_array($user) && password_verify($password, array_string_value($user, 'password_hash'))) {
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['is_admin'] = true;
            $_SESSION['user_type'] = 'admin';
            setFlashMessage('Welcome back!', 'success');
            redirect('index.php');
        } else {
            // Check clients table for admin clients
            $stmt = $conn->prepare("SELECT id, name, email, password_hash, is_admin FROM clients WHERE email = ? AND is_admin = 1 AND password_hash IS NOT NULL AND password_hash != ''");
            $stmt->execute([$username]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (is_array($client) && password_verify($password, array_string_value($client, 'password_hash'))) {
                $_SESSION['admin_id'] = $client['id'];
                $_SESSION['admin_username'] = $client['name'];
                $_SESSION['admin_email'] = $client['email'];
                $_SESSION['is_admin'] = true;
                $_SESSION['user_type'] = 'client';
                
                // Update last login
                $stmt = $conn->prepare("UPDATE clients SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$client['id']]);
                
                setFlashMessage('Welcome back, ' . escape($client['name']) . '!', 'success');
                redirect('index.php');
            } else {
                $error = 'Invalid username or password';
            }
        }
    }
}

$page_title = 'Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title>Client Login - BDTA</title>
    <script src="/assets/js/theme-init.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="/assets/css/auth-pages.css">
    <link rel="manifest" href="/client/manifest.webmanifest">
    <meta name="theme-color" content="#9a0073">
</head>
<body>
    <!-- Dark mode toggle (floating) -->
    <button id="darkModeToggle" class="btn btn-outline-light btn-sm position-fixed top-0 end-0 m-3 login-theme-toggle" title="Toggle dark mode" aria-label="Toggle dark mode">
        <i class="fas fa-moon" id="darkModeIcon"></i>
    </button>
    <main class="container">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <div class="card login-card">
                    <div class="login-header">
                        <h1 class="h3 mb-0">BDTA Admin Login</h1>
                        <small>Brook's Dog Training Academy</small>
                    </div>
                    <div class="card-body p-4">
                        <h2 class="h5 text-center mb-4">Admin Sign In</h2>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo escape($error); ?></div>
                        <?php endif; ?>
                        <form method="POST">
                            <div class="mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" name="username" required autofocus autocomplete="username">
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Login</button>
                        </form>
                        <div class="text-center mt-3">
                            <a href="forgot_password.php" class="text-decoration-none">Forgot Password?</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    <script src="/client/pwa-register.js"></script>
    <script src="/assets/js/auth-theme-toggle.js"></script>
    <?php
    require_once '../backend/includes/tawk_to.php';
    bdta_render_tawk_to_widget();
    ?>
</body>
</html>
