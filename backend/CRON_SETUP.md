# CRON Job Setup Guide

This guide explains how to set up automated tasks for Brook's Dog Training Academy CRM using CRON jobs.

## Overview

The CRON job system automates the following tasks:
- **Booking Reminders**: Send reminder emails 24 hours before appointments
- **Contract Reminders**: Remind clients to sign outstanding contracts
- **Form Reminders**: Remind clients to complete pending forms
- **Quote Reminders**: Remind clients about quotes awaiting approval (3+ days old)
- **Invoice Reminders**: Send overdue payment reminders for unpaid invoices
- **Workflow Processor**: Execute automated workflow email sequences

## Quick Setup

### 1. Make the CRON Script Executable

```bash
chmod +x /path/to/backend/cron/cron.php
```

### 2. Configure Your Crontab

Open your crontab editor:

```bash
crontab -e
```

### 3. Add CRON Schedule

Choose one of the following schedules based on your needs:

#### Run Every 15 Minutes (Recommended)
```cron
*/15 * * * * php /path/to/backend/cron/cron.php >> /path/to/logs/cron.log 2>&1
```

#### Run Every Hour
```cron
0 * * * * php /path/to/backend/cron/cron.php >> /path/to/logs/cron.log 2>&1
```

#### Run Daily at 9:00 AM
```cron
0 9 * * * php /path/to/backend/cron/cron.php >> /path/to/logs/cron.log 2>&1
```

**Important**: Replace `/path/to/` with the actual path to your installation.

### 4. Create Log Directory

```bash
mkdir -p /path/to/logs
touch /path/to/logs/cron.log
chmod 644 /path/to/logs/cron.log
```

## Manual Testing

You can test the CRON job manually before setting up automation:

```bash
php /path/to/backend/cron/cron.php
```

This will execute all scheduled tasks immediately and display the results.

## Available Tasks

### 1. Booking Reminder
- **Task Type**: `booking_reminder`
- **Description**: Sends reminder emails to clients 24 hours before their appointment
- **Default Schedule**: Every 2 hours
- **Email Features**:
  - Professional reminder template
  - Calendar export links (Google Calendar & iCal)
  - Appointment details

### 2. Contract Reminder
- **Task Type**: `contract_reminder`
- **Description**: Sends reminders for unsigned contracts sent more than 3 days ago
- **Default Schedule**: Daily at 10:00 AM
- **Email Features**:
  - Direct link to view and sign contract
  - Professional reminder template

### 3. Form Reminder
- **Task Type**: `form_reminder`
- **Description**: Sends reminders for incomplete forms sent more than 3 days ago
- **Default Schedule**: Daily at 10:00 AM
- **Email Features**:
  - Direct link to complete form
  - Professional reminder template

### 4. Quote Reminder
- **Task Type**: `quote_reminder`
- **Description**: Sends reminders for quotes sent but not approved for 3+ days
- **Default Schedule**: Daily at 11:00 AM
- **Email Features**:
  - Highlights expiration date if quote expires soon
  - Direct link to view and respond to quote
  - Professional reminder template

### 5. Invoice Reminder
- **Task Type**: `invoice_reminder`
- **Description**: Sends reminders for overdue invoices
- **Default Schedule**: Daily at 9:00 AM
- **Email Features**:
  - Shows days overdue
  - Direct payment link
  - Amount due (including partial payments)

### 6. Workflow Processor
- **Task Type**: `workflow_processor`
- **Description**: Executes automated workflow email sequences
- **Default Schedule**: Every hour
- **Features**:
  - Sends scheduled workflow emails to enrolled clients
  - Supports time-based and date-based scheduling
  - Includes contract, form, quote, invoice, and appointment link attachments
  - Automatic enrollment completion tracking

## Managing Scheduled Tasks

### Using the Admin Panel (Recommended)

1. Log in to the admin panel at `/client/`
2. Navigate to **Settings** → **Scheduled Tasks**
3. You can:
   - View all scheduled tasks
   - Enable/disable tasks
   - View execution logs
   - Configure task schedules

### Database Management (Advanced)

Tasks are stored in the `scheduled_tasks` table. You can manually insert or update tasks:

```sql
INSERT INTO scheduled_tasks (
    task_name, 
    task_type, 
    schedule_type, 
    schedule_value, 
    is_active
) VALUES (
    'Send Booking Reminders',
    'booking_reminder',
    'interval',
    '120',  -- Run every 120 minutes (2 hours)
    1
);
```

#### Schedule Types

- **hourly**: Runs every hour
- **daily**: Runs once per day at specified time (e.g., "09:00")
- **weekly**: Runs on specific day at specific time (e.g., "monday 09:00")
- **interval**: Runs every X minutes (e.g., "15" for every 15 minutes)

## Monitoring

### View Task Execution Logs

Check the `task_logs` table to monitor task execution:

```sql
SELECT * FROM task_logs ORDER BY executed_at DESC LIMIT 20;
```

### CRON Log File

View the CRON log file for detailed execution information:

```bash
tail -f /path/to/logs/cron.log
```

### Log Format

```
[2024-02-04 09:00:01] === CRON Job Started ===
[2024-02-04 09:00:01] Found 3 task(s) to run.
[2024-02-04 09:00:01] Executing task: Send Booking Reminders (Type: booking_reminder)
[2024-02-04 09:00:02] ✓ Task completed: Sent 5 reminder email(s) (5 items, 0.82s)
[2024-02-04 09:00:02] === CRON Job Completed in 1.25s ===
```

## Troubleshooting

### CRON Job Not Running

1. **Check CRON Service**: Ensure CRON daemon is running
   ```bash
   sudo service cron status
   ```

2. **Verify Path**: Make sure the path to `cron.php` is correct
   ```bash
   which php  # Find PHP path
   ls -la /path/to/backend/cron/cron.php  # Verify file exists
   ```

3. **Check Permissions**: Ensure the script is executable
   ```bash
   chmod +x /path/to/backend/cron/cron.php
   ```

### No Emails Being Sent

1. **Check Email Configuration**: Verify SMTP settings in admin panel
2. **Test Email Service**: Use the email test tool in `/backend/public/test_email.php`
3. **Check Task Logs**: Review `task_logs` table for error messages

### Task Not Executing

1. **Check Task Status**: Ensure the task is active in `scheduled_tasks` table
2. **Verify Next Run Time**: Check `next_run` column - task won't run if in the future
3. **Review Error Logs**: Check both CRON log and `task_logs` table

## Security Considerations

1. **File Permissions**: Ensure CRON script has appropriate permissions (644 or 744)
2. **Log Files**: Rotate log files regularly to prevent disk space issues
3. **Database Access**: CRON script uses same database credentials as main application
4. **Email Rate Limiting**: Tasks are designed to prevent email spam by limiting frequency

## Adding Custom Tasks

To create a new automated task:

1. Create a new PHP file in `/backend/cron/tasks/` (e.g., `my_custom_task.php`)
2. Implement the task class following this template:

```php
<?php
class MyCustomTask {
    private $conn;
    private $task;
    
    public function __construct($conn, $task) {
        $this->conn = $conn;
        $this->task = $task;
    }
    
    public function execute() {
        // Your task logic here
        
        return [
            'success' => true,
            'items_processed' => 0,
            'message' => 'Task completed'
        ];
    }
}
?>
```

3. Add the task to the `scheduled_tasks` table
4. Test manually: `php /path/to/backend/cron/cron.php`

## Support

For questions or issues with CRON job setup, please refer to:
- Main README.md
- Backend documentation in `/backend/README.md`
- Email configuration guide in `/backend/EMAIL_CONFIGURATION.md`
