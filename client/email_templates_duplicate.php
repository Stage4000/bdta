<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';
require_once '../backend/includes/template_duplication.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: email_templates_list.php');
    exit;
}

if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), scalar_string($_POST['csrf_token']))) {
    $_SESSION['error'] = 'Invalid request.';
    header('Location: email_templates_list.php');
    exit;
}

$template_id = safe_int($_POST['id'] ?? 0);
if ($template_id < 1) {
    $_SESSION['error'] = 'Template not found.';
    header('Location: email_templates_list.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

try {
    duplicateEmailTemplate($conn, $template_id);
    $_SESSION['success'] = 'Template duplicated successfully!';
} catch (Throwable $e) {
    $_SESSION['error'] = 'Error duplicating template: ' . $e->getMessage();
}

header('Location: email_templates_list.php');
exit;
