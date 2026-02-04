<?php
/**
 * Initialize default scheduled tasks
 * Run this script once to populate the scheduled_tasks table with default automation tasks
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

$db = new Database();
$conn = $db->getConnection();

// Check if tasks already exist
$stmt = $conn->query("SELECT COUNT(*) FROM scheduled_tasks");
$count = $stmt->fetchColumn();

if ($count > 0) {
    echo "Scheduled tasks already exist. Skipping initialization.\n";
    exit(0);
}

// Default tasks to create
$default_tasks = [
    [
        'task_name' => 'Send Booking Reminders',
        'task_type' => 'booking_reminder',
        'schedule_type' => 'interval',
        'schedule_value' => '120', // Every 2 hours
        'is_active' => 1,
        'next_run' => date('Y-m-d H:i:s')
    ],
    [
        'task_name' => 'Send Contract Reminders',
        'task_type' => 'contract_reminder',
        'schedule_type' => 'daily',
        'schedule_value' => '10:00', // Daily at 10 AM
        'is_active' => 1,
        'next_run' => date('Y-m-d 10:00:00')
    ],
    [
        'task_name' => 'Send Form Reminders',
        'task_type' => 'form_reminder',
        'schedule_type' => 'daily',
        'schedule_value' => '10:00', // Daily at 10 AM
        'is_active' => 1,
        'next_run' => date('Y-m-d 10:00:00')
    ]
];

// Insert default tasks
$stmt = $conn->prepare("
    INSERT INTO scheduled_tasks (
        task_name, task_type, schedule_type, schedule_value, is_active, next_run
    ) VALUES (?, ?, ?, ?, ?, ?)
");

foreach ($default_tasks as $task) {
    $stmt->execute([
        $task['task_name'],
        $task['task_type'],
        $task['schedule_type'],
        $task['schedule_value'],
        $task['is_active'],
        $task['next_run']
    ]);
    
    echo "✓ Created task: {$task['task_name']}\n";
}

echo "\nDefault scheduled tasks initialized successfully!\n";
echo "You can now set up a CRON job to run /backend/cron/cron.php periodically.\n";
echo "See CRON_SETUP.md for instructions.\n";
?>
