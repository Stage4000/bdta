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

// Database configuration
require_once __DIR__ . '/database.php';

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
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                    (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        return $protocol . $host;
    }
    
    // Fallback to base_url setting
    require_once __DIR__ . '/settings.php';
    $base_url = Settings::get('base_url', null);
    if ($base_url && $base_url !== 'http://localhost:8000') {
        return rtrim($base_url, '/');
    }
    
    // Last resort fallback
    return 'http://localhost:8000';
}
?>
