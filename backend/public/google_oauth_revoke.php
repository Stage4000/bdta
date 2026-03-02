<?php
/**
 * Google Calendar OAuth 2.0 – Revoke / Disconnect
 *
 * Revokes the stored OAuth token and removes it from the database.
 *
 * Access: Admin panel only (requires login).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/google_calendar.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(ADMIN_URL . 'settings.php?category=calendar');
}

// CSRF protection
$token         = $_POST['csrf_token'] ?? '';
$session_token = $_SESSION['csrf_token'] ?? '';
if (empty($token) || !hash_equals($session_token, $token)) {
    setFlashMessage('Invalid request. Please try again.', 'danger');
    redirect(ADMIN_URL . 'settings.php?category=calendar');
}

$admin_user_id = (int)$_SESSION['admin_id'];
GoogleCalendarIntegration::revokeOAuthToken($admin_user_id);

setFlashMessage('Google Calendar disconnected successfully.', 'success');
redirect(ADMIN_URL . 'settings.php?category=calendar');
