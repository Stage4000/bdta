<?php
require_once '../backend/includes/config.php';
requirePortalLogin();

if (!isPostRequest()) {
    redirect('index.php');
}

requireValidCsrfToken('index.php');

$db = new Database();
$conn = $db->getConnection();

$notification_id = safe_int($_POST['notification_id'] ?? 0);
$action = scalar_string($_POST['action'] ?? '');
$return_to = bdta_notification_sanitize_path(scalar_string($_POST['return_to'] ?? ''), '/portal/index.php');

if ($action === 'read') {
    bdta_mark_notification_read($conn, 'portal', portalClientId(), $notification_id);
} elseif ($action === 'delete') {
    bdta_delete_notification($conn, 'portal', portalClientId(), $notification_id);
}

redirect($return_to);
