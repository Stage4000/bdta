<?php
/**
 * Dynamic Theme CSS Endpoint
 * Outputs CSS custom properties (variables) based on stored theme settings.
 * This file is referenced by the public website, admin panel, and portal.
 */

// Determine path to backend includes based on this file's location
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/settings.php';

header('Content-Type: text/css; charset=UTF-8');
// Short cache so admin color changes appear within 60 seconds
header('Cache-Control: public, max-age=60');

$theme = Settings::getThemeColors();

// Sanitize hex colors to prevent CSS injection (only allow valid hex color format)
function sanitizeColor(string $color, string $default): string {
    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
        return $color;
    }
    return $default;
}

$primary         = sanitizeColor($theme['primary'],        '#9a0073');
$primary_dark    = sanitizeColor($theme['primary_dark'],   '#7a005a');
$secondary       = sanitizeColor($theme['secondary'],      '#0a9a9c');
$accent          = sanitizeColor($theme['accent'],         '#a39f89');
$sidebar_start   = sanitizeColor($theme['sidebar_bg_start'], '#9a0073');
$sidebar_end     = sanitizeColor($theme['sidebar_bg_end'],   '#7a005a');
?>
:root {
    --theme-primary:        <?= $primary ?>;
    --theme-primary-dark:   <?= $primary_dark ?>;
    --theme-secondary:      <?= $secondary ?>;
    --theme-accent:         <?= $accent ?>;
    --theme-sidebar-start:  <?= $sidebar_start ?>;
    --theme-sidebar-end:    <?= $sidebar_end ?>;

    /* Map to existing variable names for backward compatibility */
    --primary-color:  <?= $primary ?>;
    --primary-dark:   <?= $primary_dark ?>;
    --secondary-color: <?= $secondary ?>;
    --accent-color:   <?= $accent ?>;
}
