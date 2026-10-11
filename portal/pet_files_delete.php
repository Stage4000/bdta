<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/pet_files.php';
requirePortalLogin();

header('Content-Type: application/json');

$client_id = portalClientId();

$db   = new Database();
$conn = $db->getConnection();

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

$file_id = safe_int($_POST['file_id'] ?? 0);
if ($file_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid file ID']);
    exit;
}

// Get file and verify the pet belongs to this client
$stmt = $conn->prepare("
    SELECT pf.*, p.id as pet_id, p.client_id
    FROM pet_files pf
    JOIN pets p ON pf.pet_id = p.id
    WHERE pf.id = ? AND p.client_id = ?
");
$stmt->execute([$file_id, $client_id]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'File not found']);
    exit;
}

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

    logClientActivity($client_id, 'pet_file_delete', 'Deleted file ID ' . $file_id . ' from pet ID ' . $file['pet_id'], $conn);

    echo json_encode(['success' => true, 'message' => 'File deleted successfully']);
} catch (PDOException $e) {
    error_log('Portal pet file delete error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to delete file. Please try again.']);
}
