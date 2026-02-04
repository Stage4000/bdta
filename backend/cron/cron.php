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

// Set timezone
date_default_timezone_set('America/New_York');

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
    private $db;
    private $conn;
    private $start_time;
    
    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
        $this->start_time = microtime(true);
        
        $this->log("=== CRON Job Started ===");
    }
    
    /**
     * Main execution method
     */
    public function run() {
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
    private function getDueTasks() {
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
    private function executeTask($task) {
        $task_start_time = microtime(true);
        $this->log("Executing task: {$task['task_name']} (Type: {$task['task_type']})");
        
        try {
            // Load the task handler
            $handler_file = TASK_HANDLERS_DIR . '/' . $task['task_type'] . '.php';
            
            if (!file_exists($handler_file)) {
                throw new Exception("Task handler not found: {$handler_file}");
            }
            
            require_once $handler_file;
            
            // Get handler class name (convert snake_case to PascalCase)
            $class_name = str_replace('_', '', ucwords($task['task_type'], '_')) . 'Task';
            
            if (!class_exists($class_name)) {
                throw new Exception("Task class not found: {$class_name}");
            }
            
            // Instantiate and run the handler
            $handler = new $class_name($this->conn, $task);
            $result = $handler->execute();
            
            // Log success
            $execution_time = round(microtime(true) - $task_start_time, 2);
            $items_processed = $result['items_processed'] ?? 0;
            $message = $result['message'] ?? 'Task completed successfully';
            
            $this->logTaskExecution($task['id'], $task['task_name'], 'success', $message, $items_processed, $execution_time);
            $this->log("✓ Task completed: {$message} ({$items_processed} items, {$execution_time}s)");
            
            // Update task's last_run and next_run times
            $this->updateTaskSchedule($task);
            
        } catch (Exception $e) {
            // Log failure
            $execution_time = round(microtime(true) - $task_start_time, 2);
            $error_message = $e->getMessage();
            
            $this->logTaskExecution($task['id'], $task['task_name'], 'error', $error_message, 0, $execution_time);
            $this->log("✗ Task failed: {$error_message}");
        }
    }
    
    /**
     * Log task execution to database
     */
    private function logTaskExecution($task_id, $task_name, $status, $message, $items_processed, $execution_time) {
        $stmt = $this->conn->prepare("
            INSERT INTO task_logs (task_id, task_name, status, message, items_processed, execution_time)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$task_id, $task_name, $status, $message, $items_processed, $execution_time]);
    }
    
    /**
     * Update task's last_run and calculate next_run
     */
    private function updateTaskSchedule($task) {
        $current_time = date('Y-m-d H:i:s');
        $next_run = $this->calculateNextRun($task);
        
        $stmt = $this->conn->prepare("
            UPDATE scheduled_tasks 
            SET last_run = ?, next_run = ?, updated_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$current_time, $next_run, $current_time, $task['id']]);
    }
    
    /**
     * Calculate next run time based on schedule
     */
    private function calculateNextRun($task) {
        $schedule_type = $task['schedule_type'];
        $schedule_value = $task['schedule_value'];
        
        switch ($schedule_type) {
            case 'hourly':
                return date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            case 'daily':
                // Run at specific time (e.g., "09:00")
                if ($schedule_value) {
                    $time_parts = explode(':', $schedule_value);
                    $next = strtotime('tomorrow ' . $schedule_value);
                    return date('Y-m-d H:i:s', $next);
                }
                return date('Y-m-d H:i:s', strtotime('+1 day'));
            
            case 'weekly':
                // Run on specific day of week at specific time (e.g., "monday 09:00")
                if ($schedule_value) {
                    $next = strtotime('next ' . $schedule_value);
                    return date('Y-m-d H:i:s', $next);
                }
                return date('Y-m-d H:i:s', strtotime('+1 week'));
            
            case 'interval':
                // Run every X minutes (e.g., "15" for every 15 minutes)
                $minutes = intval($schedule_value) ?: 60;
                return date('Y-m-d H:i:s', strtotime("+{$minutes} minutes"));
            
            default:
                // Default to daily
                return date('Y-m-d H:i:s', strtotime('+1 day'));
        }
    }
    
    /**
     * Log to console/file
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        echo "[{$timestamp}] {$message}\n";
    }
}

// Run the cron job
$cron = new CronRunner();
$cron->run();
?>
