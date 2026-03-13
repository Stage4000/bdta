<?php
/**
 * Brook's Dog Training Academy - Front-End Page Renderer
 * Serves dynamic site pages stored in the database by slug.
 * Usage: /page.php?slug=about-us
 */

require_once __DIR__ . '/backend/includes/config.php';

$db   = new Database();
$conn = $db->getConnection();

$slug = trim(scalar_string($_GET['slug'] ?? ''));

// Sanitise slug: only allow lowercase alphanumeric and hyphens
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));

if ($slug === '') {
    http_response_code(404);
    echo '<h1>404 Not Found</h1><p>No page slug specified.</p>';
    exit;
}

$stmt = $conn->prepare(
    "SELECT * FROM site_pages WHERE slug = ? AND is_published = 1 AND is_homepage = 0"
);
$stmt->execute([$slug]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$page) {
    http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Not Found — Brook's Dog Training Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 bg-light">
    <div class="text-center">
        <h1 class="display-1 fw-bold text-muted">404</h1>
        <h2 class="mb-3">Page Not Found</h2>
        <p class="text-muted mb-4">The page you're looking for doesn't exist or isn't published yet.</p>
        <a href="/" class="btn btn-primary">Go Home</a>
    </div>
</body>
</html>
<?php
    exit;
}

$meta_desc    = htmlspecialchars($page['meta_description'] ?? '', ENT_QUOTES, 'UTF-8');
$meta_keywords = htmlspecialchars($page['meta_keywords']    ?? '', ENT_QUOTES, 'UTF-8');
$seo_title    = htmlspecialchars(!empty($page['og_title'])  ? $page['og_title']  : $page['title'], ENT_QUOTES, 'UTF-8');
$og_desc      = htmlspecialchars(!empty($page['og_description']) ? $page['og_description'] : ($page['meta_description'] ?? ''), ENT_QUOTES, 'UTF-8');
$og_image     = htmlspecialchars($page['og_image'] ?? '', ENT_QUOTES, 'UTF-8');
$title        = htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($meta_desc): ?>
    <meta name="description" content="<?php echo $meta_desc; ?>">
    <?php endif; ?>
    <?php if ($meta_keywords): ?>
    <meta name="keywords" content="<?php echo $meta_keywords; ?>">
    <?php endif; ?>
    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?php echo $seo_title; ?>">
    <?php if ($og_desc): ?>
    <meta property="og:description" content="<?php echo $og_desc; ?>">
    <?php endif; ?>
    <?php if ($og_image): ?>
    <meta property="og:image" content="<?php echo $og_image; ?>">
    <?php endif; ?>
    <!-- Twitter Card -->
    <meta name="twitter:card" content="<?php echo $og_image ? 'summary_large_image' : 'summary'; ?>">
    <meta name="twitter:title" content="<?php echo $seo_title; ?>">
    <?php if ($og_desc): ?>
    <meta name="twitter:description" content="<?php echo $og_desc; ?>">
    <?php endif; ?>
    <?php if ($og_image): ?>
    <meta name="twitter:image" content="<?php echo $og_image; ?>">
    <?php endif; ?>
    <meta name="color-scheme" content="light dark">
    <title><?php echo $seo_title; ?> — Brook's Dog Training Academy</title>

    <!-- Dark mode: respect saved user preference -->
    <script>
        (function () {
            'use strict';
            var saved = localStorage.getItem('bdta-theme');
            var theme = saved ? saved : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-bs-theme', theme);
        }());
    </script>

    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/backend/public/theme.css.php">

    <?php if (!empty($page['css_content'])): ?>
    <style>
        <?php echo $page['css_content']; ?>
    </style>
    <?php endif; ?>
</head>
<body>
    <?php echo $page['html_content']; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- BDTA dynamic modules (Packages & Events blocks added via the site editor) -->
    <script src="/js/bdta-modules.js"></script>
</body>
</html>
