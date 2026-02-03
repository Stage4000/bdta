<?php
/**
 * Brook's Dog Training Academy - Configuration
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Base URL configuration
define('BASE_URL', '/');
define('ADMIN_URL', '/client/');
define('DEFAULT_LOCALHOST_URL', 'http://localhost:8000');

// Database configuration
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/settings.php';

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
    if ($base_url && $base_url !== DEFAULT_LOCALHOST_URL) {
        return rtrim($base_url, '/');
    }
    
    // Last resort fallback
    return DEFAULT_LOCALHOST_URL;
}
?>
