<?php
/**
 * Form Template Delete
 * Delete a form template
 */

require_once '../includes/config.php';
require_once '../includes/database.php';

// Check if user is logged in
requireLogin();

$db = new Database();
$conn = $db->getConnection();

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    try {
        // Check if template has submissions
        $stmt = $conn->prepare("SELECT COUNT(*) FROM form_submissions WHERE template_id = ?");
        $stmt->execute([$id]);
        $submission_count = $stmt->fetchColumn();
        
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
}

header("Location: form_templates_list.php");
exit;
?>
