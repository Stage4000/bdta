<?php
require_once '../backend/includes/config.php';
requirePortalLogin();

$db = new Database();
$conn = $db->getConnection();

$notification_id = safe_int($_GET['id'] ?? 0);
$client_id = portalClientId();
$notification = bdta_get_notification_by_id($conn, 'portal', $client_id, $notification_id);

if ($notification !== null && !empty($notification['persistent_id'])) {
    bdta_mark_notification_read($conn, 'portal', $client_id, $notification_id);
    redirect(bdta_notification_sanitize_path(bdta_notification_string($notification['url'] ?? ''), '/portal/index.php'));
}

redirect('/portal/index.php');
