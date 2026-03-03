<?php
/**
 * Google Calendar OAuth – Select Calendar
 *
 * Updates the calendar_id stored for the current admin user's OAuth token.
 * Called from the calendar selector in Settings → Calendar.
 *
 * Access: Admin panel only (requires login).
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/google_calendar.php';

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

$calendar_id   = trim($_POST['calendar_id'] ?? 'primary');
$admin_user_id = (int)$_SESSION['admin_id'];

if (empty($calendar_id)) {
    $calendar_id = 'primary';
}

$db   = new Database();
$conn = $db->getConnection();
$conn->prepare("
    UPDATE google_oauth_tokens
    SET calendar_id = ?, updated_at = CURRENT_TIMESTAMP
    WHERE admin_user_id = ?
")->execute([$calendar_id, $admin_user_id]);

setFlashMessage('Calendar selection saved successfully.', 'success');
redirect(ADMIN_URL . 'settings.php?category=calendar');
