<?php
/**
 * Get Contract Template (AJAX endpoint)
 */
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

bdta_refresh_session_admin_account_type();
if (bdta_session_admin_is_accountant($_SESSION) && !bdta_is_accountant_allowed_admin_path(scalar_string($_SERVER['SCRIPT_NAME'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$template_id = safe_int($_GET['id'] ?? 0);

if ($template_id) {
    $stmt = $conn->prepare("SELECT * FROM contract_templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($template) {
        echo json_encode(['success' => true, 'template' => $template]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Template not found']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid template ID']);
}
