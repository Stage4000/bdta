<?php
/**
 * Contract Template Delete
 * Delete a contract template
 */

require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

if (!isPostRequest()) {
    setFlashMessage('Invalid request.', 'danger');
    redirect('contract_templates_list.php');
}

requireValidCsrfToken('contract_templates_list.php');

$id = safe_int($_POST['id'] ?? 0);
if ($id > 0) {

    try {
        // Verify template exists
        $stmt = $conn->prepare("SELECT id FROM contract_templates WHERE id = ?");
        $stmt->execute([$id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            setFlashMessage("Contract template not found.", 'warning');
        } else {
            $stmt = $conn->prepare("DELETE FROM contract_templates WHERE id = ?");
            $stmt->execute([$id]);

            setFlashMessage("Contract template deleted successfully.", 'success');
        }
    } catch (PDOException $e) {
        setFlashMessage("Error deleting template: " . $e->getMessage(), 'danger');
    }
} else {
    setFlashMessage("No template ID provided.", 'warning');
}

redirect('contract_templates_list.php');
?>
