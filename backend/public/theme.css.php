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

$primary         = sanitizeColor(array_string_value($theme, 'primary', '#9a0073'), '#9a0073');
$primary_dark    = sanitizeColor(array_string_value($theme, 'primary_dark', '#7a005a'), '#7a005a');
$secondary       = sanitizeColor(array_string_value($theme, 'secondary', '#0a9a9c'), '#0a9a9c');
$accent          = sanitizeColor(array_string_value($theme, 'accent', '#a39f89'), '#a39f89');
$sidebar_start   = sanitizeColor(array_string_value($theme, 'sidebar_bg_start', '#9a0073'), '#9a0073');
$sidebar_end     = sanitizeColor(array_string_value($theme, 'sidebar_bg_end', '#7a005a'), '#7a005a');
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

.bdta-section-hero {
    background: linear-gradient(135deg, var(--theme-sidebar-start) 0%, var(--theme-sidebar-end) 100%);
}

.bdta-content-narrow {
    max-width: 42rem;
}

.bdta-hero-button {
    color: var(--theme-primary);
}

.bdta-hero-button:hover,
.bdta-hero-button:focus {
    color: var(--theme-primary-dark);
}

.bdta-cta-button,
.bdta-cta-button:hover,
.bdta-cta-button:focus {
    color: #fff;
}

.bdta-feature-card {
    border: 0;
    border-radius: 1rem;
    box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.08);
}

.bdta-feature-icon {
    color: var(--theme-primary);
    font-size: 2rem;
    line-height: 1;
}

.bdta-testimonial {
    border-left: 4px solid var(--theme-primary);
    border-radius: 0.75rem;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.06);
}

.bdta-testimonial cite {
    color: var(--theme-primary);
    font-style: normal;
}

.bdta-contact-bar {
    background: #2d2d2d;
}

.bdta-contact-link {
    color: var(--theme-secondary);
    text-decoration: none;
}

.bdta-contact-link:hover,
.bdta-contact-link:focus {
    color: #fff;
}

@media (max-width: 767.98px) {
    .bdta-section-hero .display-5,
    .bdta-section-hero .display-4 {
        font-size: 2.25rem;
    }
}
