<?php
require_once '../backend/includes/config.php';

// Redirect if already logged in
if (isPortalLoggedIn()) {
    redirect(PORTAL_URL . 'index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim(scalar_string($_POST['email'] ?? ''));
    $password = scalar_string($_POST['password'] ?? '');

    if ($email && $password) {
        $db   = new Database();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("SELECT * FROM clients WHERE email = ? AND (is_admin = 0 OR is_admin IS NULL) AND password_hash IS NOT NULL AND password_hash != ''");
        $stmt->execute([$email]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($client && password_verify($password, array_string_value($client, 'password_hash'))) {
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
            <div class="col-md-4">
                <div class="card login-card">
                    <div class="login-header">
                        <h1 class="h3 mb-0">BDTA Client Portal</h1>
                        <small>Brook's Dog Training Academy</small>
                    </div>
                    <div class="card-body p-4">
                        <h2 class="h5 text-center mb-4">Sign In</h2>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo escape($error); ?></div>
                        <?php endif; ?>
                        <form method="POST">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email"
                                       autocomplete="username"
                                       value="<?php echo escape($_POST['email'] ?? ''); ?>" required autofocus>
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
                        <div class="text-center mt-4 pt-3 border-top">
                            <small class="text-muted"><a href="../client/login.php" class="text-muted text-decoration-none">Admin login</a></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    <script src="/assets/js/auth-theme-toggle.js"></script>
    <?php
    require_once __DIR__ . '/../backend/includes/tawk_to.php';
    bdta_render_tawk_to_widget();
    ?>
</body>
</html>
