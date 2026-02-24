<?php
require_once '../portal/includes/config.php';
requirePortalLogin();

header('Content-Type: application/json');

$client_id = intval($_SESSION['portal_client_id']);

$db   = new Database();
$conn = $db->getConnection();

$pet_id = isset($_GET['pet_id']) ? intval($_GET['pet_id']) : 0;
if ($pet_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid pet ID']);
    exit;
}

// Verify ownership
$stmt = $conn->prepare("SELECT id, name FROM pets WHERE id = ? AND client_id = ?");
$stmt->execute([$pet_id, $client_id]);
$pet = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pet) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Pet not found']);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, file_type, file_name, original_name, file_size, mime_type, description, uploaded_at
    FROM pet_files
    WHERE pet_id = ?
    ORDER BY uploaded_at DESC
");
$stmt->execute([$pet_id]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'pet'     => $pet,
    'files'   => $files,
    'count'   => count($files)
]);
