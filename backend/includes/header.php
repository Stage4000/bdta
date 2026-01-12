<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? escape($page_title) : 'Admin Panel'; ?> - BDTA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/mobile.css">
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
            background: rgba(10,154,156,0.3);
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
    
    <?php if (isLoggedIn()): ?>
    <div class="container-fluid">
        <div class="row">
            <!-- Mobile menu toggle button -->
            <nav class="navbar navbar-dark d-md-none" style="background: linear-gradient(135deg, #9a0073 0%, #7a005a 100%);">
                <div class="container-fluid">
                    <span class="navbar-brand">BDTA Admin</span>
                    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu" aria-controls="sidebarMenu" aria-expanded="false" aria-label="Toggle navigation">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                </div>
            </nav>
            
            <nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block sidebar collapse">
                <div class="position-sticky pt-3">
                    <h5 class="text-white px-3 mb-3 d-none d-md-block">BDTA Admin</h5>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="index.php">
                                <i class="bi bi-speedometer2 me-2"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'client') !== false ? 'active' : ''; ?>" href="clients_list.php">
                                <i class="bi bi-people me-2"></i> Clients
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'pets') !== false ? 'active' : ''; ?>" href="pets_list.php">
                                <i class="bi bi-heart-fill me-2"></i> Pets
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'booking') !== false ? 'active' : ''; ?>" href="bookings_list.php">
                                <i class="bi bi-calendar-check me-2"></i> Bookings
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'time_entries') !== false || strpos($_SERVER['PHP_SELF'], 'time_tracker') !== false ? 'active' : ''; ?>" href="time_tracker.php">
                                <i class="bi bi-stopwatch me-2"></i> Time Tracker
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'expense') !== false ? 'active' : ''; ?>" href="expenses_list.php">
                                <i class="bi bi-receipt me-2"></i> Expenses
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'invoice') !== false ? 'active' : ''; ?>" href="invoices_list.php">
                                <i class="bi bi-file-earmark-text me-2"></i> Invoices
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'contract') !== false && strpos($_SERVER['PHP_SELF'], 'template') === false ? 'active' : ''; ?>" href="contracts_list.php">
                                <i class="bi bi-file-earmark-check me-2"></i> Contracts
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'contract_template') !== false ? 'active' : ''; ?>" href="contract_templates_list.php">
                                <i class="bi bi-file-earmark-medical me-2"></i> Contract Templates
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'quote') !== false ? 'active' : ''; ?>" href="quotes_list.php">
                                <i class="bi bi-file-earmark-text me-2"></i> Quotes
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'blog') !== false ? 'active' : ''; ?>" href="blog_list.php">
                                <i class="bi bi-pencil-square me-2"></i> Blog Posts
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'appointment_types') !== false ? 'active' : ''; ?>" href="appointment_types_list.php">
                                <i class="bi bi-calendar-check-fill me-2"></i> Appointment Types
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'form_templates') !== false ? 'active' : ''; ?>" href="form_templates_list.php">
                                <i class="bi bi-file-text me-2"></i> Form Templates
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'email_templates') !== false ? 'active' : ''; ?>" href="email_templates_list.php">
                                <i class="bi bi-envelope me-2"></i> Email Templates
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo strpos($_SERVER['PHP_SELF'], 'form_submissions') !== false ? 'active' : ''; ?>" href="form_submissions_list.php">
                                <i class="bi bi-file-earmark-check me-2"></i> Form Submissions
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>" href="settings.php">
                                <i class="bi bi-gear me-2"></i> Settings
                            </a>
                        </li>
                        <li class="nav-item mt-3">
                            <a class="nav-link" href="../../index.html" target="_blank">
                                <i class="bi bi-house me-2"></i> View Website
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="logout.php">
                                <i class="bi bi-box-arrow-right me-2"></i> Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
<?php else: ?>
<main class="container mt-5">
<?php endif; ?>
