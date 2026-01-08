<?php
/**
 * Form Template Delete
 * Delete a form template
 */

session_start();

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

require_once '../includes/database.php';

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
