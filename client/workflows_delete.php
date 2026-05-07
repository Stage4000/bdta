<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

if (!isPostRequest()) {
    setFlashMessage('Invalid request.', 'danger');
    redirect('workflows_list.php');
}

requireValidCsrfToken('workflows_list.php');

$id = safe_int($_POST['id'] ?? 0);
if ($id > 0) {

    try {
        // Verify the workflow exists
        $stmt = $conn->prepare("SELECT id FROM workflows WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            $_SESSION['error'] = 'Workflow not found';
            header('Location: workflows_list.php');
            exit;
        }

        // Delete dependent records first, then the workflow
        $conn->beginTransaction();
        $conn->prepare("DELETE FROM workflow_enrollments WHERE workflow_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM workflow_steps WHERE workflow_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM workflows WHERE id = ?")->execute([$id]);
        $conn->commit();

        $_SESSION['success'] = 'Workflow deleted successfully';
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['error'] = 'Error deleting workflow. Please try again.';
    }
} else {
    setFlashMessage('Workflow ID is required.', 'danger');
}

redirect('workflows_list.php');
?>
