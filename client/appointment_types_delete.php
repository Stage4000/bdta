<?php
/**
 * Brook's Dog Training Academy - Delete Appointment Type
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';

requireLogin();

if (!isPostRequest()) {
    setFlashMessage('Invalid request.', 'danger');
    redirect('appointment_types_list.php');
}

requireValidCsrfToken('appointment_types_list.php');

$id = safe_int($_POST['id'] ?? 0);

if ($id > 0) {
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $stmt = $conn->prepare("DELETE FROM appointment_types WHERE id = ?");
        $stmt->execute([$id]);
        
        $_SESSION['flash'] = [
            'type' => 'success',
            'message' => 'Appointment type deleted successfully!'
        ];
    } catch (PDOException $e) {
        $_SESSION['flash'] = [
            'type' => 'danger',
            'message' => 'Error deleting appointment type: ' . $e->getMessage()
        ];
    }
}

redirect('appointment_types_list.php');
?>
