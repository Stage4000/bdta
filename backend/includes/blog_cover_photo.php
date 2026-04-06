<?php

require_once __DIR__ . '/base_url_helper.php';

function bdta_is_valid_blog_cover_photo_path(string $path): bool
{
    $path = trim($path);
    if ($path === '') {
        return false;
    }

    if (filter_var($path, FILTER_VALIDATE_URL)) {
        $scheme = strtolower((string) parse_url($path, PHP_URL_SCHEME));
        return $scheme === 'https';
    }

    return str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_contains($path, '..');
}

function bdta_normalize_blog_cover_photo_path(string $path): string
{
    $path = trim($path);
    return bdta_is_valid_blog_cover_photo_path($path) ? $path : '';
}

function bdta_get_blog_cover_photo_absolute_url(string $path, ?string $base_url = null): string
{
    $normalized_path = bdta_normalize_blog_cover_photo_path($path);
    if ($normalized_path === '') {
        return '';
    }

    if (filter_var($normalized_path, FILTER_VALIDATE_URL)) {
        return $normalized_path;
    }

    $resolved_base_url = bdta_normalize_base_url(
        $base_url ?? (function_exists('getDynamicBaseUrl') ? getDynamicBaseUrl() : bdta_get_default_localhost_base_url())
    );

    if ($resolved_base_url === '') {
        return $normalized_path;
    }

    return $resolved_base_url . $normalized_path;
}

function bdta_get_blog_cover_photo_upload_directory(): string
{
    return dirname(__DIR__) . '/uploads/blog';
}

function bdta_get_blog_cover_photo_local_path(string $path): string
{
    $normalized_path = bdta_normalize_blog_cover_photo_path($path);
    if ($normalized_path === '' || filter_var($normalized_path, FILTER_VALIDATE_URL)) {
        return '';
    }

    if (dirname($normalized_path) !== '/backend/uploads/blog') {
        return '';
    }

    $filename = basename($normalized_path);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return '';
    }

    return bdta_get_blog_cover_photo_upload_directory() . '/' . $filename;
}

function bdta_delete_blog_cover_photo_file(string $path): void
{
    $local_path = bdta_get_blog_cover_photo_local_path($path);
    if ($local_path === '') {
        return;
    }

    $real_upload_dir = realpath(bdta_get_blog_cover_photo_upload_directory());
    $real_local_path = realpath($local_path);
    if ($real_upload_dir === false || $real_local_path === false) {
        return;
    }

    $upload_dir_prefix = rtrim($real_upload_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (str_starts_with($real_local_path, $upload_dir_prefix) && is_file($real_local_path)) {
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- path is realpath-validated within the fixed blog upload directory
        unlink($real_local_path);
    }
}
