<?php
require_once '../backend/includes/config.php';

// Only admins who are impersonating can stop impersonation
if (empty($_SESSION['portal_impersonating_admin_id'])) {
    redirect(PORTAL_URL . 'login.php');
}

$admin_id  = safe_int($_SESSION['portal_impersonating_admin_id']);
$client_id = safe_int($_SESSION['portal_client_id'] ?? 0);

if ($client_id > 0) {
    $db   = new Database();
    $conn = $db->getConnection();
    logClientActivity(
        $client_id,
        'admin_impersonation_end',
        'Admin (ID: ' . $admin_id . ') stopped viewing portal as this client',
        $conn
    );
}

// Clear portal session variables
unset(
    $_SESSION['portal_client_id'],
    $_SESSION['portal_client_name'],
    $_SESSION['portal_client_email'],
    $_SESSION['portal_impersonating_admin_id']
);

// Return to the client view page in the admin area
$return_url = ADMIN_URL . ($client_id > 0 ? 'clients_view.php?id=' . $client_id : 'clients_list.php');
redirect($return_url);
