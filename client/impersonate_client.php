<?php
require_once '../backend/includes/config.php';
requireLogin();

require_once '../portal/includes/config.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    setFlashMessage('Invalid client ID.', 'danger');
    redirect('clients_list.php');
}

$db   = new Database();
$conn = $db->getConnection();

// Fetch the target client (non-admin clients only)
$stmt = $conn->prepare("SELECT id, name, email FROM clients WHERE id = ? AND (is_admin = 0 OR is_admin IS NULL)");
$stmt->execute([$id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    setFlashMessage('Client not found or cannot be impersonated.', 'danger');
    redirect('clients_list.php');
}

// Store the admin ID so we can return to admin mode later
$_SESSION['portal_impersonating_admin_id'] = $_SESSION['admin_id'];

// Set portal session variables as the target client
$_SESSION['portal_client_id']    = $client['id'];
$_SESSION['portal_client_name']  = $client['name'];
$_SESSION['portal_client_email'] = $client['email'];

// Audit log: record who impersonated whom
logClientActivity(
    $client['id'],
    'admin_impersonation_start',
    'Admin (ID: ' . intval($_SESSION['portal_impersonating_admin_id']) . ') started viewing portal as this client',
    $conn
);

redirect(PORTAL_URL . 'index.php');
