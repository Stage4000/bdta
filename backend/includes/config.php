<?php
/**
 * Brook's Dog Training Academy - Configuration
 */

require_once __DIR__ . '/database.php';

/**
 * Resolve the system timezone from admin settings with a safe fallback.
 * Cached per process/request; restart long-running workers after changing settings.
 * Requires database/settings availability; falls back to default when the configured value is empty or invalid.
 *
 * @return string Resolved timezone identifier suitable for date_default_timezone_set
 */
function getSystemTimezone(): string {
    require_once __DIR__ . '/settings.php';

    /** @var string|null $resolved */
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }
    $fallback = 'America/New_York';
    $configured = null;
    try {
        $configured = Settings::get('timezone', $fallback);
    } catch (Exception $e) {
        error_log('config.php: unable to read timezone setting, using fallback "' . $fallback . '": ' . $e->getMessage());
        $resolved = $fallback;
        return $resolved;
    }

    try {
        $tz_input = ($configured === null || $configured === '') ? $fallback : scalar_string($configured);
        $tz       = new DateTimeZone($tz_input);
        $resolved = $tz->getName();
    } catch (Exception $e) {
        $log_value = ($configured === null || $configured === '') ? 'empty' : scalar_string($configured);
        error_log('config.php: falling back to default timezone "' . $fallback . '" because configured value "' . $log_value . '" was invalid: ' . $e->getMessage());
        $resolved = $fallback;
    }
    return $resolved;
}

date_default_timezone_set(getSystemTimezone());

/** @var array<string, string> $_GET */
/** @var array<string, string> $_POST */
/** @var array<string, string> $_REQUEST */
/** @var array<string, string> $_COOKIE */
/** @var array<string, string> $_SERVER */
/** @var array<string, string> $_ENV */
/** @var array<string, mixed> $_SESSION */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate a per-session CSRF token (used by delete forms and other state-changing actions)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Base URL configuration
define('BASE_URL', '/');
define('ADMIN_URL', '/client/');
define('DEFAULT_LOCALHOST_URL', 'http://localhost:8000');
define('PORTAL_URL', '/portal/');

// Helper functions
function redirect(string $url): never {
    header("Location: $url");
    exit();
}

function isLoggedIn(): bool {
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        redirect(ADMIN_URL . 'login.php');
    }
}

function setFlashMessage(string $message, string $type = 'info'): void {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

/**
 * @return array{message: string, type: string}|null
 */
function getFlashMessage(): ?array {
    if (isset($_SESSION['flash_message'])) {
        $message = scalar_string($_SESSION['flash_message']);
        $type = scalar_string($_SESSION['flash_type'] ?? 'info');
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

function escape(mixed $string): string {
    return htmlspecialchars(scalar_string($string), ENT_QUOTES, 'UTF-8');
}

/**
 * @param array<string|int, mixed> $row
 */
function array_string_value(array $row, string|int $key, string $default = ''): string {
    return scalar_string($row[$key] ?? $default);
}

/**
 * @param array<string|int, mixed> $row
 */
function array_int_value(array $row, string|int $key, int $default = 0): int {
    return safe_int($row[$key] ?? $default);
}

/**
 * @return array<string, mixed>
 */
function assoc_row(mixed $row): array {
    if (!is_array($row)) {
        return [];
    }

    $assoc = [];
    foreach ($row as $key => $value) {
        $assoc[(string) $key] = $value;
    }

    return $assoc;
}

/**
 * @return list<array<string, mixed>>
 */
function assoc_rows(mixed $rows): array {
    if (!is_array($rows)) {
        return [];
    }

    $assoc_rows = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $assoc_rows[] = assoc_row($row);
        }
    }

    return $assoc_rows;
}

/**
 * @return list<string>
 */
function string_list(mixed $value): array {
    if (!is_array($value)) {
        return [];
    }

    $items = [];
    foreach ($value as $item) {
        $items[] = scalar_string($item);
    }

    return $items;
}

/**
 * @return array<string, mixed>
 */
function decode_json_assoc(mixed $json): array {
    if (!is_string($json) || $json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return assoc_row($decoded);
}

/**
 * @return list<array<string, mixed>>
 */
function decode_json_assoc_list(mixed $json): array {
    $decoded = decode_json_assoc($json);
    $rows = [];
    foreach ($decoded as $item) {
        if (is_array($item)) {
            $rows[] = assoc_row($item);
        }
    }
    return $rows;
}

function bdta_get_display_timezone(): DateTimeZone {
    static $timezone = null;
    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    $timezone = new DateTimeZone(getSystemTimezone());
    return $timezone;
}

function bdta_get_utc_timezone(): DateTimeZone {
    static $timezone = null;
    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    $timezone = new DateTimeZone('UTC');
    return $timezone;
}

function currentUtcDateTime(string $format = 'Y-m-d H:i:s'): string {
    return gmdate($format);
}

function currentLocalDate(string $format = 'Y-m-d'): string {
    return (new DateTimeImmutable('now', bdta_get_display_timezone()))->format($format);
}

function formatUtcTimestamp(int $timestamp, string $format = 'Y-m-d H:i:s'): string {
    return gmdate($format, $timestamp);
}

function bdta_parse_datetime_string(string $value, DateTimeZone $default_timezone): ?DateTimeImmutable {
    try {
        return new DateTimeImmutable($value, $default_timezone);
    } catch (Throwable) {
        return null;
    }
}

function formatDate(mixed $date, string $format = 'F j, Y'): string {
    $value = trim(scalar_string($date));
    if ($value === '') {
        return '';
    }

    $display_timezone = bdta_get_display_timezone();

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
        $datetime = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $display_timezone);
        return $datetime instanceof DateTimeImmutable ? $datetime->format($format) : '';
    }

    $datetime = bdta_parse_datetime_string($value, bdta_get_utc_timezone());
    if (!$datetime instanceof DateTimeImmutable) {
        return '';
    }

    return $datetime->setTimezone($display_timezone)->format($format);
}

function formatDateTime(mixed $date_time, string $format = 'M j, Y g:i A'): string {
    $value = trim(scalar_string($date_time));
    if ($value === '') {
        return '';
    }

    $datetime = bdta_parse_datetime_string($value, bdta_get_utc_timezone());
    if (!$datetime instanceof DateTimeImmutable) {
        return '';
    }

    return $datetime->setTimezone(bdta_get_display_timezone())->format($format);
}

function localDateTimeToUtcString(mixed $date_time, string $format = 'Y-m-d H:i:s'): string {
    $value = trim(scalar_string($date_time));
    if ($value === '') {
        return '';
    }

    $datetime = bdta_parse_datetime_string($value, bdta_get_display_timezone());
    if (!$datetime instanceof DateTimeImmutable) {
        return '';
    }

    return $datetime->setTimezone(bdta_get_utc_timezone())->format($format);
}

/**
 * Get the base URL dynamically from the current request
 * Falls back to base_url setting, then localhost
 * 
 * Note: IPv6 addresses in bracket notation (e.g., [::1]:8000) are not supported
 * and will fall back to SERVER_NAME. Use base_url setting for IPv6 hosts.
 */
function getDynamicBaseUrl(): string {
    // Try to build URL from current request
    if (isset($_SERVER['HTTP_HOST'])) {
        // Detect protocol with support for reverse proxies/load balancers
        $protocol = 'http://';
        
        // Check X-Forwarded-Proto header (set by reverse proxies like nginx, apache)
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $protocol = 'https://';
        }
        // Check X-Forwarded-SSL header (alternative header)
        elseif (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
            $protocol = 'https://';
        }
        // Check direct HTTPS connection
        elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $protocol = 'https://';
        }
        // Check if port 443 is being used
        elseif (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
            $protocol = 'https://';
        }
        
        // Get and sanitize the host
        // Use SERVER_NAME as fallback for better security
        $host = scalar_string($_SERVER['HTTP_HOST']);
        
        // Strict validation: proper hostname format with optional port
        // Pattern ensures no consecutive dots, no leading/trailing hyphens in domain parts
        // Note: Does not support IPv6 bracket notation
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*(:[0-9]+)?$/', $host)) {
            // If HTTP_HOST is suspicious, fall back to SERVER_NAME
            $host = scalar_string($_SERVER['SERVER_NAME'] ?? 'localhost');
            if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] != 80 && $_SERVER['SERVER_PORT'] != 443) {
                $host .= ':' . scalar_string($_SERVER['SERVER_PORT']);
            }
        }
        
        return $protocol . $host;
    }
    
    // Fallback to base_url setting (for CLI/cron contexts)
    $base_url = Settings::get('base_url', null);
    if (is_string($base_url) && $base_url !== '') {
        return rtrim($base_url, '/');
    }
    
    // Last resort fallback
    return DEFAULT_LOCALHOST_URL;
}

// Portal helper functions
function isPortalLoggedIn(): bool {
    return isset($_SESSION['portal_client_id']) && !empty($_SESSION['portal_client_id']);
}

function requirePortalLogin(): void {
    if (!isPortalLoggedIn()) {
        redirect(PORTAL_URL . 'login.php');
    }
}

function portalClientId(): int {
    return safe_int($_SESSION['portal_client_id'] ?? 0);
}

function logClientActivity(int|string $client_id, string $action, string $description = '', ?PDO $conn = null): void {
    if ($conn === null) {
        $db = new Database();
        $conn = $db->getConnection();
    }
    // Use X-Forwarded-For when behind a trusted proxy, validated to prevent spoofing
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = trim(explode(',', scalar_string($_SERVER['HTTP_X_FORWARDED_FOR']))[0]);
        $ip = filter_var($forwarded, FILTER_VALIDATE_IP) ? $forwarded : scalar_string($_SERVER['REMOTE_ADDR'] ?? '');
    } else {
        $ip = scalar_string($_SERVER['REMOTE_ADDR'] ?? '');
    }
    $stmt = $conn->prepare("INSERT INTO client_activity_log (client_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->execute([$client_id, $action, $description, $ip]);
}
?>
