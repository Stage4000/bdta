<?php
/**
 * Pet Delete - Delete a pet
 */

require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

// Check if user is logged in
requireLogin();

$pet_id_value = safe_int($_POST['id'] ?? 0);
$pet_id = $pet_id_value > 0 ? $pet_id_value : null;
$client_id_value = safe_int($_POST['client_id'] ?? 0);
$client_id = $client_id_value > 0 ? $client_id_value : null;
$redirect_url = $client_id ? 'clients_view.php?id=' . (int) $client_id : 'pets_list.php';

if (!isPostRequest()) {
    setFlashMessage('Invalid request.', 'danger');
    redirect($redirect_url);
}

requireValidCsrfToken($redirect_url);

if (!$pet_id) {
    setFlashMessage('Pet ID is required.', 'danger');
    redirect($redirect_url);
}

$db = new Database();
$conn = $db->getConnection();

// Get pet info
$stmt = $conn->prepare("SELECT * FROM pets WHERE id = ?");
$stmt->execute([$pet_id]);
$pet = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pet) {
    setFlashMessage('Pet not found.', 'danger');
    redirect($redirect_url);
}

// Delete the pet
try {
    $stmt = $conn->prepare("DELETE FROM pets WHERE id = ?");
    $stmt->execute([$pet_id]);
    
    setFlashMessage("Pet '" . scalar_string($pet['name']) . "' deleted successfully.", 'success');
} catch (PDOException $e) {
    setFlashMessage('Error deleting pet: ' . $e->getMessage(), 'danger');
}

redirect($redirect_url);
?>
