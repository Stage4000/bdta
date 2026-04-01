#!/usr/bin/env php
<?php
/**
 * Verify workflow step delay validation aligns with workflow processor cadence.
 */

require_once dirname(__DIR__) . '/backend/includes/config.php';
require_once dirname(__DIR__) . '/backend/includes/database.php';
require_once dirname(__DIR__) . '/backend/includes/workflow_helper.php';

function assertWorkflowIntervalTest(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$exit_code = 0;
$db = null;
$conn = null;

try {
    $db = new Database();
    $conn = $db->getConnection();

    $conn->beginTransaction();
    $delete_tasks = $conn->prepare("DELETE FROM scheduled_tasks WHERE task_type IN ('workflow_processor', 'workflow')");
    $delete_tasks->execute();

    assertWorkflowIntervalTest(
        bdta_get_workflow_processor_interval_minutes($conn) === 60,
        'Expected default workflow processor interval fallback of 60 minutes.'
    );

    $insert_task = $conn->prepare("
        INSERT INTO scheduled_tasks (task_name, task_type, schedule_type, schedule_value, is_active)
        VALUES (?, ?, ?, ?, 1)
    ");
    $insert_task->execute(['Workflow Processor Hourly', 'workflow_processor', 'hourly', '']);
    assertWorkflowIntervalTest(
        bdta_get_workflow_processor_interval_minutes($conn) === 60,
        'Hourly workflow processor should resolve to a 60 minute cadence.'
    );

    $delete_tasks->execute();
    $insert_task->execute(['Workflow Processor Interval', 'workflow_processor', 'interval', '90']);
    assertWorkflowIntervalTest(
        bdta_get_workflow_processor_interval_minutes($conn) === 90,
        'Interval schedule should use the configured number of minutes.'
    );

    $delete_tasks->execute();
    $insert_task->execute(['Workflow Processor Custom', 'workflow_processor', 'custom', '*/15 * * * *']);
    assertWorkflowIntervalTest(
        bdta_get_workflow_processor_interval_minutes($conn) === 15,
        'Custom cron cadence */15 should resolve to 15 minutes.'
    );

    $delay_minutes = bdta_parse_workflow_delay_to_minutes('30 minutes');
    $processor_interval = bdta_get_workflow_processor_interval_minutes($conn);
    assertWorkflowIntervalTest(
        $delay_minutes >= $processor_interval,
        'A 30 minute delay should be allowed when workflow processor cadence is 15 minutes.'
    );

    $delay_minutes = bdta_parse_workflow_delay_to_minutes('10 minutes');
    assertWorkflowIntervalTest(
        $delay_minutes < $processor_interval,
        'A 10 minute delay should be blocked when workflow processor cadence is 15 minutes.'
    );

    echo "Workflow step interval validation helper tests passed.\n";
} catch (Throwable $e) {
    $exit_code = 1;
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
} finally {
    if ($conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }

    $conn = null;
    $db = null;
}

exit($exit_code);
