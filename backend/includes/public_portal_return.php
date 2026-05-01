<?php

function bdta_public_portal_return_string(mixed $value): string
{
    if (is_string($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value) || is_bool($value)) {
        return (string) $value;
    }

    return '';
}

function bdta_public_portal_base_path(): string
{
    return defined('PORTAL_URL') ? PORTAL_URL : '/portal/';
}

function bdta_public_portal_return_path_is_safe(string $path): bool
{
    $decoded_path = rawurldecode($path);
    if ($decoded_path === '' || str_contains($decoded_path, '\\')) {
        return false;
    }

    foreach (explode('/', $decoded_path) as $segment) {
        if ($segment === '.' || $segment === '..') {
            return false;
        }
    }

    return true;
}

function bdta_public_portal_return_sanitize_path(string $path, string $default = ''): string
{
    $path = trim($path);
    if ($path === '') {
        return $default;
    }

    if ($path[0] === '\\') {
        return $default;
    }

    $parts = parse_url($path);
    if ($parts === false) {
        return $default;
    }

    if (isset($parts['scheme']) || isset($parts['host'])) {
        return $default;
    }

    $normalized_path = isset($parts['path']) ? bdta_public_portal_return_string($parts['path']) : '';
    if ($normalized_path === '') {
        return $default;
    }

    if ($normalized_path[0] !== '/') {
        $normalized_path = '/' . $normalized_path;
    }

    if (strncmp($normalized_path, '//', 2) === 0) {
        return $default;
    }

    if (!bdta_public_portal_return_path_is_safe($normalized_path)) {
        return $default;
    }

    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . bdta_public_portal_return_string($parts['query']) : '';
    $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . bdta_public_portal_return_string($parts['fragment']) : '';

    return $normalized_path . $query . $fragment;
}

function bdta_public_portal_return_path(string $default = ''): string
{
    $portal_return = bdta_public_portal_return_sanitize_path(bdta_public_portal_return_string($_GET['portal_return'] ?? ''), '');
    if ($portal_return === '' || !str_starts_with($portal_return, bdta_public_portal_base_path())) {
        return $default;
    }

    return $portal_return;
}

function bdta_public_current_path(string $default = ''): string
{
    return bdta_public_portal_return_sanitize_path(
        bdta_public_portal_return_string($_SERVER['REQUEST_URI'] ?? ''),
        $default
    );
}

function bdta_public_login_return_path(string $default = ''): string
{
    return bdta_public_portal_return_sanitize_path(
        bdta_public_portal_return_string($_POST['return_to'] ?? $_GET['return_to'] ?? ''),
        $default
    );
}

function bdta_public_portal_login_url(string $return_to): string
{
    $sanitized_return_to = bdta_public_portal_return_sanitize_path($return_to, '');
    $portal_login_path = rtrim(bdta_public_portal_base_path(), '/') . '/login.php';
    if ($sanitized_return_to === '') {
        return $portal_login_path;
    }

    return $portal_login_path . '?return_to=' . rawurlencode($sanitized_return_to);
}

function bdta_append_public_portal_return(string $url, string $portal_return): string
{
    $public_url = bdta_public_portal_return_sanitize_path($url, '');
    $portal_path = bdta_public_portal_return_sanitize_path($portal_return, '');

    if ($public_url === '') {
        return '';
    }

    if ($portal_path === '' || !str_starts_with($portal_path, bdta_public_portal_base_path())) {
        return $public_url;
    }

    $parts = parse_url($public_url);
    if ($parts === false) {
        return $public_url;
    }

    $path = isset($parts['path']) ? bdta_public_portal_return_string($parts['path']) : '';
    if ($path === '') {
        return $public_url;
    }

    $query_params = [];
    if (isset($parts['query']) && $parts['query'] !== '') {
        parse_str(bdta_public_portal_return_string($parts['query']), $query_params);
    }
    $query_params['portal_return'] = $portal_path;

    $query = http_build_query($query_params);
    $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . bdta_public_portal_return_string($parts['fragment']) : '';

    return $path . ($query !== '' ? '?' . $query : '') . $fragment;
}
