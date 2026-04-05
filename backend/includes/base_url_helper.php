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

    $normalized_host = trim(strtolower($host), '[]');
    if ($normalized_host === 'localhost') {
        return true;
    }

    if (filter_var($normalized_host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $ipv6 = inet_pton($normalized_host);
        $ipv6_loopback = inet_pton('::1');
        return $ipv6 !== false && $ipv6_loopback !== false && $ipv6 === $ipv6_loopback;
    }

    if (filter_var($normalized_host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $ipv4_octets = explode('.', $normalized_host);
        // Check whether the first octet places the address in the 127.0.0.0/8 loopback range.
        return ($ipv4_octets[0] ?? '') === '127';
    }

    return false;
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
