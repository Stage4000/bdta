<?php
/**
 * Brook's Dog Training Academy - Delete Appointment Type
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

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

header('Location: appointment_types_list.php');
exit;
?>
