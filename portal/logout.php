<?php
require_once '../backend/includes/config.php';

// If an admin is impersonating a client, stop impersonation instead of logging out
if (!empty($_SESSION['portal_impersonating_admin_id'])) {
    redirect(PORTAL_URL . 'stop_impersonation.php');
}

if (isPortalLoggedIn()) {
    $client_id = portalClientId();
    $db   = new Database();
    $conn = $db->getConnection();
    logClientActivity($client_id, 'logout', 'Client logged out', $conn);
}

unset(
    $_SESSION['portal_client_id'],
    $_SESSION['portal_client_name'],
    $_SESSION['portal_client_email']
);

redirect(PORTAL_URL . 'login.php');
