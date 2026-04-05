<?php
/**
 * Public social link helpers and setting definitions.
 */

/**
 * @return list<array{setting_key:string, setting_value:string, setting_type:string, category:string, label:string, description:string, is_secret:int, icon:string}>
 */
function bdta_get_supported_social_link_settings(): array {
    return [
        ['setting_key' => 'facebook_url', 'setting_value' => 'https://www.facebook.com/BrooksDogTrainingAcademy', 'setting_type' => 'url', 'category' => 'social', 'label' => 'Facebook URL', 'description' => 'Facebook page URL', 'is_secret' => 0, 'icon' => 'fab fa-facebook-f'],
        ['setting_key' => 'instagram_url', 'setting_value' => 'https://www.instagram.com/brooksdogtrainingacademy', 'setting_type' => 'url', 'category' => 'social', 'label' => 'Instagram URL', 'description' => 'Instagram profile URL', 'is_secret' => 0, 'icon' => 'fab fa-instagram'],
        ['setting_key' => 'linktree_url', 'setting_value' => 'https://linktr.ee/brooksdogtrainingacademy', 'setting_type' => 'url', 'category' => 'social', 'label' => 'Linktree URL', 'description' => 'Linktree profile URL', 'is_secret' => 0, 'icon' => 'fas fa-link'],
        ['setting_key' => 'tiktok_url', 'setting_value' => '', 'setting_type' => 'url', 'category' => 'social', 'label' => 'TikTok URL', 'description' => 'TikTok profile URL', 'is_secret' => 0, 'icon' => 'fab fa-tiktok'],
        ['setting_key' => 'youtube_url', 'setting_value' => '', 'setting_type' => 'url', 'category' => 'social', 'label' => 'YouTube URL', 'description' => 'YouTube channel URL', 'is_secret' => 0, 'icon' => 'fab fa-youtube'],
        ['setting_key' => 'twitter_x_url', 'setting_value' => '', 'setting_type' => 'url', 'category' => 'social', 'label' => 'Twitter / X URL', 'description' => 'Twitter / X profile URL', 'is_secret' => 0, 'icon' => 'fab fa-x-twitter'],
        ['setting_key' => 'threads_url', 'setting_value' => '', 'setting_type' => 'url', 'category' => 'social', 'label' => 'Threads URL', 'description' => 'Threads profile URL', 'is_secret' => 0, 'icon' => 'fab fa-threads'],
        ['setting_key' => 'nextdoor_url', 'setting_value' => '', 'setting_type' => 'url', 'category' => 'social', 'label' => 'Nextdoor URL', 'description' => 'Nextdoor business page URL', 'is_secret' => 0, 'icon' => 'fas fa-house'],
        ['setting_key' => 'patreon_url', 'setting_value' => '', 'setting_type' => 'url', 'category' => 'social', 'label' => 'Patreon URL', 'description' => 'Patreon page URL', 'is_secret' => 0, 'icon' => 'fab fa-patreon'],
        ['setting_key' => 'pinterest_url', 'setting_value' => '', 'setting_type' => 'url', 'category' => 'social', 'label' => 'Pinterest URL', 'description' => 'Pinterest profile URL', 'is_secret' => 0, 'icon' => 'fab fa-pinterest-p'],
        ['setting_key' => 'snapchat_url', 'setting_value' => '', 'setting_type' => 'url', 'category' => 'social', 'label' => 'Snapchat URL', 'description' => 'Snapchat profile URL', 'is_secret' => 0, 'icon' => 'fab fa-snapchat'],
        ['setting_key' => 'linkedin_url', 'setting_value' => '', 'setting_type' => 'url', 'category' => 'social', 'label' => 'LinkedIn URL', 'description' => 'LinkedIn profile or company URL', 'is_secret' => 0, 'icon' => 'fab fa-linkedin-in'],
        ['setting_key' => 'bluesky_url', 'setting_value' => '', 'setting_type' => 'url', 'category' => 'social', 'label' => 'Bluesky URL', 'description' => 'Bluesky profile URL', 'is_secret' => 0, 'icon' => 'custom:bluesky-butterfly'],
        ['setting_key' => 'yelp_url', 'setting_value' => '', 'setting_type' => 'url', 'category' => 'social', 'label' => 'Yelp URL', 'description' => 'Yelp business page URL', 'is_secret' => 0, 'icon' => 'fab fa-yelp'],
        ['setting_key' => 'substack_url', 'setting_value' => '', 'setting_type' => 'url', 'category' => 'social', 'label' => 'Substack URL', 'description' => 'Substack publication URL', 'is_secret' => 0, 'icon' => 'fas fa-newspaper'],
    ];
}

/**
 * @return list<array{setting_key:string, setting_value:string, setting_type:string, category:string, label:string, description:string, is_secret:int}>
 */
function bdta_get_custom_social_link_settings(): array {
    $settings = [];

    for ($index = 1; $index <= 5; $index++) {
        $settings[] = [
            'setting_key' => 'custom_social_link_' . $index . '_label',
            'setting_value' => '',
            'setting_type' => 'text',
            'category' => 'social',
            'label' => 'Custom Link ' . $index . ' Label',
            'description' => 'Short label shown on the website for custom link ' . $index . '.',
            'is_secret' => 0,
        ];
        $settings[] = [
            'setting_key' => 'custom_social_link_' . $index . '_url',
            'setting_value' => '',
            'setting_type' => 'url',
            'category' => 'social',
            'label' => 'Custom Link ' . $index . ' URL',
            'description' => 'Website URL for custom link ' . $index . '. Leave blank to hide it.',
            'is_secret' => 0,
        ];
    }

    return $settings;
}

/**
 * @return list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:int}>
 */
function bdta_get_social_settings_seed_rows(): array {
    $rows = [];

    foreach (bdta_get_supported_social_link_settings() as $setting) {
        $rows[] = [
            $setting['setting_key'],
            $setting['setting_value'],
            $setting['setting_type'],
            $setting['category'],
            $setting['label'],
            $setting['description'],
            $setting['is_secret'],
        ];
    }

    foreach (bdta_get_custom_social_link_settings() as $setting) {
        $rows[] = [
            $setting['setting_key'],
            $setting['setting_value'],
            $setting['setting_type'],
            $setting['category'],
            $setting['label'],
            $setting['description'],
            $setting['is_secret'],
        ];
    }

    return $rows;
}

function bdta_social_string(mixed $value): string {
    if (is_string($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value) || is_bool($value)) {
        return (string) $value;
    }

    return '';
}

function bdta_social_map_value(array $row, string $key): string {
    return isset($row[$key]) ? bdta_social_string($row[$key]) : '';
}

/**
 * @param array<string, mixed>|list<array<string, mixed>> $settings
 * @return array<string, string>
 */
function bdta_normalize_social_settings(array $settings): array {
    $normalized = [];

    foreach ($settings as $key => $value) {
        if (is_int($key) && is_array($value)) {
            $setting_key = trim(bdta_social_map_value($value, 'key'));
            if ($setting_key === '') {
                continue;
            }

            $normalized[$setting_key] = trim(
                bdta_social_map_value($value, 'actual_value') !== ''
                    ? bdta_social_map_value($value, 'actual_value')
                    : bdta_social_map_value($value, 'value')
            );
            continue;
        }

        $normalized[(string) $key] = trim(bdta_social_string($value));
    }

    return $normalized;
}

function bdta_sanitize_public_social_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    $validated = filter_var($url, FILTER_VALIDATE_URL);
    if (!is_string($validated) || $validated === '') {
        return '';
    }

    $scheme = strtolower((string) parse_url($validated, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    return $validated;
}

function bdta_get_custom_social_link_label(string $label, string $url, int $index): string {
    $label = trim($label);
    if ($label !== '') {
        return $label;
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        return preg_replace('/^www\./i', '', $host) ?? $host;
    }

    return 'Custom Link ' . $index;
}

/**
 * @param array<string, mixed>|list<array<string, mixed>> $settings
 * @return list<array{name:string,url:string,icon:string}>
 */
function bdta_collect_social_links(array $settings): array {
    $settings_map = bdta_normalize_social_settings($settings);
    $links = [];

    foreach (bdta_get_supported_social_link_settings() as $setting) {
        $url = bdta_sanitize_public_social_url($settings_map[$setting['setting_key']] ?? '');
        if ($url === '') {
            continue;
        }

        $links[] = [
            'name' => preg_replace('/\s+URL$/', '', $setting['label']) ?? $setting['label'],
            'url' => $url,
            'icon' => $setting['icon'],
        ];
    }

    for ($index = 1; $index <= 5; $index++) {
        $url = bdta_sanitize_public_social_url($settings_map['custom_social_link_' . $index . '_url'] ?? '');
        if ($url === '') {
            continue;
        }

        $links[] = [
            'name' => bdta_get_custom_social_link_label($settings_map['custom_social_link_' . $index . '_label'] ?? '', $url, $index),
            'url' => $url,
            'icon' => 'fas fa-link',
        ];
    }

    return $links;
}

/**
 * @return list<array{name:string,url:string,icon:string}>
 */
function bdta_get_public_social_links(): array {
    require_once __DIR__ . '/settings.php';

    return bdta_collect_social_links(Settings::getCategory('social'));
}

/**
 * @param list<array{name:string,url:string,icon:string}> $links
 */
function bdta_render_social_link_buttons(array $links, string $container_classes, string $button_classes): string {
    if ($links === []) {
        return '';
    }

    $html = '<div class="' . htmlspecialchars($container_classes, ENT_QUOTES, 'UTF-8') . '">';
    foreach ($links as $link) {
        $name = htmlspecialchars($link['name'], ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8');
        $aria_label = htmlspecialchars('Visit us on ' . $link['name'] . ' (opens in new tab)', ENT_QUOTES, 'UTF-8');

        $html .= '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" class="' . htmlspecialchars($button_classes, ENT_QUOTES, 'UTF-8') . '" aria-label="' . $aria_label . '">';
        $html .= bdta_render_social_link_icon($link['icon']);
        $html .= '</a>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * @param list<array{name:string,url:string,icon:string}> $links
 */
function bdta_render_public_social_links_block(string $slot, array $links): string {
    if ($links === []) {
        return '';
    }

    if ($slot === 'events') {
        return '<div class="text-center mt-5">' .
            '<p class="lead mb-3">Follow us on social media for event updates and training tips!</p>' .
            bdta_render_social_link_buttons($links, 'social-links d-flex gap-3 justify-content-center', 'btn btn-primary rounded-circle d-flex align-items-center justify-content-center circular-icon-button') .
            '</div>';
    }

    if ($slot === 'contact') {
        return '<div class="mt-4">' .
            '<h3 class="h5 fw-bold mb-3">Follow Us</h3>' .
            bdta_render_social_link_buttons($links, 'social-links d-flex gap-3 flex-wrap', 'btn btn-outline-primary rounded-circle d-flex align-items-center justify-content-center circular-icon-button circular-icon-button-md') .
            '</div>';
    }

    if ($slot === 'footer') {
        return bdta_render_social_link_buttons($links, 'social-links d-flex gap-2 flex-wrap', 'btn btn-outline-light btn-sm rounded-circle d-flex align-items-center justify-content-center circular-icon-button circular-icon-button-sm');
    }

    return '';
}

function bdta_replace_public_social_links_slot(string $html, string $slot, string $replacement): string {
    $start_marker = '<!-- BDTA_SOCIAL_LINKS:' . $slot . ' -->';
    $end_marker = '<!-- /BDTA_SOCIAL_LINKS:' . $slot . ' -->';
    $pattern = '/' . preg_quote($start_marker, '/') . '.*?' . preg_quote($end_marker, '/') . '/s';
    $rendered = $start_marker . "\n" . $replacement . "\n" . $end_marker;

    return preg_replace($pattern, $rendered, $html, 1) ?? $html;
}

/**
 * @param list<array{name:string,url:string,icon:string}>|null $links
 */
function bdta_apply_public_social_links(string $html, ?array $links = null): string {
    $links = $links ?? bdta_get_public_social_links();

    foreach (['events', 'contact', 'footer'] as $slot) {
        $html = bdta_replace_public_social_links_slot($html, $slot, bdta_render_public_social_links_block($slot, $links));
    }

    return $html;
}

function bdta_render_social_link_icon(string $icon): string {
    if ($icon === 'custom:bluesky-butterfly') {
        return '<span class="bdta-social-icon bdta-social-icon-bluesky" aria-hidden="true"></span>';
    }

    return '<i class="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i>';
}
