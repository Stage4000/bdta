<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title><?php echo isset($page_title) ? escape($page_title) : 'Client Portal'; ?> - BDTA</title>
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
    <?php
    $theme = Settings::getThemeColors();
    $theme_primary = array_string_value($theme, 'primary', '#9a0073');
    $theme_primary_dark = array_string_value($theme, 'primary_dark', '#7a005a');
    $theme_secondary = array_string_value($theme, 'secondary', '#0a9a9c');
    $theme_sidebar_start = array_string_value($theme, 'sidebar_bg_start', '#9a0073');
    $theme_sidebar_end = array_string_value($theme, 'sidebar_bg_end', '#7a005a');
    $tc_primary       = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_primary) === 1 ? $theme_primary : '#9a0073';
    $tc_primary_dark  = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_primary_dark) === 1 ? $theme_primary_dark : '#7a005a';
    $tc_secondary     = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_secondary) === 1 ? $theme_secondary : '#0a9a9c';
    $tc_sidebar_start = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_sidebar_start) === 1 ? $theme_sidebar_start : '#9a0073';
    $tc_sidebar_end   = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_sidebar_end) === 1 ? $theme_sidebar_end : '#7a005a';
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
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, <?= $tc_sidebar_start ?> 0%, <?= $tc_sidebar_end ?> 100%);
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
            background-color: <?= $tc_primary ?>;
            border-color: <?= $tc_primary ?>;
        }
        .btn-primary:hover {
            background-color: <?= $tc_primary_dark ?>;
            border-color: <?= $tc_primary_dark ?>;
        }
        .btn-success {
            background-color: <?= $tc_secondary ?>;
            border-color: <?= $tc_secondary ?>;
        }
        .btn-success:hover {
            background-color: color-mix(in srgb, <?= $tc_secondary ?> 85%, black);
            border-color: color-mix(in srgb, <?= $tc_secondary ?> 85%, black);
        }
        .badge.bg-primary {
            background-color: <?= $tc_primary ?> !important;
        }
        .badge.bg-info {
            background-color: <?= $tc_secondary ?> !important;
        }
        .text-primary {
            color: <?= $tc_primary ?> !important;
        }
        a {
            color: <?= $tc_primary ?>;
        }
        a:hover {
            color: <?= $tc_primary_dark ?>;
        }
        /* Dark mode overrides for custom (non-Bootstrap) elements */
        .sidebar-divider {
            border-top: 1px solid rgba(255,255,255,0.15);
            margin: 0.4rem 0.75rem;
        }
        .app-admin-banner {
            z-index: 1050;
        }
        .app-toast-container {
            z-index: 11;
        }
        .app-mobile-navbar {
            background: linear-gradient(135deg, var(--theme-sidebar-start) 0%, var(--theme-sidebar-end) 100%);
        }
        .app-main-content {
            min-height: 100vh;
            padding: 1rem;
            padding-bottom: 2rem;
        }
        @media (min-width: 768px) {
            .app-main-content {
                padding: 1.5rem;
            }
        }
        @media (prefers-color-scheme: dark) {
            main.col-md-9,
            main.col-md-10,
            .main-content {
                background-color: #111827;
            }
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
                            <a class="nav-link <?php echo $current_page === 'agreements.php' ? 'active' : ''; ?>" href="<?php echo PORTAL_URL; ?>agreements.php">
                                <i class="fas fa-file-contract me-2"></i> Agreements
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
                                <i class="fa-solid fa-dog me-2"></i> Pets
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
<!-- Dark mode toggle script -->
<script>
(function () {
    'use strict';
    function updateToggle() {
        var isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        var icon  = document.getElementById('darkModeIcon');
        var label = document.getElementById('darkModeLabel');
        if (icon)  icon.className = isDark ? 'fas fa-sun me-2' : 'fas fa-moon me-2';
        if (label) label.textContent = isDark ? 'Light Mode' : 'Dark Mode';
    }
    updateToggle();
    var btn = document.getElementById('darkModeToggle');
    if (btn) {
        btn.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-bs-theme', next);
            localStorage.setItem('bdta-theme', next);
            updateToggle();
        });
    }
}());
</script>
