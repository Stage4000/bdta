<?php
require_once '../portal/includes/config.php';

if (isPortalLoggedIn()) {
    $client_id = intval($_SESSION['portal_client_id']);
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
