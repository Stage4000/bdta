<?php

if (!function_exists('bdta_get_theme_palette')) {
    /**
     * @return array{primary: string, primary_dark: string, secondary: string, sidebar_start: string, sidebar_end: string}
     */
    function bdta_get_theme_palette(): array
    {
        $theme = Settings::getThemeColors();

        $sanitizeHex = static function ($value, string $fallback): string {
            $candidate = scalar_string($value ?? '');
            return preg_match('/^#[0-9A-Fa-f]{6}$/', $candidate) === 1 ? $candidate : $fallback;
        };

        return [
            'primary' => $sanitizeHex($theme['primary'] ?? '', '#9a0073'),
            'primary_dark' => $sanitizeHex($theme['primary_dark'] ?? '', '#7a005a'),
            'secondary' => $sanitizeHex($theme['secondary'] ?? '', '#0a9a9c'),
            'sidebar_start' => $sanitizeHex($theme['sidebar_bg_start'] ?? '', '#9a0073'),
            'sidebar_end' => $sanitizeHex($theme['sidebar_bg_end'] ?? '', '#7a005a'),
        ];
    }
}
