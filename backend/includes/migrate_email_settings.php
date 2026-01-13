<?php
/**
 * Migration script to add new SMTP settings to existing databases
 * Run this once to update an existing database with new email settings
 */

require_once __DIR__ . '/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    echo "Starting email settings migration...\n";
    
    // Check if smtp_encryption setting exists
    $stmt = $conn->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
    $stmt->execute(['smtp_encryption']);
    $exists = $stmt->fetchColumn() > 0;
    
    if (!$exists) {
        echo "Adding smtp_encryption setting...\n";
        $stmt = $conn->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_type, category, label, description, is_secret)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            'smtp_encryption',
            'tls',
            'select',
            'email',
            'SMTP Encryption',
            'Encryption method (tls, ssl, none)',
            0
        ]);
        echo "✓ Added smtp_encryption setting\n";
    } else {
        echo "✓ smtp_encryption setting already exists\n";
    }
    
    // Check if smtp_debug setting exists
    $stmt->execute(['smtp_debug']);
    $exists = $stmt->fetchColumn() > 0;
    
    if (!$exists) {
        echo "Adding smtp_debug setting...\n";
        $stmt = $conn->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_type, category, label, description, is_secret)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            'smtp_debug',
            '0',
            'checkbox',
            'email',
            'SMTP Debug Mode',
            'Enable detailed debug logging for troubleshooting',
            0
        ]);
        echo "✓ Added smtp_debug setting\n";
    } else {
        echo "✓ smtp_debug setting already exists\n";
    }
    
    // Update descriptions for existing settings
    echo "Updating setting descriptions...\n";
    
    $updates = [
        ['smtp_port', 'SMTP server port (587 for TLS, 465 for SSL)'],
        ['smtp_username', 'SMTP authentication username (leave empty if not required)'],
        ['smtp_password', 'SMTP authentication password (leave empty if not required)']
    ];
    
    $stmt = $conn->prepare("UPDATE settings SET description = ? WHERE setting_key = ?");
    foreach ($updates as $update) {
        $stmt->execute([$update[1], $update[0]]);
    }
    echo "✓ Updated setting descriptions\n";
    
    echo "\nMigration completed successfully!\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
