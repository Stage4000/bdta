<?php
/**
 * Brook's Dog Training Academy - Homepage Router
 * Serves the homepage from the database (if published via the site editor),
 * otherwise falls back to the static index.html file.
 */

require_once __DIR__ . '/backend/includes/config.php';
require_once __DIR__ . '/backend/includes/newsletter_embed.php';
require_once __DIR__ . '/backend/includes/social_links.php';
require_once __DIR__ . '/backend/includes/public_notice.php';
require_once __DIR__ . '/backend/includes/tawk_to.php';
require_once __DIR__ . '/backend/includes/turnstile.php';
require_once __DIR__ . '/backend/public/includes/public_navigation.php';
require_once __DIR__ . '/backend/public/includes/public_services.php';

$db   = new Database();
$conn = $db->getConnection();

$stmt = $conn->query(
    "SELECT * FROM site_pages WHERE is_homepage = 1 AND is_published = 1 LIMIT 1"
);
$page = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$page || trim(array_string_value($page, 'html_content')) === '') {
    // Fall back to the static index.html
    $static = __DIR__ . '/index.html';
    if (file_exists($static)) {
        $html = file_get_contents($static);
        if ($html === false) {
            $last_error = error_get_last();
            $read_error_message = is_array($last_error) ? scalar_string($last_error['message']) : 'unknown error';
            error_log('Failed to read static homepage: ' . $read_error_message);
            header('Content-Type: text/html; charset=UTF-8');
            http_response_code(500);
            echo '<h1>Site temporarily unavailable.</h1>';
            exit;
        }

        $html = scalar_string($html);
        $html = bdta_apply_public_social_links($html);
        $html = bdta_inject_imported_page_runtime_css($html);
        $html = bdta_inject_public_notice_markup($html);
        $html = bdta_inject_newsletter_embed_markup($html);
        $widget = bdta_get_tawk_to_widget_script();
        if ($widget !== '') {
            $html = preg_replace('/<\/body>/i', $widget . "\n</body>", $html, 1) ?? $html;
        }
        $html = bdta_sync_public_navigation_links($html);
        $html = bdta_prepare_public_html_with_turnstile($html);
        echo $html;
    } else {
        echo '<h1>Site coming soon.</h1>';
    }
    exit;
}

$meta_desc    = htmlspecialchars($page['meta_description'] ?? '', ENT_QUOTES, 'UTF-8');
$meta_keywords = htmlspecialchars($page['meta_keywords']    ?? '', ENT_QUOTES, 'UTF-8');
$seo_title    = htmlspecialchars(!empty($page['og_title'])  ? $page['og_title']  : $page['title'], ENT_QUOTES, 'UTF-8');
$og_desc      = htmlspecialchars(!empty($page['og_description']) ? $page['og_description'] : ($page['meta_description'] ?? ''), ENT_QUOTES, 'UTF-8');
$og_image     = htmlspecialchars($page['og_image'] ?? '', ENT_QUOTES, 'UTF-8');
$title        = htmlspecialchars($page['title'], ENT_QUOTES, 'UTF-8');
$page_html_content = bdta_inject_public_services_into_homepage(array_string_value($page, 'html_content'));
$rendered_page_html = bdta_inject_turnstile_widgets_into_forms(
    bdta_wrap_imported_page_html(
        bdta_sync_public_navigation_links(bdta_apply_public_social_links($page_html_content))
    )
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
    <!-- AOS Animation Library -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script src="/assets/js/theme-toggle.js"></script>
    <?php if ($page_has_turnstile_widget): ?>
    <?php echo bdta_get_turnstile_assets_html(); ?>
    <?php endif; ?>
    <!-- Custom JS (loads dynamic Package and Event modules, etc.) -->
    <script src="/assets/js/public/site.js"></script>
    <!-- BDTA dynamic modules (Packages & Events blocks added via the site editor) -->
    <script src="/assets/js/public/modules.js"></script>
    <?php bdta_render_tawk_to_widget(); ?>
</body>
</html>
