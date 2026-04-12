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
        $normalized_path = '/' . ltrim($normalized_path, '/');
    }

    if (strncmp($normalized_path, '//', 2) === 0) {
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

function bdta_append_public_portal_return(string $url, string $portal_return): string
{
    $public_url = bdta_public_portal_return_sanitize_path($url, '');
    $portal_path = bdta_public_portal_return_sanitize_path($portal_return, '');

    if ($public_url === '') {
        return $url;
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
