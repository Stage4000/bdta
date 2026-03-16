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

$task_name = 'Test Reschedule After Failure';
$cleanup_stmt = $conn->prepare("DELETE FROM scheduled_tasks WHERE task_name = ?");
$cleanup_stmt->execute([$task_name]);

$insert = $conn->prepare("
    INSERT INTO scheduled_tasks (task_name, task_type, schedule_type, schedule_value, is_active, next_run, last_run)
    VALUES (?, ?, ?, ?, 1, ?, NULL)
");
$past_time = gmdate('Y-m-d H:i:s', time() - 3600);
$insert->execute([$task_name, 'nonexistent_handler', 'hourly', '', $past_time]);
$task_id = (int) $conn->lastInsertId();

$task_stmt = $conn->prepare("SELECT * FROM scheduled_tasks WHERE id = ?");
$task_stmt->execute([$task_id]);
$task = $task_stmt->fetch(PDO::FETCH_ASSOC);

if (!$task) {
    throw new RuntimeException('Failed to insert test task.');
}

$cron = new CronRunner();
$cron_reflection = new ReflectionClass('CronRunner');
$execute_task = $cron_reflection->getMethod('executeTask');
$execute_task->setAccessible(true);
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

$cleanup_stmt->execute([$task_name]);

echo "=== Cron Failure Reschedule Test ===\n\n";
echo "✓ Task failure rescheduled to future run time: {$updated_next_run}\n";
echo "\nAll cron failure reschedule tests passed!\n";
