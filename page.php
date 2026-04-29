<?php
/**
 * Brook's Dog Training Academy - Front-End Page Renderer
 * Serves dynamic site pages stored in the database by slug.
 * Usage: /page.php?slug=about-us
 */

require_once __DIR__ . '/backend/includes/config.php';
require_once __DIR__ . '/backend/includes/newsletter_embed.php';
require_once __DIR__ . '/backend/public/includes/public_error_page.php';
require_once __DIR__ . '/backend/public/includes/public_navigation.php';
require_once __DIR__ . '/backend/includes/public_notice.php';
require_once __DIR__ . '/backend/includes/tawk_to.php';
require_once __DIR__ . '/backend/includes/turnstile.php';

$db   = new Database();
$conn = $db->getConnection();

$slug = trim(scalar_string($_GET['slug'] ?? ''));

// Sanitise slug: only allow lowercase alphanumeric and hyphens
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));

if ($slug === '') {
    renderPublicErrorPage(
        'Page Not Found',
        'Page Not Found',
        'No page slug was specified for this page request.',
        404
    );
}

$stmt = $conn->prepare(
    "SELECT * FROM site_pages WHERE slug = ? AND is_published = 1 AND is_homepage = 0"
);
$stmt->execute([$slug]);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$page) {
    renderPublicErrorPage(
        'Page Not Found',
        'Page Not Found',
        'The page you are looking for does not exist or is not published yet.',
        404
    );
}

$meta_desc    = htmlspecialchars($page['meta_description'] ?? '', ENT_QUOTES, 'UTF-8');
$meta_keywords = htmlspecialchars($page['meta_keywords']    ?? '', ENT_QUOTES, 'UTF-8');
$seo_title    = htmlspecialchars(!empty($page['og_title'])  ? $page['og_title']  : $page['title'], ENT_QUOTES, 'UTF-8');
$og_desc      = htmlspecialchars(!empty($page['og_description']) ? $page['og_description'] : ($page['meta_description'] ?? ''), ENT_QUOTES, 'UTF-8');
$og_image     = htmlspecialchars($page['og_image'] ?? '', ENT_QUOTES, 'UTF-8');
$title        = htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8');
$rendered_page_html = bdta_inject_turnstile_widgets_into_forms(
    bdta_wrap_imported_page_html(bdta_sync_public_navigation_links((string) $page['html_content']))
);
$rendered_page_html = bdta_inject_newsletter_embed_markup($rendered_page_html);
$page_has_turnstile_widget = str_contains($rendered_page_html, 'bdta-turnstile');
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

    <script src="/assets/js/theme-init.js"></script>

    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/public/site.css">
    <link rel="stylesheet" href="/backend/public/theme.css.php">

    <?php if (!empty($page['css_content'])): ?>
    <style>
        <?php echo $page['css_content']; ?>
    </style>
    <?php endif; ?>
    <style>
        <?php echo bdta_get_imported_page_runtime_css(); ?>
    </style>
</head>
<body>
    <?php echo $rendered_page_html; ?>
    <?php bdta_render_public_notice(); ?>
    <?php echo bdta_get_public_theme_toggle_button_html(); ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/theme-toggle.js"></script>
    <?php if ($page_has_turnstile_widget): ?>
    <?php echo bdta_get_turnstile_assets_html(); ?>
    <?php endif; ?>
    <!-- BDTA dynamic modules (Packages & Events blocks added via the site editor) -->
    <script src="/assets/js/public/modules.js"></script>
    <?php bdta_render_tawk_to_widget(); ?>
</body>
</html>
