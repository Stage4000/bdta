<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/email_attachments.php';
requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method not allowed');
}
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(404);
    exit('Attachment not found');
}
$conn = (new Database())->getConnection();
// A row is downloadable only while its authenticated email history parent exists.
$stmt = $conn->prepare("SELECT ea.* FROM email_attachments ea
    WHERE ea.id = ? AND ((ea.email_kind = 'client' AND EXISTS (SELECT 1 FROM client_emails ce WHERE ce.id = ea.email_id))
    OR (ea.email_kind = 'unmatched' AND EXISTS (SELECT 1 FROM unmatched_emails ue WHERE ue.id = ea.email_id)))");
$stmt->execute([$id]);
$attachment = $stmt->fetch(PDO::FETCH_ASSOC);
try {
    if (!is_array($attachment)) {
        throw new RuntimeException('Missing attachment.');
    }
    $path = bdta_email_verified_attachment_path($attachment);
} catch (InvalidArgumentException | RuntimeException $e) {
    http_response_code(404);
    exit('Attachment unavailable. Check the original mailbox for received files.');
}
$name = bdta_email_attachment_name((string) $attachment['original_name']);
$fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'attachment.bin';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . (string) $attachment['file_size']);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, no-store, max-age=0');
header('Referrer-Policy: no-referrer');
session_write_close();
// Only a verified, random-key file beneath private storage reaches this sink.
readfile($path);
