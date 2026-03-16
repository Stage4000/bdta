<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';
require_once '../backend/includes/template_duplication.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contract_templates_list.php');
    exit;
}

if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), scalar_string($_POST['csrf_token']))) {
    setFlashMessage('Invalid request.', 'danger');
    header('Location: contract_templates_list.php');
    exit;
}

$template_id = safe_int($_POST['id'] ?? 0);
if ($template_id < 1) {
    setFlashMessage('Template not found.', 'danger');
    header('Location: contract_templates_list.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

try {
    duplicateContractTemplate($conn, $template_id);
    setFlashMessage('Template duplicated successfully!', 'success');
} catch (Throwable $e) {
    setFlashMessage('Error duplicating template: ' . $e->getMessage(), 'danger');
}

header('Location: contract_templates_list.php');
exit;
