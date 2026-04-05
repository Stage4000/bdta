<?php

function bdta_is_blog_cover_photo_upload_path(string $cover_photo): bool
{
    $path = trim($cover_photo);

    return $path !== ''
        && str_starts_with($path, '/backend/uploads/blog_covers/')
        && !str_contains($path, '..');
}

function bdta_get_blog_cover_photo_filesystem_path(string $cover_photo): string
{
    $path = trim($cover_photo);
    $prefix = '/backend/uploads/blog_covers/';
    if (!bdta_is_blog_cover_photo_upload_path($path)) {
        return '';
    }

    $uploads_root = realpath(dirname(__DIR__, 2) . '/backend/uploads/blog_covers');
    $relative_path = substr($path, strlen($prefix));
    if ($relative_path === '' || str_contains($relative_path, '/')) {
        return '';
    }

    $resolved_path = realpath(dirname(__DIR__, 2) . '/backend/uploads/blog_covers/' . $relative_path);
    if ($uploads_root === false || $resolved_path === false) {
        return '';
    }

    $normalized_uploads_root = rtrim($uploads_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($resolved_path, $normalized_uploads_root)) {
        return '';
    }

    return $resolved_path;
}

function bdta_get_blog_cover_photo_meta_url(string $cover_photo, ?string $base_url = null): string
{
    $path = trim($cover_photo);
    if ($path === '') {
        return '';
    }

    if (filter_var($path, FILTER_VALIDATE_URL)) {
        $scheme = strtolower((string) parse_url($path, PHP_URL_SCHEME));
        if (in_array($scheme, ['http', 'https'], true)) {
            return $path;
        }

        return '';
    }

    if (!bdta_is_blog_cover_photo_upload_path($path)) {
        return '';
    }

    if ($base_url === null) {
        if (!function_exists('getDynamicBaseUrl')) {
            return $path;
        }

        $base_url = getDynamicBaseUrl();
    }

    $normalized_base_url = function_exists('bdta_normalize_base_url')
        ? bdta_normalize_base_url($base_url)
        : rtrim(trim($base_url), '/');

    if ($normalized_base_url === '') {
        return $path;
    }

    return $normalized_base_url . $path;
}
