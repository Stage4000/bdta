#!/usr/bin/env php
<?php
/**
 * Test script for MySQL database connection
 */

require_once __DIR__ . '/backend/includes/database.php';

echo "=== Database Connection Test ===\n\n";

try {
    // Test database connection
    $db = new Database();
    $conn = $db->getConnection();
    $db_type = $db->getDatabaseType();
    
    if ($db_type !== 'mysql') {
        throw new Exception("Unexpected database type: $db_type (MySQL is required)");
    }
    
    echo "✓ Database connection successful!\n";
    echo "  Database type: " . strtoupper($db_type) . "\n\n";
    
    // Test table creation
    $stmt = $conn->query("SHOW TABLES");
    
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "✓ Tables created successfully!\n";
    echo "  Total tables: " . count($tables) . "\n";
    echo "  Sample tables: " . implode(', ', array_slice($tables, 0, 5)) . "...\n\n";
    
    // Test admin user
    $stmt = $conn->query("SELECT COUNT(*) as count FROM admin_users");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($result)) {
        throw new Exception("Failed to read admin user count");
    }
    echo "✓ Admin users table accessible!\n";
    echo "  Admin users count: " . $result['count'] . "\n\n";
    
    echo "=== All Tests Passed! ===\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . "\n";
    echo "  Line: " . $e->getLine() . "\n";
    exit(1);
}
