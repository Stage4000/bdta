<?php
/**
 * Contract Template Delete
 * Delete a contract template
 */

require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

// Check if user is logged in
requireLogin();

$db = new Database();
$conn = $db->getConnection();

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];

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

header("Location: contract_templates_list.php");
exit;
?>
