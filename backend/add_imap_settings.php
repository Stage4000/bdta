<?php
/**
 * Migration Script: Add IMAP Email Settings
 * This script adds IMAP email receiving settings to the settings table
 * Run this if you don't see IMAP options in Settings -> Email
 */

require_once __DIR__ . '/includes/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    echo "Checking for IMAP settings...\n";
    
    // Check if IMAP settings already exist
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM settings WHERE setting_key = 'imap_enabled'");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] > 0) {
        echo "IMAP settings already exist in the database.\n";
        exit(0);
    }
    
    echo "Adding IMAP settings to database...\n";
    
    // IMAP Settings to add
    $imap_settings = [
        ['imap_enabled', '0', 'checkbox', 'email', 'Enable IMAP Email Receiving', 'Fetch incoming emails automatically', 0],
        ['imap_host', '', 'text', 'email', 'IMAP Host', 'IMAP server hostname (e.g., imap.gmail.com)', 0],
        ['imap_port', '993', 'number', 'email', 'IMAP Port', 'IMAP server port (993 for SSL, 143 for TLS)', 0],
        ['imap_encryption', 'ssl', 'select', 'email', 'IMAP Encryption', 'Encryption method (ssl, tls, none)', 0],
        ['imap_username', '', 'text', 'email', 'IMAP Username', 'IMAP authentication username (usually email address)', 0],
        ['imap_password', '', 'password', 'email', 'IMAP Password', 'IMAP authentication password', 1],
        ['imap_folder', 'INBOX', 'text', 'email', 'IMAP Folder', 'Folder to fetch emails from (default: INBOX)', 0],
        ['imap_sync_days', '30', 'number', 'email', 'Sync Days', 'How many days of emails to sync (default: 30)', 0],
    ];
    
    $stmt = $conn->prepare("
        INSERT INTO settings (setting_key, setting_value, setting_type, category, label, description, is_secret)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $added = 0;
    foreach ($imap_settings as $setting) {
        $stmt->execute($setting);
        $added++;
    }
    
    echo "Successfully added $added IMAP settings to the database.\n";
    echo "\nIMAP settings are now available in Settings -> Email\n";
    echo "Please scroll down on the Email Settings page to see them.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
