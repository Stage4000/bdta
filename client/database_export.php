<?php
/**
 * Database Export Tool (MySQL only)
 * SQLite exports are no longer supported.
 */

require_once __DIR__ . '/../backend/includes/config.php';

requireLogin();

header('HTTP/1.1 410 Gone');
header('Content-Type: application/json');
echo json_encode([
    'error' => 'SQLite exports are no longer supported. Use MySQL backups instead.',
]);
