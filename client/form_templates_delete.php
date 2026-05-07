<?php
/**
 * Form Template Delete
 * Delete a form template
 */

require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

if (!isPostRequest()) {
    setFlashMessage('Invalid request.', 'danger');
    redirect('form_templates_list.php');
}

requireValidCsrfToken('form_templates_list.php');

$id = safe_int($_POST['id'] ?? 0);
if ($id > 0) {
    
    try {
        // Check if template has submissions
        $stmt = $conn->prepare("SELECT COUNT(*) FROM form_submissions WHERE template_id = ?");
        $stmt->execute([$id]);
        $submission_count = safe_int($stmt->fetchColumn());
        
        if ($submission_count > 0) {
            $_SESSION['flash_message'] = "Cannot delete template - it has $submission_count submission(s). Mark it as inactive instead.";
            $_SESSION['flash_message_type'] = 'warning';
        } else {
            // Delete template
            $stmt = $conn->prepare("DELETE FROM form_templates WHERE id = ?");
            $stmt->execute([$id]);
            
            $_SESSION['flash_message'] = "Form template deleted successfully!";
            $_SESSION['flash_message_type'] = 'success';
        }
    } catch (PDOException $e) {
        $_SESSION['flash_message'] = "Error deleting template: " . $e->getMessage();
        $_SESSION['flash_message_type'] = 'danger';
    }
} else {
    setFlashMessage('No template ID provided.', 'warning');
}

redirect('form_templates_list.php');
?>
