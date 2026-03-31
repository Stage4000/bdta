<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title><?php echo isset($page_title) ? escape($page_title) : 'Client Portal'; ?> - BDTA</title>
    <script src="/assets/js/theme-init.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/shared-ui.css">
    <?php
    require_once dirname(__DIR__, 2) . '/backend/includes/theme_palette.php';
    $theme_palette = bdta_get_theme_palette();
    $tc_primary = $theme_palette['primary'];
    $tc_primary_dark = $theme_palette['primary_dark'];
    $tc_secondary = $theme_palette['secondary'];
    $tc_sidebar_start = $theme_palette['sidebar_start'];
    $tc_sidebar_end = $theme_palette['sidebar_end'];
    $current_page = basename(scalar_string($_SERVER['PHP_SELF'] ?? ''));
    ?>
    <style>
        :root {
            --theme-primary:       <?= $tc_primary ?>;
            --theme-primary-dark:  <?= $tc_primary_dark ?>;
            --theme-secondary:     <?= $tc_secondary ?>;
            --theme-sidebar-start: <?= $tc_sidebar_start ?>;
            --theme-sidebar-end:   <?= $tc_sidebar_end ?>;
        }
    </style>
</head>
<body>
    <?php if (!empty($_SESSION['portal_impersonating_admin_id'])): ?>
    <div class="alert alert-warning mb-0 rounded-0 text-center py-2 app-admin-banner">
        <i class="fas fa-user-secret me-2"></i>
        <strong>Admin View:</strong> You are viewing the portal as <strong><?php echo escape($_SESSION['portal_client_name'] ?? 'this client'); ?></strong>.
        <a href="<?php echo PORTAL_URL; ?>stop_impersonation.php" class="btn btn-sm btn-dark ms-3">
            <i class="fas fa-arrow-left me-1"></i> Return to Admin
        </a>
    </div>
    <?php endif; ?>
    <?php $flash = getFlashMessage(); ?>
    <?php if ($flash): ?>
    <div class="position-fixed top-0 end-0 p-3 app-toast-container">
        <div class="toast show align-items-center text-white bg-<?php echo escape($flash['type']); ?> border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body"><?php echo escape($flash['message']); ?></div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close notification"></button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="container-fluid">
        <div class="row">
            <!-- Mobile navbar -->
            <nav class="navbar navbar-dark d-md-none app-mobile-navbar">
                <div class="container-fluid">
                    <span class="navbar-brand mb-0 fs-6">BDTA Client Portal</span>
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
                            <a class="nav-link <?php echo $current_page === 'index.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>index.php">
                                <i class="fas fa-gauge me-2"></i> Home
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page === 'invoices.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>invoices.php">
                                <i class="fas fa-file-invoice me-2"></i> Invoices
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page === 'appointments.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>appointments.php">
                                <i class="fas fa-calendar-check me-2"></i> Appointments
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page === 'credits.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>credits.php">
                                <i class="fas fa-coins me-2"></i> Credits
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo in_array($current_page, ['agreements.php', 'form_view.php'], true) ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>agreements.php">
                                <i class="fas fa-file-contract me-2"></i> Agreements &amp; Forms
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page === 'quotes.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>quotes.php">
                                <i class="fas fa-file-invoice-dollar me-2"></i> Quotes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page === 'activity.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>activity.php">
                                <i class="fas fa-list-ul me-2"></i> Activity
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page === 'profile.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>profile.php">
                                <i class="fas fa-user me-2"></i> Profile
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $current_page === 'pets.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>pets.php">
                                <i class="fas fa-dog me-2"></i> Pets
                            </a>
                        </li>
                        <li class="nav-item mt-3">
                            <a class="nav-link" href="<?php echo PORTAL_URL; ?>logout.php">
                                <i class="fas fa-arrow-right-from-bracket me-2"></i> Logout
                            </a>
                        </li>
                        <!-- Dark Mode Toggle -->
                        <li><hr class="sidebar-divider"></li>
                        <li class="nav-item px-3 pb-3">
                            <button id="darkModeToggle" class="btn btn-outline-light btn-sm w-100" title="Toggle dark mode" aria-label="Toggle dark mode">
                                <i class="fas fa-moon me-2" id="darkModeIcon"></i><span id="darkModeLabel">Dark Mode</span>
                            </button>
                        </li>
                    </ul>
                </div>
            </nav>

            <main class="col-md-9 ms-sm-auto col-lg-10 app-main-content">
