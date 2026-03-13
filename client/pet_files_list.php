<?php
/**
 * Pet Files List API - Get all files for a specific pet
 */

require_once '../backend/includes/config.php';
requireLogin();

header('Content-Type: application/json');

$db = new Database();
$conn = $db->getConnection();

// Validate pet_id
$pet_id = safe_int($_GET['pet_id'] ?? 0);
if ($pet_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid pet ID']);
    exit;
}

// Verify pet exists
$stmt = $conn->prepare("SELECT id, name FROM pets WHERE id = ?");
$stmt->execute([$pet_id]);
$pet = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pet) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Pet not found']);
    exit;
}

// Get all files for this pet
$stmt = $conn->prepare("
    SELECT 
        pf.*,
        au.username as uploaded_by_name
    FROM pet_files pf
    LEFT JOIN admin_users au ON pf.uploaded_by = au.id
    WHERE pf.pet_id = ?
    ORDER BY pf.uploaded_at DESC
");
$stmt->execute([$pet_id]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'pet' => $pet,
    'files' => $files,
    'count' => count($files)
]);
