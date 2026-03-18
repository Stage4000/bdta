#!/usr/bin/env php
<?php
/**
 * Verify legacy workflow cron tasks still resolve to the workflow processor handler.
 */

$handler_file = __DIR__ . '/backend/cron/tasks/workflow.php';

if (!file_exists($handler_file)) {
    throw new RuntimeException('Legacy workflow task handler file is missing.');
}

require_once __DIR__ . '/backend/includes/config.php';
require_once __DIR__ . '/backend/includes/database.php';
require_once $handler_file;

echo "=== Workflow Task Handler Alias Test ===\n\n";

if (!class_exists('WorkflowTask')) {
    throw new RuntimeException('Legacy WorkflowTask class is missing.');
}

if (!is_subclass_of('WorkflowTask', 'WorkflowProcessorTask')) {
    throw new RuntimeException('WorkflowTask should extend WorkflowProcessorTask.');
}

$reflection = new ReflectionClass('WorkflowTask');
$constructor = $reflection->getConstructor();

if (!$constructor instanceof ReflectionMethod) {
    throw new RuntimeException('WorkflowTask constructor is missing.');
}

if ($constructor->getNumberOfParameters() !== 1) {
    throw new RuntimeException('WorkflowTask constructor should accept the cron database connection.');
}

echo "✓ Legacy workflow handler file exists: {$handler_file}\n";
echo "✓ Legacy workflow task class resolves to WorkflowProcessorTask\n";
echo "\nAll workflow handler alias tests passed!\n";
