<?php
/**
 * Backward-compatible workflow task handler alias.
 */

require_once __DIR__ . '/workflow_processor.php';

/**
 * Legacy alias for scheduled tasks still using task_type = "workflow".
 * New task registrations should use WorkflowProcessorTask via workflow_processor.php.
 */
class WorkflowTask extends WorkflowProcessorTask {
}
