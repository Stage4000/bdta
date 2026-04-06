<?php require_once dirname(__DIR__, 2) . '/backend/public/includes/public_navigation.php'; ?>
<?php
$meta_description = isset($meta_description) ? scalar_string($meta_description) : '';
$meta_keywords = isset($meta_keywords) ? scalar_string($meta_keywords) : '';
$seo_title = isset($seo_title) ? scalar_string($seo_title) : '';
$og_description = isset($og_description) ? scalar_string($og_description) : '';
$og_image = isset($og_image) ? scalar_string($og_image) : '';
$og_type = isset($og_type) && scalar_string($og_type) !== '' ? scalar_string($og_type) : 'website';
$browser_title = $seo_title !== '' ? $seo_title : (isset($page_title) ? scalar_string($page_title) : '');
$og_image_alt = isset($og_image_alt) && scalar_string($og_image_alt) !== '' ? scalar_string($og_image_alt) : $browser_title;
$twitter_card = $og_image !== '' ? 'summary_large_image' : 'summary';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($meta_description !== ''): ?>
    <meta name="description" content="<?php echo escape($meta_description); ?>">
    <?php endif; ?>
    <?php if ($meta_keywords !== ''): ?>
    <meta name="keywords" content="<?php echo escape($meta_keywords); ?>">
    <?php endif; ?>
    <meta property="og:type" content="<?php echo escape($og_type); ?>">
    <?php if ($browser_title !== ''): ?>
    <meta property="og:title" content="<?php echo escape($browser_title); ?>">
    <meta name="twitter:title" content="<?php echo escape($browser_title); ?>">
    <?php endif; ?>
    <?php if ($og_description !== ''): ?>
    <meta property="og:description" content="<?php echo escape($og_description); ?>">
    <meta name="twitter:description" content="<?php echo escape($og_description); ?>">
    <?php endif; ?>
    <?php if ($og_image !== ''): ?>
    <meta property="og:image" content="<?php echo escape($og_image); ?>">
    <meta name="twitter:image" content="<?php echo escape($og_image); ?>">
    <?php if ($og_image_alt !== ''): ?>
    <meta name="twitter:image:alt" content="<?php echo escape($og_image_alt); ?>">
    <?php endif; ?>
    <?php endif; ?>
    <meta name="twitter:card" content="<?php echo escape($twitter_card); ?>">
    <meta name="color-scheme" content="light dark">
    <title><?php echo $browser_title !== '' ? escape($browser_title) . ' - ' : ''; ?>Brook's Dog Training Academy</title>
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
