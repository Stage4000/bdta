<?php
/**
 * Legacy SQLite auto-migration endpoint.
 * Runtime SQLite support has been removed, so this endpoint now returns guidance.
 */

require_once __DIR__ . '/../backend/includes/config.php';

requireLogin();

header('Content-Type: application/json');
http_response_code(410);

echo json_encode([
    'success' => false,
    'error' => 'Automatic SQLite-to-MySQL migration is no longer available in-app. Import legacy SQLite data into MySQL before running the application.',
]);
