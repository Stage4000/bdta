#!/usr/bin/env php
<?php
/**
 * CRON Job Runner for Brook's Dog Training Academy
 * 
 * This script should be run periodically via a system cron job.
 * It executes scheduled tasks like sending reminder emails, processing
 * expired items, and other automated operations.
 * 
 * Setup:
 * Add to your crontab - runs every 15 minutes:
 * STAR/15 * * * * php /path/to/backend/cron/cron.php >> /path/to/logs/cron.log 2>&1
 * (Replace STAR with *)
 * 
 * Or run hourly:
 * 0 * * * * php /path/to/backend/cron/cron.php >> /path/to/logs/cron.log 2>&1
 */

// Set error reporting for CLI
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Determine the script's directory
$script_dir = dirname(__FILE__);
$backend_dir = dirname($script_dir);

// Include required files
require_once $backend_dir . '/includes/config.php';
require_once $backend_dir . '/includes/database.php';
require_once $backend_dir . '/includes/email_service.php';

// Define task handlers directory
define('TASK_HANDLERS_DIR', __DIR__ . '/tasks');

class CronRunner {
    private Database $db;
    private SafePDO $conn;
    private float $start_time;
    
    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
        $this->start_time = microtime(true);
        
        $this->log("=== CRON Job Started ===");
    }
    
    /**
     * Main execution method
     */
    public function run(): void {
        // Get all active scheduled tasks that are due to run
        $tasks = $this->getDueTasks();
        
        if (empty($tasks)) {
            $this->log("No tasks due to run.");
            return;
        }
        
        $this->log("Found " . count($tasks) . " task(s) to run.");
        
        foreach ($tasks as $task) {
            $this->executeTask($task);
        }
        
        $execution_time = round(microtime(true) - $this->start_time, 2);
        $this->log("=== CRON Job Completed in {$execution_time}s ===");
    }
    
    /**
     * Get tasks that are due to run
     */
    /**
     * @return list<array<string, mixed>>
     */
    private function getDueTasks(): array {
        $current_time = $this->getCurrentUtcDateTime();
        
        $stmt = $this->conn->prepare("
            SELECT * FROM scheduled_tasks 
            WHERE is_active = 1 
            AND (next_run IS NULL OR next_run <= ?)
            ORDER BY id
        ");
        $stmt->execute([$current_time]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Execute a single task
     */
    /**
     * @param array<string, mixed> $task
     */
    private function executeTask(array $task): void {
        $task_start_time = microtime(true);
        $task_id = $task['id'] ?? null;
        $has_valid_task_id = is_int($task_id) || is_string($task_id);
        $task_name = scalar_string($task['task_name'] ?? '');
        $task_type = scalar_string($task['task_type'] ?? '');
        $this->log("Executing task: {$task_name} (Type: {$task_type})");
        $schedule_updated = false;
        
        try {
            // Load the task handler
            $handler_file = TASK_HANDLERS_DIR . '/' . $task_type . '.php';
            
            if (!file_exists($handler_file)) {
                throw new Exception("Task handler not found: {$handler_file}");
            }
            
            require_once $handler_file;
            
            // Get handler class name (convert snake_case to PascalCase)
            $class_name = str_replace('_', '', ucwords($task_type, '_')) . 'Task';
            
            if (!class_exists($class_name)) {
                throw new Exception("Task class not found: {$class_name}");
            }
            
            // Instantiate and run the handler
            $reflection = new ReflectionClass($class_name);
            $constructor = $reflection->getConstructor();

            if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
                $handler = $reflection->newInstance();
            } elseif ($constructor->getNumberOfParameters() === 1) {
                $handler = $reflection->newInstance($this->conn);
            } else {
                $handler = $reflection->newInstance($this->conn, $task);
            }
            if (!method_exists($handler, 'execute')) {
                throw new Exception("Task handler is missing execute(): {$class_name}");
            }
            $result = $handler->execute();
            if (!is_array($result)) {
                throw new RuntimeException("Task handler returned invalid result: {$class_name}");
            }
            
            // Log based on handler result success flag
            $execution_time = round(microtime(true) - $task_start_time, 2);
            $items_processed = safe_int($result['items_processed'] ?? 0);
            $message = scalar_string($result['message'] ?? 'Task completed successfully');
            $success = (bool)($result['success'] ?? true);
            $status = $success ? 'success' : 'error';
            
            if (!is_int($task_id) && !is_string($task_id)) {
                throw new RuntimeException('Task id missing.');
            }
            $this->logTaskExecution($task_id, $task_name, $status, $message, $items_processed, $execution_time);
            $log_prefix = $success ? '✓ Task completed' : '✗ Task completed with errors';
            $this->log("{$log_prefix}: {$message} ({$items_processed} items, {$execution_time}s)");
            
            // Update task's last_run and next_run times
            $this->updateTaskSchedule($task);
            $schedule_updated = true;
            
        } catch (Exception $e) {
            // Log failure
            $execution_time = round(microtime(true) - $task_start_time, 2);
            $error_message = $e->getMessage();
            
            if (is_int($task_id) || is_string($task_id)) {
                $this->logTaskExecution($task_id, $task_name, 'error', $error_message, 0, $execution_time);
            }
            $this->log("✗ Task failed: {$error_message}");
        } finally {
            // Only reschedule here if the success path did not update the task;
            // this keeps failed tasks from thrashing while avoiding a double-update
            // when execute() already advanced the schedule.
            if (!$schedule_updated && $has_valid_task_id) {
                try {
                    $next_run = $this->updateTaskSchedule($task);
                    $this->log("Task rescheduled after failure to {$next_run}: {$task_name}");
                } catch (Exception $scheduleException) {
                    $this->log("Failed to reschedule task; next_run remains unchanged and task may retry immediately: " . $scheduleException->getMessage());
                }
            }
        }
    }
    
    /**
     * @param int|string $task_id
     * @param string $task_name
     * @param string $status success or error
     * @param string $message
     * @param int $items_processed
     * @param float $execution_time
     */
    private function logTaskExecution(int|string $task_id, string $task_name, string $status, string $message, int $items_processed, float $execution_time): void {
        $stmt = $this->conn->prepare("
            INSERT INTO task_logs (task_id, task_name, status, message, items_processed, execution_time)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$task_id, $task_name, $status, $message, $items_processed, $execution_time]);
    }
    
    /**
     * Update task's last_run and calculate next_run
     */
    /**
     * @param array<string, mixed> $task
     */
    private function updateTaskSchedule(array $task): string {
        $current_time = $this->getCurrentUtcDateTime();
        $next_run = $this->calculateNextRun($task);
        $task_id = $task['id'] ?? null;

        if (is_int($task_id)) {
            $task_id_param = $task_id;
        } elseif (is_string($task_id) && ctype_digit($task_id)) {
            $task_id_param = (int) $task_id;
        } else {
            throw new RuntimeException('Invalid task id for schedule update; expected int or numeric string.');
        }
        
        $stmt = $this->conn->prepare("
            UPDATE scheduled_tasks 
            SET last_run = ?, next_run = ?, updated_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$current_time, $next_run, $current_time, $task_id_param]);

        if ($stmt->rowCount() === 0) {
            $exists_stmt = $this->conn->prepare("SELECT 1 FROM scheduled_tasks WHERE id = ? LIMIT 1");
            $exists_stmt->execute([$task_id_param]);

            if ($exists_stmt->fetchColumn() === false) {
                throw new RuntimeException("Failed to update schedule for task {$task_id_param}: task not found.");
            }

            $this->log("Warning: Task schedule update made no changes for task {$task_id_param}.");
        }
        
        return $next_run;
    }
    
    /**
     * Calculate next run time based on schedule
     */
    /**
     * @param array<string, mixed> $task
     */
    private function calculateNextRun(array $task): string {
        $schedule_type = scalar_string($task['schedule_type'] ?? '');
        $schedule_value = scalar_string($task['schedule_value'] ?? '');
        $task_name = scalar_string($task['task_name'] ?? '');
        $now = new DateTimeImmutable('now', bdta_get_display_timezone());
        
        switch ($schedule_type) {
            case 'hourly':
                return $now->modify('+1 hour')->setTimezone(bdta_get_utc_timezone())->format('Y-m-d H:i:s');
            
            case 'daily':
                // Run at specific time (e.g., "09:00")
                if ($schedule_value && !$this->isCronExpression($schedule_value)) {
                    if (preg_match('/^\d{1,2}:\d{2}$/', $schedule_value) === 1) {
                        $next = new DateTimeImmutable('tomorrow ' . $schedule_value, bdta_get_display_timezone());
                        return $next->setTimezone(bdta_get_utc_timezone())->format('Y-m-d H:i:s');
                    }

                    $this->log("Warning: Invalid daily schedule '{$schedule_value}' for task '{$task_name}'. Defaulting to +1 day.");
                }
                return $now->modify('+1 day')->setTimezone(bdta_get_utc_timezone())->format('Y-m-d H:i:s');
            
            case 'weekly':
                // Run on specific day of week at specific time (e.g., "monday 09:00")
                if ($schedule_value) {
                    if (preg_match('/^[a-z]+(?:\s+\d{1,2}:\d{2})?$/i', $schedule_value) === 1) {
                        $next = new DateTimeImmutable('next ' . $schedule_value, bdta_get_display_timezone());
                        return $next->setTimezone(bdta_get_utc_timezone())->format('Y-m-d H:i:s');
                    }

                    $this->log("Warning: Invalid weekly schedule '{$schedule_value}' for task '{$task_name}'. Defaulting to +1 week.");
                }
                return $now->modify('+1 week')->setTimezone(bdta_get_utc_timezone())->format('Y-m-d H:i:s');
            
            case 'interval':
                // Run every X minutes (e.g., "15" for every 15 minutes)
                $minutes = intval($schedule_value) ?: 60;
                return $now->modify("+{$minutes} minutes")->setTimezone(bdta_get_utc_timezone())->format('Y-m-d H:i:s');
            
            case 'custom':
                // Custom schedule using cron expression
                if ($schedule_value && $this->isCronExpression($schedule_value)) {
                    $next_run = $this->parseCronExpression($schedule_value);
                    if ($next_run) {
                        return $next_run;
                    }
                    $this->log("Warning: Unsupported cron expression '{$schedule_value}' for task '{$task_name}'. Defaulting to +15 minutes.");
                }
                // Fallback to 15 minutes for custom schedules
                return $now->modify('+15 minutes')->setTimezone(bdta_get_utc_timezone())->format('Y-m-d H:i:s');
            
            default:
                // Default to daily
                $this->log("Warning: Unknown schedule_type '{$schedule_type}' for task '{$task_name}'. Defaulting to +1 day.");
                return $now->modify('+1 day')->setTimezone(bdta_get_utc_timezone())->format('Y-m-d H:i:s');
        }
    }
    
    /**
     * Check if a string is a cron expression
     */
    private function isCronExpression(string $value): bool {
        // Cron expressions have 5 parts: minute hour day month weekday
        // Pattern: each part can contain digits, *, commas, hyphens, or slashes
        $pattern = '/^(?:[\d*,\/-]+\s+){4}[\d*,\/-]+$/';
        return preg_match($pattern, trim($value)) === 1;
    }
    
    /**
     * Parse common cron expressions and calculate next run time
     * Supports basic patterns like every N minutes, hourly, daily, etc.
     */
    private function parseCronExpression(string $cron): ?string {
        $parts = preg_split('/\s+/', trim($cron)) ?: [];
        if (count($parts) !== 5) {
            return null;
        }
        
        list($minute, $hour, $day, $month, $weekday) = $parts;
        $now = new DateTimeImmutable('now', bdta_get_display_timezone());
        
        // Handle common interval patterns (e.g., */5 * * * * = every 5 minutes)
        if (preg_match('/^\*\/(\d+)$/', $minute, $matches) && $this->areAllWildcards([$hour, $day, $month, $weekday])) {
            $interval = intval($matches[1]);
            return $now->modify("+{$interval} minutes")->setTimezone(bdta_get_utc_timezone())->format('Y-m-d H:i:s');
        }

        // Handle every N hours at a specific minute (e.g., 0 */2 * * * = every 2 hours on the hour)
        if (is_numeric($minute) && preg_match('/^\*\/(\d+)$/', $hour, $matches) && $this->areAllWildcards([$day, $month, $weekday])) {
            $target_minute = intval($minute);
            $hour_interval = max(1, intval($matches[1]));
            $current_hour = (int) $now->format('G');
            $current_minute = (int) $now->format('i');
            $hour_remainder = $current_hour % $hour_interval;
            $hours_until_interval = ($hour_remainder === 0) ? 0 : ($hour_interval - $hour_remainder);

            if ($hours_until_interval === 0 && $target_minute <= $current_minute) {
                $hours_until_interval = $hour_interval;
            }

            $candidate = $now->setTime($current_hour, $target_minute, 0)->modify("+{$hours_until_interval} hours");
            return $candidate->setTimezone(bdta_get_utc_timezone())->format('Y-m-d H:i:s');
        }
        
        // Handle hourly at specific minute (e.g., 15 * * * * = every hour at minute 15)
        if (is_numeric($minute) && $this->areAllWildcards([$hour, $day, $month, $weekday])) {
            $target_minute = intval($minute);

            $next = $now->setTime((int) $now->format('H'), $target_minute, 0);
            if ($next <= $now) {
                $next = $next->modify('+1 hour');
            }

            return $next->setTimezone(bdta_get_utc_timezone())->format('Y-m-d H:i:s');
        }
        
        // Handle daily at specific time (e.g., 0 9 * * * = daily at 9:00 AM)
        if (is_numeric($minute) && is_numeric($hour) && $this->areAllWildcards([$day, $month, $weekday])) {
            $target_hour = intval($hour);
            $target_minute = intval($minute);

            $next = $now->setTime($target_hour, $target_minute, 0);
            if ($next <= $now) {
                $next = $next->modify('+1 day');
            }

            return $next->setTimezone(bdta_get_utc_timezone())->format('Y-m-d H:i:s');
        }
        
        // Pattern not supported
        return null;
    }
    
    /**
     * Check if all provided cron parts are wildcards
     */
    /**
     * @param list<string> $parts
     */
    private function areAllWildcards(array $parts): bool {
        foreach ($parts as $part) {
            if ($part !== '*') {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Log to console/file
     */
    private function log(string $message): void {
        $timestamp = $this->getCurrentUtcDateTime();
        echo "[{$timestamp}] {$message}\n";
    }
    
    /**
     * Compatibility wrapper for currentUtcDateTime() so cron can still run
     * against older or mismatched deployments where config.php is present but
     * that specific helper has not been loaded yet, falling back to gmdate().
     */
    private function getCurrentUtcDateTime(): string {
        if (function_exists('currentUtcDateTime')) {
            return currentUtcDateTime();
        }
        
        static $warned = false;
        if (!$warned) {
            error_log('CronRunner: currentUtcDateTime() helper function not available, falling back to gmdate()');
            $warned = true;
        }
        
        return gmdate('Y-m-d H:i:s');
    }
}

// Allow tests to include this file without executing the runner.
if (!defined('BDTA_CRON_BOOTSTRAP_ONLY')) {
    $cron = new CronRunner();
    $cron->run();
}
?>
