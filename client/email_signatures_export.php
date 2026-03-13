<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';
require_once '../backend/includes/email_signature_helper.php';

requireLogin();

$id = safe_int($_GET['id'] ?? 0);

if (!$id) {
    die('Signature ID is required');
}

$signature = EmailSignatureHelper::getSignature($id);

if (!$signature) {
    die('Signature not found or inactive');
}

// Get HTML export
$html = EmailSignatureHelper::exportAsHTML($id);

// Set headers for download
header('Content-Type: text/html; charset=utf-8');
$signature_name = array_string_value($signature, 'name');
header('Content-Disposition: attachment; filename="email_signature_' . preg_replace('/[^a-z0-9]+/i', '_', $signature_name) . '.html"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo $html;
