<?php

function bdta_get_default_localhost_base_url(): string
{
    return defined('DEFAULT_LOCALHOST_URL') ? (string) DEFAULT_LOCALHOST_URL : 'http://localhost:8000';
}

function bdta_normalize_base_url(string $base_url): string
{
    $normalized = trim($base_url);
    if ($normalized === '') {
        return '';
    }

    if (!str_contains($normalized, '://')) {
        $normalized = 'https://' . ltrim($normalized, '/');
    }

    $parts = parse_url($normalized);
    if (!is_array($parts)) {
        return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));

    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return '';
    }

    $resolved = $scheme . '://' . $host;

    if (isset($parts['port']) && is_int($parts['port'])) {
        $resolved .= ':' . $parts['port'];
    }

    return rtrim($resolved, '/');
}

function bdta_is_default_localhost_base_url(string $base_url): bool
{
    return bdta_normalize_base_url($base_url) === bdta_normalize_base_url(bdta_get_default_localhost_base_url());
}
