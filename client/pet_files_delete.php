<?php
/**
 * Pet Files Delete API - Handle file deletion for pet profiles
 */

require_once '../backend/includes/config.php';
require_once '../backend/includes/pet_files.php';
requireLogin();

header('Content-Type: application/json');

$db = new Database();
$conn = $db->getConnection();

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!is_string($_POST['csrf_token'] ?? null) || !isValidCsrfToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request. Please refresh and try again.']);
    exit;
}

// Validate file_id
$file_id = safe_int($_POST['file_id'] ?? 0);
if ($file_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid file ID']);
    exit;
}

// Get file information from database
$stmt = $conn->prepare("
    SELECT pf.*, p.id as pet_id 
    FROM pet_files pf
    JOIN pets p ON pf.pet_id = p.id
    WHERE pf.id = ?
");
$stmt->execute([$file_id]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'File not found']);
    exit;
}

// Delete file from filesystem
try {
    $file_path = bdta_pet_file_path(safe_int($file['pet_id']), scalar_string($file['file_name']));
} catch (InvalidArgumentException | RuntimeException $e) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'File not found']);
    exit;
}
// nosemgrep: php.lang.security.unlink-use.unlink-use -- realpath validated inside authorized pet directory
$file_deleted = $file_path === null || unlink($file_path);
if (!$file_deleted) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to delete file. Please try again.']);
    exit;
}

try {
    $stmt = $conn->prepare("DELETE FROM pet_files WHERE id = ?");
    $stmt->execute([$file_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'File deleted successfully',
        'file_deleted_from_disk' => $file_deleted
    ]);
    
} catch (PDOException $e) {
    // Log the error server-side (in production, use proper logging)
    error_log('Pet file delete database error: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to delete file. Please try again.']);
}
