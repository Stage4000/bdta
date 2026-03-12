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
        $current_time = date('Y-m-d H:i:s');
        
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
        $task_name = scalar_string($task['task_name'] ?? '');
        $task_type = scalar_string($task['task_type'] ?? '');
        $this->log("Executing task: {$task_name} (Type: {$task_type})");
        
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
            $handler = new $class_name($this->conn, $task);
            if (!method_exists($handler, 'execute')) {
                throw new Exception("Task handler is missing execute(): {$class_name}");
            }
            $result = $handler->execute();
            
            // Log success
            $execution_time = round(microtime(true) - $task_start_time, 2);
            $items_processed = $result['items_processed'] ?? 0;
            $message = $result['message'] ?? 'Task completed successfully';
            
            if (!is_int($task_id) && !is_string($task_id)) {
                throw new RuntimeException('Task id missing.');
            }
            $this->logTaskExecution($task_id, $task_name, 'success', $message, $items_processed, $execution_time);
            $this->log("✓ Task completed: {$message} ({$items_processed} items, {$execution_time}s)");
            
            // Update task's last_run and next_run times
            $this->updateTaskSchedule($task);
            
        } catch (Exception $e) {
            // Log failure
            $execution_time = round(microtime(true) - $task_start_time, 2);
            $error_message = $e->getMessage();
            
            if (is_int($task_id) || is_string($task_id)) {
                $this->logTaskExecution($task_id, $task_name, 'error', $error_message, 0, $execution_time);
            }
            $this->log("✗ Task failed: {$error_message}");
        }
    }
    
    /**
     * Log task execution to database
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
    private function updateTaskSchedule(array $task): void {
        $current_time = date('Y-m-d H:i:s');
        $next_run = $this->calculateNextRun($task);
        $task_id = $task['id'] ?? null;
        
        $stmt = $this->conn->prepare("
            UPDATE scheduled_tasks 
            SET last_run = ?, next_run = ?, updated_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$current_time, $next_run, $current_time, $task_id]);
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
        
        switch ($schedule_type) {
            case 'hourly':
                return date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            case 'daily':
                // Run at specific time (e.g., "09:00")
                if ($schedule_value && !$this->isCronExpression($schedule_value)) {
                    $time_parts = explode(':', $schedule_value);
                    $next = strtotime('tomorrow ' . $schedule_value);
                    return date('Y-m-d H:i:s', safe_timestamp($next));
                }
                return date('Y-m-d H:i:s', strtotime('+1 day'));
            
            case 'weekly':
                // Run on specific day of week at specific time (e.g., "monday 09:00")
                if ($schedule_value) {
                    $next = strtotime('next ' . $schedule_value);
                    return date('Y-m-d H:i:s', safe_timestamp($next));
                }
                return date('Y-m-d H:i:s', strtotime('+1 week'));
            
            case 'interval':
                // Run every X minutes (e.g., "15" for every 15 minutes)
                $minutes = intval($schedule_value) ?: 60;
                return date('Y-m-d H:i:s', safe_timestamp(strtotime("+{$minutes} minutes")));
            
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
                return date('Y-m-d H:i:s', strtotime('+15 minutes'));
            
            default:
                // Default to daily
                $this->log("Warning: Unknown schedule_type '{$schedule_type}' for task '{$task_name}'. Defaulting to +1 day.");
                return date('Y-m-d H:i:s', strtotime('+1 day'));
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
        
        // Handle common interval patterns (e.g., */5 * * * * = every 5 minutes)
        if (preg_match('/^\*\/(\d+)$/', $minute, $matches) && $this->areAllWildcards([$hour, $day, $month, $weekday])) {
            $interval = intval($matches[1]);
            return date('Y-m-d H:i:s', safe_timestamp(strtotime("+{$interval} minutes")));
        }
        
        // Handle hourly at specific minute (e.g., 15 * * * * = every hour at minute 15)
        if (is_numeric($minute) && $this->areAllWildcards([$hour, $day, $month, $weekday])) {
            $current_minute = intval(date('i'));
            $target_minute = intval($minute);
            
            if ($current_minute < $target_minute) {
                // Later this hour
                $next = mktime(intval(date('H')), $target_minute, 0);
            } else {
                // Next hour
                $next = mktime(intval(date('H')) + 1, $target_minute, 0);
            }
            return date('Y-m-d H:i:s', safe_timestamp($next));
        }
        
        // Handle daily at specific time (e.g., 0 9 * * * = daily at 9:00 AM)
        if (is_numeric($minute) && is_numeric($hour) && $this->areAllWildcards([$day, $month, $weekday])) {
            $target_hour = intval($hour);
            $target_minute = intval($minute);
            $current_time = time();
            $today_run = mktime($target_hour, $target_minute, 0);
            
            if ($today_run > $current_time) {
                // Later today
                return date('Y-m-d H:i:s', $today_run);
            } else {
                // Tomorrow at the same time
                return date('Y-m-d H:i:s', safe_timestamp(strtotime('+1 day', safe_timestamp($today_run))));
            }
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
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] {$message}\n";
    }
}

// Run the cron job
$cron = new CronRunner();
$cron->run();
?>
