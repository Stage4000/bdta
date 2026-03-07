<?php
require_once '../backend/includes/config.php';
requirePortalLogin();

header('Content-Type: application/json');

$client_id = intval($_SESSION['portal_client_id']);

$db   = new Database();
$conn = $db->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$pet_id = isset($_POST['pet_id']) ? intval($_POST['pet_id']) : 0;
if ($pet_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid pet ID']);
    exit;
}

// Verify ownership — client may only upload to their own pets
$stmt = $conn->prepare("SELECT id, name FROM pets WHERE id = ? AND client_id = ?");
$stmt->execute([$pet_id, $client_id]);
$pet = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pet) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Pet not found']);
    exit;
}

$description = trim($_POST['description'] ?? '');

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $error_message = 'No file uploaded';
    if (isset($_FILES['file']['error'])) {
        switch ($_FILES['file']['error']) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $error_message = 'File is too large. Maximum size is 10MB'; break;
            case UPLOAD_ERR_PARTIAL:
                $error_message = 'File was only partially uploaded'; break;
            case UPLOAD_ERR_NO_FILE:
                $error_message = 'No file was uploaded'; break;
            default:
                $error_message = 'File upload failed'; break;
        }
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $error_message]);
    exit;
}

$max_file_size = 10 * 1024 * 1024;
if ($_FILES['file']['size'] > $max_file_size) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File is too large. Maximum size is 10MB']);
    exit;
}

$original_name = basename($_FILES['file']['name']);
$original_name = preg_replace('/[^a-zA-Z0-9._-]/', '_', $original_name);
$original_name = str_replace(['/', '\\', '..'], '', $original_name);

$file_size     = $_FILES['file']['size'];
$tmp_name      = $_FILES['file']['tmp_name'];
$mime_type     = mime_content_type($tmp_name);
$file_extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
if (!in_array($file_extension, $allowed_extensions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, and PDF files are allowed']);
    exit;
}

$allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
if (!in_array($mime_type, $allowed_mime_types)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid file type detected. File does not match its extension']);
    exit;
}

$file_type = in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif']) ? 'photo' : 'document';

$upload_base_dir = __DIR__ . '/../backend/uploads/pets';
$upload_pet_dir  = $upload_base_dir . '/' . $pet_id;

if (!is_dir($upload_base_dir) && !mkdir($upload_base_dir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to create upload directory']);
    exit;
}

if (!is_dir($upload_pet_dir) && !mkdir($upload_pet_dir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to create pet upload directory']);
    exit;
}

$unique_filename = uniqid('pet_' . $pet_id . '_') . '.' . $file_extension;
$file_path       = $upload_pet_dir . '/' . $unique_filename;

if (!move_uploaded_file($tmp_name, $file_path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
    exit;
}

try {
    $stmt = $conn->prepare("
        INSERT INTO pet_files (pet_id, file_type, file_name, original_name, file_size, mime_type, description, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, NULL)
    ");
    $stmt->execute([$pet_id, $file_type, $unique_filename, $original_name, $file_size, $mime_type, $description]);
    $file_id = $conn->lastInsertId();

    logClientActivity($client_id, 'pet_file_upload', 'Uploaded file "' . $original_name . '" for pet ID ' . $pet_id, $conn);

    echo json_encode([
        'success' => true,
        'message' => 'File uploaded successfully',
        'file' => [
            'id'            => intval($file_id),
            'type'          => $file_type,
            'name'          => $unique_filename,
            'original_name' => $original_name,
            'size'          => $file_size,
            'description'   => $description
        ]
    ]);
} catch (PDOException $e) {
    if (file_exists($file_path)) {
        unlink($file_path);
    }
    error_log('Portal pet file upload error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save file information. Please try again.']);
}
