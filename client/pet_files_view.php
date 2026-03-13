<?php
/**
 * Pet Files Download/View - Secure file serving for pet files
 */

require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Validate file_id
$file_id = safe_int($_GET['id'] ?? 0);
if ($file_id <= 0) {
    http_response_code(400);
    die('Invalid file ID');
}

// Get file information from database
$stmt = $conn->prepare("
    SELECT pf.*, p.id as pet_id, p.name as pet_name
    FROM pet_files pf
    JOIN pets p ON pf.pet_id = p.id
    WHERE pf.id = ?
");
$stmt->execute([$file_id]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    http_response_code(404);
    die('File not found');
}

// Build file path
$file_path = __DIR__ . '/../backend/uploads/pets/' . $file['pet_id'] . '/' . $file['file_name'];

// Check if file exists
if (!file_exists($file_path)) {
    http_response_code(404);
    die('File not found on server');
}

// Determine if we should force download or display inline
$download = isset($_GET['download']) && $_GET['download'] == '1';

// Sanitize filename for Content-Disposition header
// Remove any characters that could cause header injection
$safe_filename = scalar_string(preg_replace('/[^\w\s\.-]/', '_', $file['original_name']));
$safe_filename = str_replace(["\r", "\n"], '', $safe_filename);

// Set appropriate headers
header('Content-Type: ' . $file['mime_type']);
header('Content-Length: ' . filesize($file_path));

if ($download) {
    // Force download with sanitized filename
    header('Content-Disposition: attachment; filename="' . addslashes($safe_filename) . '"');
} else {
    // Display inline (for images and PDFs) with sanitized filename
    header('Content-Disposition: inline; filename="' . addslashes($safe_filename) . '"');
}

// Prevent caching of sensitive files
header('Cache-Control: private, max-age=0, no-cache, must-revalidate');
header('Pragma: no-cache');

// Output file
readfile($file_path);
exit;
