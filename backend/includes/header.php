<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title><?php echo isset($page_title) ? escape($page_title) : 'Client Area'; ?> - BDTA</title>
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
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <link rel="manifest" href="/client/manifest.webmanifest">
    <?php
    $theme = Settings::getThemeColors();
    $theme_primary = scalar_string($theme['primary'] ?? '');
    $theme_primary_dark = scalar_string($theme['primary_dark'] ?? '');
    $theme_secondary = scalar_string($theme['secondary'] ?? '');
    $theme_sidebar_start = scalar_string($theme['sidebar_bg_start'] ?? '');
    $theme_sidebar_end = scalar_string($theme['sidebar_bg_end'] ?? '');
    $tc_primary       = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_primary) === 1 ? $theme_primary : '#9a0073';
    $tc_primary_dark  = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_primary_dark) === 1 ? $theme_primary_dark : '#7a005a';
    $tc_secondary     = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_secondary) === 1 ? $theme_secondary : '#0a9a9c';
    $tc_sidebar_start = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_sidebar_start) === 1 ? $theme_sidebar_start : '#9a0073';
    $tc_sidebar_end   = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_sidebar_end) === 1 ? $theme_sidebar_end : '#7a005a';
    ?>
    <meta name="theme-color" content="<?= $tc_primary ?>">
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
            background: rgba(10,154,156,0.3);
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
        /* Submenu parent row layout */
        .nav-item-parent {
            display: flex;
            align-items: stretch;
        }
        .nav-item-parent > .nav-link {
            flex-grow: 1;
        }
        /* Submenu toggle chevron button */
        .submenu-toggle {
            background: none;
            border: none;
            color: rgba(255,255,255,0.8);
            padding: 0 0.75rem;
            cursor: pointer;
            flex-shrink: 0;
            display: flex;
            align-items: center;
        }
        .submenu-toggle:hover {
            color: #fff;
        }
        .submenu-toggle .fa-chevron-down {
            font-size: 0.7rem;
            transition: transform 0.2s ease;
        }
        .submenu-toggle[aria-expanded="true"] .fa-chevron-down {
            transform: rotate(180deg);
        }
        /* Sub-menu items */
        .sidebar .submenu .nav-link {
            padding: 0.45rem 1rem 0.45rem 2.5rem;
            font-size: 0.875em;
        }
        /* Sidebar divider */
        .sidebar-divider {
            border-top: 1px solid rgba(255,255,255,0.15);
            margin: 0.4rem 0.75rem;
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
        /* Dark mode overrides for custom (non-Bootstrap) elements */
        @media (prefers-color-scheme: dark) {
            main.col-md-9,
            main.col-md-10,
            .main-content {
                background-color: #111827;
            }
        }
    </style>
    <script src="/client/pwa-register.js" defer></script>
</head>
<body>
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
    
    <?php if (isLoggedIn()): ?>
    <div class="container-fluid">
        <div class="row">
            <!-- Mobile menu toggle button -->
            <nav class="navbar navbar-dark d-md-none app-mobile-navbar">
                <div class="container-fluid">
                    <span class="navbar-brand mb-0 fs-6">BDTA Client Area</span>
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                </div>
            </nav>
            
            <nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block sidebar collapse">
                <div class="position-sticky pt-3">
                    <h5 class="text-white px-3 mb-3 d-none d-md-block">BDTA Client Area</h5>
                    <?php
                        $currentPath = scalar_string($_SERVER['PHP_SELF'] ?? '');
                        $currentFile = basename($currentPath);
                        // Individual active states
                        $isClients        = strpos($currentPath, 'clients_') !== false;
                        $isPets           = strpos($currentPath, 'pets') !== false;
                        $isBookings       = strpos($currentPath, 'booking') !== false;
                        $isApptTypes      = strpos($currentPath, 'appointment_types') !== false;
                        $isPackages       = strpos($currentPath, 'packages') !== false;
                        $isTplDefaults    = $currentFile === 'email_template_defaults.php';
                        $isTimeTracker    = strpos($currentPath, 'time_entries') !== false || strpos($currentPath, 'time_tracker') !== false;
                        $isInvoices       = strpos($currentPath, 'invoice') !== false;
                        $isExpenses       = strpos($currentPath, 'expense') !== false;
                        $isQuotes         = strpos($currentPath, 'quote') !== false;
                        $isFinancial      = strpos($currentPath, 'reports_financial') !== false || strpos($currentPath, 'reports_export') !== false;
                        $isContracts      = strpos($currentPath, 'contract') !== false && strpos($currentPath, 'template') === false;
                        $isContractTpls   = strpos($currentPath, 'contract_template') !== false;
                        $isBlog           = strpos($currentPath, 'blog') !== false;
                        $isPortal         = $currentFile === 'portal_homepage.php';
                        $isSitePages      = strpos($currentPath, 'site_pages') !== false || strpos($currentPath, 'site_editor') !== false;
                        $isFormTpls       = strpos($currentPath, 'form_templates') !== false;
                        $isFormSubs       = strpos($currentPath, 'form_submissions') !== false;
                        $isUnmatched      = strpos($currentPath, 'unmatched_emails') !== false;
                        $isEmailSigs      = strpos($currentPath, 'email_signatures') !== false;
                        $isEmailTpls      = strpos($currentPath, 'email_templates') !== false && $currentFile !== 'email_template_defaults.php';
                        $isWorkflows      = strpos($currentPath, 'workflows') !== false;
                        $isScheduled      = strpos($currentPath, 'scheduled_tasks') !== false;
                        $isSettings       = $currentFile === 'settings.php';
                        $isChangePwd      = $currentFile === 'change_password.php';
                        // Group active states (any child active → group open)
                        $clientsOpen      = $isClients || $isPets;
                        $bookingsOpen     = $isBookings || $isApptTypes || $isPackages || $isTplDefaults;
                        $invoicesOpen     = $isInvoices || $isExpenses || $isQuotes || $isFinancial;
                        $contractsOpen    = $isContracts || $isContractTpls;
                        $formTplsOpen     = $isFormTpls || $isFormSubs;
                        $unmatchedOpen    = $isUnmatched || $isEmailSigs || $isEmailTpls;
                        $workflowsOpen    = $isWorkflows || $isScheduled;
                        $settingsOpen     = $isSettings || $isChangePwd;
                    ?>
                    <ul class="nav flex-column">

                        <!-- 1. Dashboard -->
                        <li class="nav-item">
                            <a class="nav-link <?php echo $currentFile === 'index.php' ? 'active' : ''; ?>" href="index.php">
                                <i class="fas fa-gauge me-2"></i> Dashboard
                            </a>
                        </li>

                        <!-- 2. Clients (+ Pets sub-item) -->
                        <li class="nav-item">
                            <div class="nav-item-parent">
                                <a class="nav-link <?php echo $clientsOpen ? 'active' : ''; ?>" href="clients_list.php">
                                    <i class="fas fa-users me-2"></i> Clients
                                </a>
                                <button class="submenu-toggle" data-bs-toggle="collapse" data-bs-target="#clientsSubmenu" aria-expanded="<?php echo $clientsOpen ? 'true' : 'false'; ?>" aria-controls="clientsSubmenu">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            <div class="collapse <?php echo $clientsOpen ? 'show' : ''; ?>" id="clientsSubmenu">
                                <ul class="nav flex-column submenu">
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $isPets ? 'active' : ''; ?>" href="pets_list.php">
                                            <i class="fa-solid fa-dog me-2"></i> Pets
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>

                        <!-- 3. Bookings (+ Appointment Types, Packages, Template Defaults) -->
                        <li class="nav-item">
                            <div class="nav-item-parent">
                                <a class="nav-link <?php echo $bookingsOpen ? 'active' : ''; ?>" href="bookings_list.php">
                                    <i class="fas fa-calendar-check me-2"></i> Bookings
                                </a>
                                <button class="submenu-toggle" data-bs-toggle="collapse" data-bs-target="#bookingsSubmenu" aria-expanded="<?php echo $bookingsOpen ? 'true' : 'false'; ?>" aria-controls="bookingsSubmenu">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            <div class="collapse <?php echo $bookingsOpen ? 'show' : ''; ?>" id="bookingsSubmenu">
                                <ul class="nav flex-column submenu">
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $isApptTypes ? 'active' : ''; ?>" href="appointment_types_list.php">
                                            <i class="fas fa-calendar-plus me-2"></i> Appointment Types
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $isPackages ? 'active' : ''; ?>" href="packages_list.php">
                                            <i class="fas fa-box-open me-2"></i> Packages
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $isTplDefaults ? 'active' : ''; ?>" href="email_template_defaults.php">
                                            <i class="fas fa-envelope-open-text me-2"></i> Template Defaults
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>

                        <!-- 4. Time Tracker -->
                        <li class="nav-item">
                            <a class="nav-link <?php echo $isTimeTracker ? 'active' : ''; ?>" href="time_entries_list.php">
                                <i class="fas fa-stopwatch me-2"></i> Time Tracker
                            </a>
                        </li>

                        <!-- 5. Invoices (+ Expenses, Quotes, Financial Reports) -->
                        <li class="nav-item">
                            <div class="nav-item-parent">
                                <a class="nav-link <?php echo $invoicesOpen ? 'active' : ''; ?>" href="invoices_list.php">
                                    <i class="fas fa-file-invoice me-2"></i> Invoices
                                </a>
                                <button class="submenu-toggle" data-bs-toggle="collapse" data-bs-target="#invoicesSubmenu" aria-expanded="<?php echo $invoicesOpen ? 'true' : 'false'; ?>" aria-controls="invoicesSubmenu">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            <div class="collapse <?php echo $invoicesOpen ? 'show' : ''; ?>" id="invoicesSubmenu">
                                <ul class="nav flex-column submenu">
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $isExpenses ? 'active' : ''; ?>" href="expenses_list.php">
                                            <i class="fas fa-receipt me-2"></i> Expenses
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $isQuotes ? 'active' : ''; ?>" href="quotes_list.php">
                                            <i class="fas fa-file-invoice-dollar me-2"></i> Quotes
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $isFinancial ? 'active' : ''; ?>" href="reports_financial.php">
                                            <i class="fas fa-chart-line me-2"></i> Financial Reports
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>

                        <!-- 6. Contracts (+ Contract Templates) -->
                        <li class="nav-item">
                            <div class="nav-item-parent">
                                <a class="nav-link <?php echo $contractsOpen ? 'active' : ''; ?>" href="contracts_list.php">
                                    <i class="fas fa-file-contract me-2"></i> Contracts
                                </a>
                                <button class="submenu-toggle" data-bs-toggle="collapse" data-bs-target="#contractsSubmenu" aria-expanded="<?php echo $contractsOpen ? 'true' : 'false'; ?>" aria-controls="contractsSubmenu">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            <div class="collapse <?php echo $contractsOpen ? 'show' : ''; ?>" id="contractsSubmenu">
                                <ul class="nav flex-column submenu">
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $isContractTpls ? 'active' : ''; ?>" href="contract_templates_list.php">
                                            <i class="fas fa-file-medical me-2"></i> Contract Templates
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>

                        <!-- 7. Blog -->
                        <li class="nav-item">
                            <a class="nav-link <?php echo $isBlog ? 'active' : ''; ?>" href="blog_list.php">
                                <i class="fas fa-blog me-2"></i> Blog
                            </a>
                        </li>

                        <!-- 8. Site Editor (front-end pages) -->
                        <li class="nav-item">
                            <a class="nav-link <?php echo $isSitePages ? 'active' : ''; ?>" href="site_pages_list.php">
                                <i class="fas fa-file-code me-2"></i> Site Editor
                            </a>
                        </li>

                        <!-- 9. Portal Homepage -->
                        <li class="nav-item">
                            <a class="nav-link <?php echo $isPortal ? 'active' : ''; ?>" href="portal_homepage.php">
                                <i class="fas fa-door-open me-2"></i> Portal Homepage
                            </a>
                        </li>

                        <!-- 9. Form Templates (+ Form Submissions) -->
                        <li class="nav-item">
                            <div class="nav-item-parent">
                                <a class="nav-link <?php echo $formTplsOpen ? 'active' : ''; ?>" href="form_templates_list.php">
                                    <i class="fas fa-file-lines me-2"></i> Form Templates
                                </a>
                                <button class="submenu-toggle" data-bs-toggle="collapse" data-bs-target="#formTplsSubmenu" aria-expanded="<?php echo $formTplsOpen ? 'true' : 'false'; ?>" aria-controls="formTplsSubmenu">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            <div class="collapse <?php echo $formTplsOpen ? 'show' : ''; ?>" id="formTplsSubmenu">
                                <ul class="nav flex-column submenu">
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $isFormSubs ? 'active' : ''; ?>" href="form_submissions_list.php">
                                            <i class="fas fa-file-circle-check me-2"></i> Form Submissions
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>

                        <!-- 10. Unmatched Emails (+ Email Signatures, Email Templates) -->
                        <li class="nav-item">
                            <div class="nav-item-parent">
                                <a class="nav-link <?php echo $unmatchedOpen ? 'active' : ''; ?>" href="unmatched_emails_list.php">
                                    <i class="fas fa-envelope-open-text me-2"></i> Unmatched Emails
                                </a>
                                <button class="submenu-toggle" data-bs-toggle="collapse" data-bs-target="#unmatchedSubmenu" aria-expanded="<?php echo $unmatchedOpen ? 'true' : 'false'; ?>" aria-controls="unmatchedSubmenu">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            <div class="collapse <?php echo $unmatchedOpen ? 'show' : ''; ?>" id="unmatchedSubmenu">
                                <ul class="nav flex-column submenu">
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $isEmailSigs ? 'active' : ''; ?>" href="email_signatures_list.php">
                                            <i class="fas fa-signature me-2"></i> Email Signatures
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $isEmailTpls ? 'active' : ''; ?>" href="email_templates_list.php">
                                            <i class="fas fa-envelope me-2"></i> Email Templates
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>

                        <!-- 11. Workflows (+ Scheduled Tasks) -->
                        <li class="nav-item">
                            <div class="nav-item-parent">
                                <a class="nav-link <?php echo $workflowsOpen ? 'active' : ''; ?>" href="workflows_list.php">
                                    <i class="fas fa-diagram-project me-2"></i> Workflows
                                </a>
                                <button class="submenu-toggle" data-bs-toggle="collapse" data-bs-target="#workflowsSubmenu" aria-expanded="<?php echo $workflowsOpen ? 'true' : 'false'; ?>" aria-controls="workflowsSubmenu">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            <div class="collapse <?php echo $workflowsOpen ? 'show' : ''; ?>" id="workflowsSubmenu">
                                <ul class="nav flex-column submenu">
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $isScheduled ? 'active' : ''; ?>" href="scheduled_tasks_list.php">
                                            <i class="fas fa-clock me-2"></i> Scheduled Tasks
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </li>

                        <!-- Divider before Settings -->
                        <li><hr class="sidebar-divider"></li>

                        <!-- 12. Settings (+ Change Password, View Website, Logout) -->
                        <li class="nav-item">
                            <div class="nav-item-parent">
                                <a class="nav-link <?php echo $settingsOpen ? 'active' : ''; ?>" href="settings.php">
                                    <i class="fas fa-gear me-2"></i> Settings
                                </a>
                                <button class="submenu-toggle" data-bs-toggle="collapse" data-bs-target="#settingsSubmenu" aria-expanded="<?php echo $settingsOpen ? 'true' : 'false'; ?>" aria-controls="settingsSubmenu">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            <div class="collapse <?php echo $settingsOpen ? 'show' : ''; ?>" id="settingsSubmenu">
                                <ul class="nav flex-column submenu">
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $isChangePwd ? 'active' : ''; ?>" href="change_password.php">
                                            <i class="fas fa-key me-2"></i> Change Password
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" href="../../index.html" target="_blank">
                                            <i class="fas fa-house me-2"></i> View Website
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" href="logout.php">
                                            <i class="fas fa-arrow-right-from-bracket me-2"></i> Logout
                                        </a>
                                    </li>
                                </ul>
                            </div>
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
<?php else: ?>
<main class="container mt-5">
<?php endif; ?>
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
