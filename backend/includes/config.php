<?php
/**
 * Brook's Dog Training Academy - Configuration
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/settings.php';

// Set timezone from admin settings with a safe fallback (cached per process; restart long-running workers after changing)
function getSystemTimezone(): string {
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
        $is_empty = ($configured === null || $configured === '');
        $tz_input = $is_empty ? $fallback : $configured;
        $tz       = new DateTimeZone($tz_input);
        $resolved = $tz->getName();
    } catch (Exception $e) {
        $log_value = $is_empty ? 'empty' : $configured;
        error_log('config.php: falling back to default timezone "' . $fallback . '" because configured value "' . $log_value . '" was invalid: ' . $e->getMessage());
        $resolved = $fallback;
    }
    return $resolved;
}

date_default_timezone_set(getSystemTimezone());

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
function redirect($url) {
    header("Location: $url");
    exit();
}

function isLoggedIn() {
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect(ADMIN_URL . 'login.php');
    }
}

function setFlashMessage($message, $type = 'info') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

function getFlashMessage() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'info';
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
        return ['message' => $message, 'type' => $type];
    }
    return null;
}

function escape($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

function formatDate($date) {
    return date('F j, Y', strtotime($date));
}

/**
 * Get the base URL dynamically from the current request
 * Falls back to base_url setting, then localhost
 * 
 * Note: IPv6 addresses in bracket notation (e.g., [::1]:8000) are not supported
 * and will fall back to SERVER_NAME. Use base_url setting for IPv6 hosts.
 */
function getDynamicBaseUrl() {
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
        $host = $_SERVER['HTTP_HOST'];
        
        // Strict validation: proper hostname format with optional port
        // Pattern ensures no consecutive dots, no leading/trailing hyphens in domain parts
        // Note: Does not support IPv6 bracket notation
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)*(:[0-9]+)?$/', $host)) {
            // If HTTP_HOST is suspicious, fall back to SERVER_NAME
            $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
            if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] != 80 && $_SERVER['SERVER_PORT'] != 443) {
                $host .= ':' . $_SERVER['SERVER_PORT'];
            }
        }
        
        return $protocol . $host;
    }
    
    // Fallback to base_url setting (for CLI/cron contexts)
    $base_url = Settings::get('base_url', null);
    if ($base_url) {
        return rtrim($base_url, '/');
    }
    
    // Last resort fallback
    return DEFAULT_LOCALHOST_URL;
}

// Portal helper functions
function isPortalLoggedIn() {
    return isset($_SESSION['portal_client_id']) && !empty($_SESSION['portal_client_id']);
}

function requirePortalLogin() {
    if (!isPortalLoggedIn()) {
        redirect(PORTAL_URL . 'login.php');
    }
}

function logClientActivity($client_id, $action, $description = '', $conn = null) {
    if ($conn === null) {
        $db = new Database();
        $conn = $db->getConnection();
    }
    // Use X-Forwarded-For when behind a trusted proxy, validated to prevent spoofing
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        $ip = filter_var($forwarded, FILTER_VALIDATE_IP) ? $forwarded : ($_SERVER['REMOTE_ADDR'] ?? '');
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    }
    $stmt = $conn->prepare("INSERT INTO client_activity_log (client_id, action, description, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->execute([$client_id, $action, $description, $ip]);
}
?>
