<?php
require_once '../backend/includes/config.php';
requirePortalLogin();

$client_id = portalClientId();

$db   = new Database();
$conn = $db->getConnection();

$file_id = safe_int($_GET['id'] ?? 0);
if ($file_id <= 0) {
    http_response_code(400);
    die('Invalid file ID');
}

// Get file info and verify the pet belongs to this client
$stmt = $conn->prepare("
    SELECT pf.*, p.id as pet_id, p.name as pet_name, p.client_id
    FROM pet_files pf
    JOIN pets p ON pf.pet_id = p.id
    WHERE pf.id = ? AND p.client_id = ?
");
$stmt->execute([$file_id, $client_id]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    http_response_code(404);
    die('File not found');
}

$file_path = __DIR__ . '/../backend/uploads/pets/' . $file['pet_id'] . '/' . $file['file_name'];

if (!file_exists($file_path)) {
    http_response_code(404);
    die('File not found on server');
}

$download     = isset($_GET['download']) && $_GET['download'] == '1';
$safe_filename = scalar_string(preg_replace('/[^\w\s\.-]/', '_', $file['original_name']));
$safe_filename = str_replace(["\r", "\n"], '', $safe_filename);

header('Content-Type: ' . $file['mime_type']);
header('Content-Length: ' . filesize($file_path));

if ($download) {
    header('Content-Disposition: attachment; filename="' . addslashes($safe_filename) . '"');
} else {
    header('Content-Disposition: inline; filename="' . addslashes($safe_filename) . '"');
}

header('Cache-Control: private, max-age=0, no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($file_path);
exit;
