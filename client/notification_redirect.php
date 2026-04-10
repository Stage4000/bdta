<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$notification_id = safe_int($_GET['id'] ?? 0);
$admin_id = safe_int($_SESSION['admin_id'] ?? 0);
$notification = bdta_get_notification_by_id($conn, 'admin', $admin_id, $notification_id);

if ($notification !== null && !empty($notification['persistent_id'])) {
    bdta_mark_notification_read($conn, 'admin', $admin_id, $notification_id);
    redirect(bdta_notification_sanitize_path((string) ($notification['url'] ?? ''), '/client/index.php'));
}

redirect('/client/index.php');
