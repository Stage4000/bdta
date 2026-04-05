<?php require_once dirname(__DIR__, 2) . '/backend/public/includes/public_navigation.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <?php
    $resolved_page_title = isset($page_title) ? scalar_string($page_title) : '';
    $resolved_meta_description = isset($meta_description) ? scalar_string($meta_description) : '';
    $resolved_meta_og_title = isset($meta_og_title) ? scalar_string($meta_og_title) : $resolved_page_title;
    $resolved_meta_og_description = isset($meta_og_description) ? scalar_string($meta_og_description) : $resolved_meta_description;
    $resolved_meta_og_image = isset($meta_og_image) ? scalar_string($meta_og_image) : '';
    $resolved_meta_og_type = isset($meta_og_type) ? scalar_string($meta_og_type) : 'website';
    $resolved_twitter_card = $resolved_meta_og_image !== '' ? 'summary_large_image' : 'summary';
    ?>
    <?php if ($resolved_meta_description !== ''): ?>
    <meta name="description" content="<?php echo escape($resolved_meta_description); ?>">
    <?php endif; ?>
    <?php if ($resolved_meta_og_title !== ''): ?>
    <meta property="og:title" content="<?php echo escape($resolved_meta_og_title); ?>">
    <meta name="twitter:title" content="<?php echo escape($resolved_meta_og_title); ?>">
    <?php endif; ?>
    <meta property="og:type" content="<?php echo escape($resolved_meta_og_type); ?>">
    <?php if ($resolved_meta_og_description !== ''): ?>
    <meta property="og:description" content="<?php echo escape($resolved_meta_og_description); ?>">
    <meta name="twitter:description" content="<?php echo escape($resolved_meta_og_description); ?>">
    <?php endif; ?>
    <?php if ($resolved_meta_og_image !== ''): ?>
    <meta property="og:image" content="<?php echo escape($resolved_meta_og_image); ?>">
    <meta name="twitter:image" content="<?php echo escape($resolved_meta_og_image); ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="<?php echo escape($resolved_twitter_card); ?>">
    <title><?php echo $resolved_page_title !== '' ? escape($resolved_page_title) . ' - ' : ''; ?>Brook's Dog Training Academy</title>
    <script src="/assets/js/theme-init.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="../assets/css/public/site.css" rel="stylesheet">
</head>
<body>
    <?php ob_start(); ?>
    <nav class="navbar navbar-expand-lg navbar-light bg-white fixed-top shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="../index.html">
                <i class="fas fa-paw text-primary me-2"></i>Brook's Dog Training Academy
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="../index.html#home">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="../index.html#about">About</a></li>
                    <li class="nav-item"><a class="nav-link" href="../index.html#services">Services</a></li>
                    <li class="nav-item"><a class="nav-link" href="../index.html#events">Events</a></li>
                    <li class="nav-item"><a class="nav-link" href="../index.html#testimonials">Testimonials</a></li>
                    <li class="nav-item"><a class="nav-link" href="../index.html#contact">Contact</a></li>
                    <li class="nav-item"><a class="nav-link active" href="index.php">Blog</a></li>
                    <li class="nav-item ms-lg-2">
                        <button id="darkModeToggle" class="btn btn-outline-secondary btn-sm" title="Toggle dark mode" aria-label="Toggle dark mode">
                            <i class="fas fa-moon" id="darkModeIcon"></i>
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <?php echo bdta_sync_public_navigation_links((string) ob_get_clean()); ?>

    <main style="margin-top: 80px;">
