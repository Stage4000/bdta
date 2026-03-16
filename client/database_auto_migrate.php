<?php
/**
 * Automatic database migration is no longer available.
 * SQLite support has been removed; configure MySQL directly.
 */

require_once __DIR__ . '/../backend/includes/config.php';

requireLogin();

header('HTTP/1.1 410 Gone');
header('Content-Type: application/json');
echo json_encode([
    'error' => 'SQLite migration has been removed. Configure MySQL and import data manually.',
]);
