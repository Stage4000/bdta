<?php require_once dirname(__DIR__, 2) . '/includes/turnstile.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title><?php echo (isset($page_title) && $page_title !== '') ? htmlspecialchars(scalar_string($page_title), ENT_QUOTES, 'UTF-8') . ' - ' : ''; ?>Brook's Dog Training Academy</title>
    <script src="/assets/js/theme-init.js"></script>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <?php echo bdta_get_turnstile_assets_html(); ?>
