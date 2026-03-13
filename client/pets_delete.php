<?php
/**
 * Pet Delete - Delete a pet
 */

require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

// Check if user is logged in
requireLogin();

$pet_id_value = safe_int($_GET['id'] ?? 0);
$pet_id = $pet_id_value > 0 ? $pet_id_value : null;
$client_id_value = safe_int($_GET['client_id'] ?? 0);
$client_id = $client_id_value > 0 ? $client_id_value : null;

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
    header("Location: clients_view.php?id=$client_id");
} else {
    header("Location: pets_list.php");
}
exit;
?>
