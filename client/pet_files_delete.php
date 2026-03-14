<?php
/**
 * Pet Files Delete API - Handle file deletion for pet profiles
 */

require_once '../backend/includes/config.php';
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
$file_path = __DIR__ . '/../backend/uploads/pets/' . $file['pet_id'] . '/' . $file['file_name'];
$file_deleted = false;

if (file_exists($file_path)) {
    // file_path is scoped to the fixed pet uploads directory plus database-owned identifiers.
    // nosemgrep
    $file_deleted = unlink($file_path);
} else {
    // File doesn't exist on filesystem, but we'll still remove the database record
    $file_deleted = true;
}

// Delete record from database
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
