<?php
/**
 * Get Contract Template (AJAX endpoint)
 */
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

header('Content-Type: application/json');

$db = new Database();
$conn = $db->getConnection();

$template_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

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
