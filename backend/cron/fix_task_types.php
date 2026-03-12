#!/usr/bin/env php
<?php
/**
 * Fix incorrect task_type values in scheduled_tasks table
 * 
 * This script corrects task_type values that don't match actual task handler filenames.
 * Run this once to fix the "task handler not found" errors.
 * 
 * Usage: php fix_task_types.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

echo "=== Fixing Task Type Values ===\n\n";

$db = new Database();
$conn = $db->getConnection();

// Simple mapping of incorrect task_type values to correct ones
$simple_fixes = [
    'reminder' => 'booking_reminder',
    'workflow' => 'workflow_processor'
];

// First, let's see what tasks currently exist
echo "Current scheduled tasks:\n";
echo str_repeat('-', 80) . "\n";

$stmt = $conn->query("SELECT id, task_name, task_type, is_active FROM scheduled_tasks ORDER BY id");
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($tasks)) {
    echo "No scheduled tasks found in database.\n";
    echo "\nIt appears the scheduled_tasks table is empty.\n";
    echo "Please run: php init_tasks.php\n\n";
    exit(1);
}

foreach ($tasks as $task) {
    $status = $task['is_active'] ? 'Active' : 'Inactive';
    echo sprintf("ID: %-3s | %-30s | Type: %-25s | %s\n", 
        $task['id'], 
        $task['task_name'], 
        $task['task_type'],
        $status
    );
}

echo str_repeat('-', 80) . "\n\n";

// Check which tasks need to be fixed
$needs_fixing = [];
foreach ($tasks as $task) {
    $handler_file = __DIR__ . '/tasks/' . $task['task_type'] . '.php';
    if (!file_exists($handler_file)) {
        $needs_fixing[] = $task;
        echo "⚠ Task '{$task['task_name']}' has invalid task_type: '{$task['task_type']}'\n";
        echo "  Handler not found: {$handler_file}\n";
    }
}

if (empty($needs_fixing)) {
    echo "✓ All task types are valid! No fixes needed.\n";
    exit(0);
}

echo "\n" . count($needs_fixing) . " task(s) need to be fixed.\n\n";

// Apply fixes
$update_stmt = $conn->prepare("UPDATE scheduled_tasks SET task_type = ?, updated_at = ? WHERE id = ?");
$fixed_count = 0;
$skipped_count = 0;

foreach ($needs_fixing as $task) {
    $old_type = scalar_string($task['task_type'] ?? '');
    $new_type = null;
    
    // Check if we have a simple mapping fix for this task_type
    if (isset($simple_fixes[$old_type])) {
        $new_type = $simple_fixes[$old_type];
    }
    // Special handling for generic 'email' task_type - use task name to determine correct type
    elseif ($old_type === 'email') {
        $task_name_lower = strtolower(scalar_string($task['task_name'] ?? ''));
        if (strpos($task_name_lower, 'receive') !== false || strpos($task_name_lower, 'imap') !== false) {
            $new_type = 'email_receiver';
        } elseif (strpos($task_name_lower, 'send') !== false || strpos($task_name_lower, 'scheduled') !== false) {
            $new_type = 'scheduled_email_sender';
        } else {
            // Default to scheduled_email_sender if we can't determine from name
            $new_type = 'scheduled_email_sender';
        }
    }
    
    if ($new_type) {
        // Verify the new handler file exists
        $new_handler = __DIR__ . '/tasks/' . $new_type . '.php';
        if (file_exists($new_handler)) {
            $update_stmt->execute([$new_type, date('Y-m-d H:i:s'), $task['id']]);
            echo "✓ Fixed: '{$task['task_name']}' - Changed '{$old_type}' → '{$new_type}'\n";
            $fixed_count++;
        } else {
            echo "✗ Skipped: '{$task['task_name']}' - Handler still not found: {$new_handler}\n";
            $skipped_count++;
        }
    } else {
        echo "⚠ Skipped: '{$task['task_name']}' - No automatic fix available for task_type '{$old_type}'\n";
        echo "  Please manually update this task or delete it.\n";
        $skipped_count++;
    }
}

echo "\n" . str_repeat('=', 80) . "\n";
echo "Fix Summary:\n";
echo "  ✓ Fixed: {$fixed_count} task(s)\n";
echo "  ⚠ Skipped: {$skipped_count} task(s)\n";

if ($skipped_count > 0) {
    echo "\nFor skipped tasks, you may need to:\n";
    echo "1. Manually update the task_type in the database\n";
    echo "2. Delete the invalid task and run init_tasks.php to recreate defaults\n";
    echo "3. Use the admin panel to manage tasks\n";
}

echo "\n✓ Task type fixes completed!\n";
?>
