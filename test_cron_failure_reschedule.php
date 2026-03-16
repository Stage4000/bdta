#!/usr/bin/env php
<?php
/**
 * Verify that failing cron tasks are rescheduled instead of retried every minute.
 */

require_once __DIR__ . '/backend/includes/database.php';
require_once __DIR__ . '/backend/includes/config.php';

define('BDTA_CRON_BOOTSTRAP_ONLY', true);
require_once __DIR__ . '/backend/cron/cron.php';

$db = new Database();
$conn = $db->getConnection();

$task_name = 'Test Reschedule After Failure ' . uniqid('test_', false);
$cleanup_stmt = $conn->prepare("DELETE FROM scheduled_tasks WHERE id = ?");

$insert = $conn->prepare("
    INSERT INTO scheduled_tasks (task_name, task_type, schedule_type, schedule_value, is_active, next_run, last_run)
    VALUES (?, ?, ?, ?, 1, ?, NULL)
");
$cron = new CronRunner();
$cron_reflection = new ReflectionClass('CronRunner');
$get_current_time = $cron_reflection->getMethod('getCurrentUtcDateTime');
$get_current_time->setAccessible(true);
$now_utc = (string) $get_current_time->invoke($cron);
$past_time = (new DateTimeImmutable($now_utc, new DateTimeZone('UTC')))
    ->modify('-1 hour')
    ->format('Y-m-d H:i:s');
$insert->execute([
    $task_name,
    'nonexistent_handler',
    'hourly',
    '', // hourly schedule_type ignores schedule_value
    $past_time
]);
$task_id = (int) $conn->lastInsertId();

if ($task_id <= 0) {
    throw new RuntimeException('Failed to determine inserted test task id.');
}

$task_stmt = $conn->prepare("SELECT * FROM scheduled_tasks WHERE id = ?");
$task_stmt->execute([$task_id]);
$task = $task_stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    throw new RuntimeException('Failed to insert test task.');
}

try {
    $execute_task = $cron_reflection->getMethod('executeTask');
    $execute_task->setAccessible(true);
    // Call the single-task executor directly so we don't trigger real scheduled tasks
    // that may exist in the database during local runs.
    $execute_task->invoke($cron, $task);

    $next_stmt = $conn->prepare("SELECT next_run FROM scheduled_tasks WHERE id = ?");
    $next_stmt->execute([$task_id]);
    $next_value = $next_stmt->fetchColumn();
    if ($next_value === false) {
        throw new RuntimeException('Failed to load updated next_run value.');
    }
    $updated_next_run = (string) $next_value;

    if ($updated_next_run === '') {
        throw new RuntimeException('Task next_run was not updated.');
    }

    $original_time = new DateTimeImmutable($past_time, new DateTimeZone('UTC'));
    $updated_time = new DateTimeImmutable($updated_next_run, new DateTimeZone('UTC'));

    if ($updated_time <= $original_time) {
        throw new RuntimeException('Task next_run did not advance after failure.');
    }

    echo "=== Cron Failure Reschedule Test ===\n\n";
    echo "✓ Task failure rescheduled to future run time: {$updated_next_run}\n";
    echo "\nAll cron failure reschedule tests passed!\n";
} finally {
    $cleanup_stmt->execute([$task_id]);
}
