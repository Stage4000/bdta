<?php
/**
 * Pet Delete - Delete a pet
 */

require_once '../includes/config.php';
require_once '../includes/database.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$pet_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;

if (!$pet_id) {
    $_SESSION['flash_error'] = "Pet ID is required.";
    header('Location: pets_list.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Get pet info
$stmt = $conn->prepare("SELECT * FROM pets WHERE id = ?");
$stmt->execute([$pet_id]);
$pet = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pet) {
    $_SESSION['flash_error'] = "Pet not found.";
    header('Location: pets_list.php');
    exit;
}

// Delete the pet
try {
    $stmt = $conn->prepare("DELETE FROM pets WHERE id = ?");
    $stmt->execute([$pet_id]);
    
    $_SESSION['flash_message'] = "Pet '" . htmlspecialchars($pet['name']) . "' deleted successfully.";
} catch (PDOException $e) {
    $_SESSION['flash_error'] = "Error deleting pet: " . $e->getMessage();
}

// Redirect back
if ($client_id) {
    header("Location: clients_edit.php?id=$client_id");
} else {
    header("Location: pets_list.php");
}
exit;
?>
