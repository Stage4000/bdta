<?php
require_once __DIR__ . '/../../backend/includes/config.php';

define('PORTAL_URL', '/portal/');

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
