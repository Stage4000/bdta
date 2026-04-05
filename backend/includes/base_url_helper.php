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

    if (isset($parts['port'])) {
        $resolved .= ':' . $parts['port'];
    }

    return rtrim($resolved, '/');
}

function bdta_is_default_localhost_base_url(string $base_url): bool
{
    return bdta_normalize_base_url($base_url) === bdta_normalize_base_url(bdta_get_default_localhost_base_url());
}

function bdta_is_localhost_base_url(string $base_url): bool
{
    $normalized = bdta_normalize_base_url($base_url);
    if ($normalized === '') {
        return false;
    }

    $host = parse_url($normalized, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return false;
    }

    return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true);
}

/**
 * @return list<string>
 */
function bdta_get_base_url_compare_candidates(string $base_url): array
{
    $trimmed = trim($base_url);
    if ($trimmed === '') {
        return [''];
    }

    $normalized = bdta_normalize_base_url($trimmed);
    if ($normalized === '') {
        return [$trimmed];
    }

    $candidates = [$normalized];
    $candidates[] = $normalized . '/';

    if (!in_array($trimmed, $candidates, true)) {
        $candidates[] = $trimmed;
    }

    return $candidates;
}
