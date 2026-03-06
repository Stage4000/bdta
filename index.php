<?php
/**
 * Brook's Dog Training Academy - Homepage Router
 * Serves the homepage from the database (if published via the site editor),
 * otherwise falls back to the static index.html file.
 */

require_once __DIR__ . '/backend/includes/config.php';

$db   = new Database();
$conn = $db->getConnection();

$stmt = $conn->query(
    "SELECT * FROM site_pages WHERE is_homepage = 1 AND is_published = 1 LIMIT 1"
);
$page = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

if (!$page || trim($page['html_content']) === '') {
    // Fall back to the static index.html
    $static = __DIR__ . '/index.html';
    if (file_exists($static)) {
        readfile($static);
    } else {
        echo '<h1>Site coming soon.</h1>';
    }
    exit;
}

$meta_desc = htmlspecialchars($page['meta_description'] ?? '', ENT_QUOTES, 'UTF-8');
$title     = htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($meta_desc): ?>
    <meta name="description" content="<?php echo $meta_desc; ?>">
    <?php endif; ?>
    <meta name="color-scheme" content="light dark">
    <title><?php echo $title; ?> — Brook's Dog Training Academy</title>

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
    <!-- AOS Animation Library -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <!-- Custom JS (loads dynamic Package and Event modules, etc.) -->
    <script src="/js/script.js"></script>
</body>
</html>
