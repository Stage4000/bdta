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
    if (!bdta_is_blog_cover_photo_upload_path($cover_photo)) {
        return '';
    }

    return dirname(__DIR__, 2) . trim($cover_photo);
}

function bdta_get_blog_cover_photo_meta_url(string $cover_photo, ?string $base_url = null): string
{
    $path = trim($cover_photo);
    if ($path === '') {
        return '';
    }

    if (filter_var($path, FILTER_VALIDATE_URL)) {
        return $path;
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
