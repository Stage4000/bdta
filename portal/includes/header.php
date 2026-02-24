<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? escape($page_title) : 'Client Portal'; ?> - BDTA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #9a0073 0%, #7a005a 100%);
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 0.75rem 1rem;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            color: #fff;
            background: rgba(255,255,255,0.15);
        }
        .btn-primary {
            background-color: #9a0073;
            border-color: #9a0073;
        }
        .btn-primary:hover {
            background-color: #7a005a;
            border-color: #7a005a;
        }
        .btn-success {
            background-color: #0a9a9c;
            border-color: #0a9a9c;
        }
        .btn-success:hover {
            background-color: #088587;
            border-color: #088587;
        }
        .badge.bg-primary {
            background-color: #9a0073 !important;
        }
        .badge.bg-info {
            background-color: #0a9a9c !important;
        }
        .text-primary {
            color: #9a0073 !important;
        }
        a {
            color: #9a0073;
        }
        a:hover {
            color: #7a005a;
        }
    </style>
</head>
<body>
    <?php $flash = getFlashMessage(); ?>
    <?php if ($flash): ?>
    <div class="position-fixed top-0 end-0 p-3" style="z-index: 11">
        <div class="toast show align-items-center text-white bg-<?php echo $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'error' ? 'danger' : 'info'); ?> border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body"><?php echo escape($flash['message']); ?></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="container-fluid">
        <div class="row">
            <!-- Mobile navbar -->
            <nav class="navbar navbar-dark d-md-none" style="background: linear-gradient(135deg, #9a0073 0%, #7a005a 100%);">
                <div class="container-fluid">
                    <span class="navbar-brand">BDTA Client Portal</span>
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                </div>
            </nav>

            <nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block sidebar collapse">
                <div class="position-sticky pt-3">
                    <h5 class="text-white px-3 mb-1 d-none d-md-block">BDTA Client Portal</h5>
                    <?php if (isPortalLoggedIn()): ?>
                    <small class="text-white-50 px-3 d-none d-md-block mb-3"><?php echo escape($_SESSION['portal_client_name'] ?? ''); ?></small>
                    <?php endif; ?>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>index.php">
                                <i class="fas fa-gauge me-2"></i> Home
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'invoices.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>invoices.php">
                                <i class="fas fa-file-invoice me-2"></i> Invoices
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'appointments.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>appointments.php">
                                <i class="fas fa-calendar-check me-2"></i> Appointments
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'credits.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>credits.php">
                                <i class="fas fa-coins me-2"></i> Credits
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'agreements.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>agreements.php">
                                <i class="fas fa-file-contract me-2"></i> Agreements
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'quotes.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>quotes.php">
                                <i class="fas fa-file-invoice-dollar me-2"></i> Quotes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'activity.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>activity.php">
                                <i class="fas fa-list-ul me-2"></i> Activity
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>profile.php">
                                <i class="fas fa-user me-2"></i> Profile
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'pets.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>pets.php">
                                <i class="fa-solid fa-dog me-2"></i> Pets
                            </a>
                        </li>
                        <li class="nav-item mt-3">
                            <a class="nav-link" href="<?php echo PORTAL_URL; ?>logout.php">
                                <i class="fas fa-arrow-right-from-bracket me-2"></i> Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 pt-3">
