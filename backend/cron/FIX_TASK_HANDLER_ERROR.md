# Fixing "Task Handler Not Found" Errors

## Update (Fixed)

**The `email.php` and `workflow.php` handlers have been added** to provide backward compatibility for tasks with `task_type = 'email'` and `task_type = 'workflow'`. 

If you previously saw the error:
```
Task handler not found: /var/www/.../backend/cron/tasks/email.php
Task handler not found: /var/www/.../backend/cron/tasks/workflow.php
```

These errors should now be **resolved automatically**. The `email` task type delegates to the scheduled email sender, and the legacy `workflow` task type delegates to `workflow_processor.php`.

For `reminder` errors, continue reading below for the solution. Updating old `workflow` rows to `workflow_processor` is still recommended for clarity.

---

## Problem Description

If you're seeing errors like this in your Task Execution Logs:

```
Task handler not found: /var/www/.../backend/cron/tasks/reminder.php
Task handler not found: /var/www/.../backend/cron/tasks/workflow.php
```

This means your `scheduled_tasks` database table contains incorrect `task_type` values that don't match the actual task handler filenames.

## Root Cause

The `scheduled_tasks` table has task_type values that don't correspond to the actual PHP files in `/backend/cron/tasks/`.

**Common Incorrect Values:**
- `reminder` → Should be `booking_reminder`
- `workflow` → Should be `workflow_processor`

**Available Task Handlers:**
- `booking_reminder.php` - Send booking reminders
- `contract_reminder.php` - Send contract reminders
- `email.php` - Generic email handler (legacy, delegates to scheduled_email_sender)
- `email_receiver.php` - Receive emails via IMAP
- `form_reminder.php` - Send form reminders
- `invoice_reminder.php` - Send invoice reminders
- `quote_reminder.php` - Send quote reminders
- `scheduled_email_sender.php` - Send scheduled emails
- `workflow_processor.php` - Process workflow steps

## Solution

### Option 1: Automated Fix (Recommended)

Run the automated fix script to correct the task_type values:

```bash
cd /path/to/backend/cron
php fix_task_types.php
```

This script will:
1. Display all current scheduled tasks
2. Identify tasks with invalid task_type values
3. Automatically correct known issues
4. Report what was fixed and what needs manual intervention

### Option 2: Manual Database Fix

If you prefer to fix the database manually, use these SQL commands:

```sql
-- Fix 'reminder' → 'booking_reminder'
UPDATE scheduled_tasks 
SET task_type = 'booking_reminder', updated_at = datetime('now')
WHERE task_type = 'reminder';

-- Fix 'workflow' → 'workflow_processor'
UPDATE scheduled_tasks 
SET task_type = 'workflow_processor', updated_at = datetime('now')
WHERE task_type = 'workflow';

-- Note: 'email' is now a valid task_type (generic handler that delegates to scheduled_email_sender)
-- However, for better clarity, you may optionally update to specific types:

-- (Optional) Fix generic 'email' → 'scheduled_email_sender' (for "Send Scheduled Emails" task)
UPDATE scheduled_tasks 
SET task_type = 'scheduled_email_sender', updated_at = datetime('now')
WHERE task_type = 'email' AND task_name = 'Send Scheduled Emails';

-- (Optional) Fix generic 'email' → 'email_receiver' (for "Receive Emails" task)
UPDATE scheduled_tasks 
SET task_type = 'email_receiver', updated_at = datetime('now')
WHERE task_type = 'email' AND task_name LIKE '%Receive%';
```

### Option 3: Reinitialize Tasks

If you want to start fresh with the default tasks:

```bash
cd /path/to/backend/cron

# 1. First, backup your current tasks (optional)
mysql -u your_user -p your_database -e "SELECT * FROM scheduled_tasks" > tasks_backup.txt

# 2. Clear the current tasks
mysql -u your_user -p your_database -e "DELETE FROM scheduled_tasks"

# 3. Reinitialize with defaults
php init_tasks.php
```

**Note:** This will remove any custom tasks you've created. Only use this if you haven't customized your scheduled tasks.

## Verification

After applying the fix, verify that tasks are working:

1. Check the scheduled tasks:
   ```bash
   php fix_task_types.php
   ```
   You should see "✓ All task types are valid! No fixes needed."

2. Run the cron job manually:
   ```bash
   php cron.php
   ```
   You should see tasks executing successfully.

3. Check the Task Execution Logs in the admin panel. You should no longer see "task handler not found" errors.

## Prevention

To prevent this issue in the future:

1. **Always use `init_tasks.php`** to create default tasks instead of manually inserting into the database
2. When creating custom tasks, ensure the `task_type` matches an actual PHP filename in `/backend/cron/tasks/`
3. Use the admin panel to manage tasks when possible

## Creating Custom Tasks

If you need to create a custom scheduled task:

1. Create your task handler in `/backend/cron/tasks/my_custom_task.php`
2. Insert into database with matching task_type:
   ```sql
   INSERT INTO scheduled_tasks (
       task_name, task_type, schedule_type, schedule_value, is_active, next_run
   ) VALUES (
       'My Custom Task',
       'my_custom_task',  -- Must match the filename without .php
       'daily',
       '10:00',
       1,
       datetime('now')
   );
   ```

## Need Help?

If you continue to experience issues after applying these fixes, check:
- File permissions on `/backend/cron/tasks/` directory and files
- PHP error logs for additional details
- Database connection settings in `/backend/includes/config.php`
