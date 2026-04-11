<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title><?php echo isset($page_title) ? escape($page_title) : 'Client Area'; ?> - BDTA</title>
    <script src="/assets/js/theme-init.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha384-t1nt8BQoYMLFN5p42tRAtuAAFQaCQODekUVeKKZrEnEyp4H2R0RHFz0KWpmj7i8g" crossorigin="anonymous">
    <link rel="stylesheet" href="../assets/css/shared-ui.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
    <link rel="manifest" href="/client/manifest.webmanifest">
    <link rel="apple-touch-icon" sizes="180x180" href="/client/apple-touch-icon.png">
    <?php
    require_once __DIR__ . '/theme_palette.php';
    $theme_palette = bdta_get_theme_palette();
    $tc_primary = $theme_palette['primary'];
    $tc_primary_dark = $theme_palette['primary_dark'];
    $tc_secondary = $theme_palette['secondary'];
    $tc_sidebar_start = $theme_palette['sidebar_start'];
    $tc_sidebar_end = $theme_palette['sidebar_end'];
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
    </style>
    <script src="/client/pwa-register.js" defer></script>
</head>
<body>
    <?php require_once __DIR__ . '/time_tracker_helper.php'; ?>
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
    
    <?php if (isLoggedIn()): ?>
    <?php
        if (!isset($conn) || !($conn instanceof PDO)) {
            $header_db = new Database();
            $conn = $header_db->getConnection();
        }
        bdta_render_notification_widget(
            $conn,
            'admin',
            safe_int($_SESSION['admin_id'] ?? 0),
            '/client/notification_action.php',
            '/client/notification_redirect.php',
            '/client/index.php'
        );
    ?>
    <?php
        $bdta_active_timer = bdta_normalize_valid_active_timer($_SESSION['active_timer'] ?? null);
        if ($bdta_active_timer === null) {
            unset($_SESSION['active_timer']);
        }
        $bdta_active_timer_storage_key = bdta_active_timer_storage_key($_SESSION['user_type'] ?? 'admin', $_SESSION['admin_id'] ?? 0);
    ?>
    <a
        id="appActiveTimerIndicator"
        class="app-active-timer d-none"
        href="time_tracker.php"
        aria-label="Open the running timer"
    >
        <span class="app-active-timer__status">
            <i class="fas fa-stopwatch" aria-hidden="true"></i>
            <span>Timer running</span>
        </span>
        <span id="appActiveTimerIndicatorTime" class="app-active-timer__time" aria-hidden="true">00:00:00</span>
        <span id="appActiveTimerIndicatorMeta" class="app-active-timer__meta"></span>
    </a>
    <script>
    (function() {
        const indicator = document.getElementById('appActiveTimerIndicator');
        const timeElement = document.getElementById('appActiveTimerIndicatorTime');
        const metaElement = document.getElementById('appActiveTimerIndicatorMeta');
        const storageKey = <?= json_encode($bdta_active_timer_storage_key) ?>;
        const serverTimer = <?= json_encode($bdta_active_timer) ?>;
        const ACTIVE_TIMER_FUTURE_TOLERANCE_SECONDS = 300;
        let timerInterval = null;
        let currentTimer = null;

        function normalizeActiveTimer(timer) {
            if (!timer || typeof timer !== 'object') {
                return null;
            }

            const startTimeValue = Number(timer.start_time);
            const clientId = Number(timer.client_id);
            const serviceType = typeof timer.service_type === 'string' ? timer.service_type.trim() : '';
            const description = typeof timer.description === 'string' ? timer.description.trim() : '';

            const nowInSeconds = Math.floor(Date.now() / 1000);
            const hasValidStartTime = Number.isFinite(startTimeValue)
                && startTimeValue > 0
                && startTimeValue <= (nowInSeconds + ACTIVE_TIMER_FUTURE_TOLERANCE_SECONDS);

            if (!hasValidStartTime || !Number.isFinite(clientId) || clientId <= 0 || serviceType === '') {
                return null;
            }

            return {
                start_time: Math.floor(startTimeValue),
                client_id: Math.floor(clientId),
                service_type: serviceType,
                description: description
            };
        }

        function formatDuration(seconds) {
            const totalSeconds = Math.max(0, seconds);
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const secs = totalSeconds % 60;
            return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
        }

        function updateDuration() {
            if (!currentTimer) {
                timeElement.textContent = '00:00:00';
                return;
            }

            const elapsed = Math.floor(Date.now() / 1000) - currentTimer.start_time;
            timeElement.textContent = formatDuration(elapsed);
        }

        function stopTimerUpdate() {
            if (timerInterval) {
                clearInterval(timerInterval);
                timerInterval = null;
            }
        }

        function startTimerUpdate() {
            stopTimerUpdate();
            updateDuration();
            timerInterval = window.setInterval(updateDuration, 1000);
        }

        function renderTimer(timer) {
            currentTimer = timer;
            metaElement.textContent = timer.service_type;
            indicator.classList.remove('d-none');
            indicator.title = timer.description !== ''
                ? `${timer.service_type} — ${timer.description}`
                : timer.service_type;
            startTimerUpdate();
        }

        function hideTimer() {
            currentTimer = null;
            stopTimerUpdate();
            indicator.classList.add('d-none');
            indicator.removeAttribute('title');
            metaElement.textContent = '';
            timeElement.textContent = '00:00:00';
        }

        function loadStoredTimer() {
            try {
                const storedTimer = localStorage.getItem(storageKey);
                return normalizeActiveTimer(storedTimer ? JSON.parse(storedTimer) : null);
            } catch (error) {
                return null;
            }
        }

        function persistTimer(timer) {
            try {
                localStorage.setItem(storageKey, JSON.stringify(timer));
            } catch (error) {
                console.warn(error);
            }
        }

        function clearStoredTimer() {
            try {
                localStorage.removeItem(storageKey);
            } catch (error) {
                console.warn(error);
            }
        }

        function setActiveTimer(timer, options = {}) {
            const normalizedTimer = normalizeActiveTimer(timer);
            if (!normalizedTimer) {
                clearActiveTimer(options);
                return;
            }

            if (options.persist !== false) {
                persistTimer(normalizedTimer);
            }

            renderTimer(normalizedTimer);
        }

        function clearActiveTimer(options = {}) {
            if (options.clearStorage !== false) {
                clearStoredTimer();
            }

            hideTimer();
        }

        const initialTimer = serverTimer || loadStoredTimer();
        if (initialTimer) {
            setActiveTimer(initialTimer, { persist: !!serverTimer });
        }

        window.addEventListener('storage', function(event) {
            if (event.key !== storageKey) {
                return;
            }

            if (!event.newValue) {
                clearActiveTimer({ clearStorage: false });
                return;
            }

            try {
                setActiveTimer(JSON.parse(event.newValue), { persist: false });
            } catch (error) {
                clearActiveTimer({ clearStorage: false });
            }
        });

        window.bdtaActiveTimerIndicator = {
            setActiveTimer,
            clearActiveTimer,
            formatDuration
        };
    })();
    </script>
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
                        $isMoxieImport    = $currentFile === 'moxie_import.php';
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
                        $clientsOpen      = $isClients || $isPets || $isMoxieImport;
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
                                <button class="submenu-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#clientsSubmenu" aria-expanded="<?php echo $clientsOpen ? 'true' : 'false'; ?>" aria-controls="clientsSubmenu" aria-label="Toggle Clients submenu">
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                            </div>
                            <div class="collapse <?php echo $clientsOpen ? 'show' : ''; ?>" id="clientsSubmenu">
                                <ul class="nav flex-column submenu">
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $isPets ? 'active' : ''; ?>" href="pets_list.php">
                                            <i class="fas fa-dog me-2"></i> Pets
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?php echo $isMoxieImport ? 'active' : ''; ?>" href="moxie_import.php">
                                            <i class="fas fa-cloud-arrow-down me-2"></i> Import from Moxie
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
                                <button class="submenu-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#bookingsSubmenu" aria-expanded="<?php echo $bookingsOpen ? 'true' : 'false'; ?>" aria-controls="bookingsSubmenu" aria-label="Toggle Bookings submenu">
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
                                <button class="submenu-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#invoicesSubmenu" aria-expanded="<?php echo $invoicesOpen ? 'true' : 'false'; ?>" aria-controls="invoicesSubmenu" aria-label="Toggle Invoices submenu">
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
                                <button class="submenu-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#contractsSubmenu" aria-expanded="<?php echo $contractsOpen ? 'true' : 'false'; ?>" aria-controls="contractsSubmenu" aria-label="Toggle Contracts submenu">
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
                                <button class="submenu-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#formTplsSubmenu" aria-expanded="<?php echo $formTplsOpen ? 'true' : 'false'; ?>" aria-controls="formTplsSubmenu" aria-label="Toggle Form Templates submenu">
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
                                <button class="submenu-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#unmatchedSubmenu" aria-expanded="<?php echo $unmatchedOpen ? 'true' : 'false'; ?>" aria-controls="unmatchedSubmenu" aria-label="Toggle Unmatched Emails submenu">
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
                                <button class="submenu-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#workflowsSubmenu" aria-expanded="<?php echo $workflowsOpen ? 'true' : 'false'; ?>" aria-controls="workflowsSubmenu" aria-label="Toggle Workflows submenu">
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
                                <button class="submenu-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#settingsSubmenu" aria-expanded="<?php echo $settingsOpen ? 'true' : 'false'; ?>" aria-controls="settingsSubmenu" aria-label="Toggle Settings submenu">
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
                                    <li id="pwaInstallNavItem" class="nav-item">
                                        <button id="pwaInstallButton" class="nav-link disabled text-start w-100 border-0 bg-transparent" type="button" aria-label="Install the BDTA admin app" disabled>
                                            <i class="fas fa-download me-2"></i> Install App
                                        </button>
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
