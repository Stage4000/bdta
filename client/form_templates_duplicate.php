<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';
require_once '../backend/includes/template_duplication.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: form_templates_list.php');
    exit;
}

if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), scalar_string($_POST['csrf_token']))) {
    $_SESSION['flash_message'] = 'Invalid request.';
    $_SESSION['flash_message_type'] = 'danger';
    header('Location: form_templates_list.php');
    exit;
}

$template_id = safe_int($_POST['id'] ?? 0);
if ($template_id < 1) {
    $_SESSION['flash_message'] = 'Template not found.';
    $_SESSION['flash_message_type'] = 'danger';
    header('Location: form_templates_list.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

try {
    duplicateFormTemplate($conn, $template_id);
    $_SESSION['flash_message'] = 'Template duplicated successfully!';
    $_SESSION['flash_message_type'] = 'success';
} catch (Throwable $e) {
    $_SESSION['flash_message'] = 'Error duplicating template: ' . $e->getMessage();
    $_SESSION['flash_message_type'] = 'danger';
}

header('Location: form_templates_list.php');
exit;
