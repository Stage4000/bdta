# Automated Workflows Guide

This guide explains how to use the automated workflows system in Brook's Dog Training Academy CRM.

## Overview

Automated workflows allow you to create sequences of emails that are automatically sent to clients based on time delays or specific dates. Workflows can include attachments like contracts, forms, quotes, invoices, and appointment booking links.

## Key Features

- **Email Sequences**: Create multi-step email campaigns
- **Time-Based Scheduling**: Send emails based on delays (e.g., 3 days after enrollment)
- **Date-Based Scheduling**: Send emails on specific dates
- **Attachments**: Include contracts, forms, quotes, invoices, or appointment links
- **Auto-Enrollment**: Automatically enroll clients when they book appointments or complete forms
- **Manual Enrollment**: Add clients to workflows manually
- **Progress Tracking**: Monitor enrollment status and email delivery

## Use Cases

- **New Client Onboarding**: Welcome series with forms and contracts
- **Pre-Appointment Preparation**: Send reminders and preparation materials
- **Post-Service Follow-ups**: Request feedback and reviews
- **Educational Campaigns**: Share training tips over time
- **Re-engagement Campaigns**: Win back inactive clients
- **Package/Program Sequences**: Multi-week training programs

## Creating a Workflow

### 1. Create the Workflow

1. Navigate to **Admin Panel** → **Workflows**
2. Click **New Workflow**
3. Enter:
   - **Name**: Descriptive name (e.g., "New Client Welcome Series")
   - **Description**: Purpose of the workflow
   - **Active**: Check to enable the workflow
4. Click **Create Workflow**

### 2. Add Workflow Steps

After creating a workflow, add email steps:

1. Click **Add Step** on the workflow steps page
2. Configure the step:
   - **Step Name**: Internal name for the step
   - **Email Subject**: Subject line (supports placeholders)
   - **Email Body (HTML)**: HTML email content
   - **Email Body (Plain Text)**: Optional plain text version

#### Scheduling Options

**Immediate:**
- Email sent as soon as client is enrolled

**After Enrollment:**
- Email sent X time after enrollment
- Example: "3 days", "1 week", "2 hours"

**After Previous Step:**
- Email sent X time after previous step completes
- Example: "2 days" after the previous email

**Specific Date:**
- Email sent on a specific date and time
- Example: February 10, 2026 at 10:00 AM

#### Available Placeholders

- `{client_name}` - Client's full name
- `{workflow_name}` - Name of the workflow
- `{step_name}` - Name of the current step

#### Attachments & Links

You can attach:
- **Contract Template**: Links to a specific contract
- **Form Template**: Links to a form to complete
- **Quote**: Links to an existing quote (must be created separately)
- **Invoice**: Links to an existing invoice (must be created separately)
- **Appointment Link**: Includes booking link for specified appointment type

### 3. Set Up Auto-Enrollment Triggers (Optional)

Automatically enroll clients when they:
- Book a specific appointment type
- Complete a specific form

*Note: Trigger configuration UI is accessed through workflow settings*

### 4. Enroll Clients

Enroll clients in two ways:

**Manual Enrollment:**
1. Go to workflow page
2. Click **Enroll Clients**
3. Select clients to enroll
4. Click **Enroll Selected Clients**

**Automatic Enrollment:**
- Clients are automatically enrolled when triggers fire
- Example: Booking a "Consultation" appointment triggers "New Client Onboarding"

## Managing Enrollments

### View Enrollments

1. Navigate to **Admin Panel** → **Workflows**
2. Click on a workflow
3. Click **View Enrollments**

You can see:
- Active enrollments
- Completed enrollments
- Cancelled enrollments
- Progress for each enrollment
- Step completion status

### Cancel an Enrollment

To stop a workflow for a specific client:
1. Go to workflow enrollments
2. Find the client
3. Click **Cancel**

This stops all pending emails for that enrollment.

## Examples

### Example 1: New Client Welcome Series

**Steps:**
1. **Immediate** - "Welcome Email"
   - Introduces the business
   - Sets expectations
2. **1 day after enrollment** - "Complete Your Forms"
   - Attaches client intake form
   - Attaches liability waiver
3. **3 days after previous** - "Book Your First Session"
   - Includes appointment booking link
4. **1 week after previous** - "Training Tips"
   - Educational content

**Trigger**: Auto-enroll when "Consultation" appointment is booked

### Example 2: Pre-Appointment Preparation

**Steps:**
1. **7 days before appointment** - "Getting Ready"
   - What to bring
   - What to expect
2. **3 days before appointment** - "Preparation Checklist"
   - Final reminders
   - Contact information
3. **1 day before appointment** - "See You Tomorrow"
   - Confirmation with directions
   - Calendar reminder

**Trigger**: Auto-enroll when any appointment is booked

### Example 3: Post-Service Follow-up

**Steps:**
1. **1 day after enrollment** - "Thank You"
   - Appreciation email
   - Request feedback
2. **1 week after previous** - "How's It Going?"
   - Check-in on progress
   - Offer additional support
3. **1 month after previous** - "Continue Your Training"
   - Offer next level services
   - Include quote for package

**Trigger**: Manually enroll after completing a service

## Technical Details

### Database Tables

- `workflows` - Workflow definitions
- `workflow_steps` - Individual email steps
- `workflow_enrollments` - Client enrollments
- `workflow_step_executions` - Scheduled and sent emails
- `workflow_triggers` - Auto-enrollment configuration

### CRON Processing

The `workflow_processor` task runs every hour by default and:
1. Finds pending step executions that are due
2. Sends emails with appropriate attachments
3. Marks executions as complete
4. Checks if enrollment is complete

### Programmatic Enrollment

You can enroll clients programmatically:

```php
require_once 'backend/includes/workflow_helper.php';

$workflow_helper = new WorkflowHelper($conn);
$result = $workflow_helper->enrollClient($workflow_id, $client_id, $admin_id);

if ($result['success']) {
    echo "Client enrolled successfully";
}
```

### Auto-Enrollment Triggers

Trigger workflows automatically:

```php
// After booking appointment
$workflow_helper->checkAppointmentTriggers($booking_id);

// After form submission
$workflow_helper->checkFormTriggers($form_submission_id);
```

## Best Practices

1. **Test First**: Create a test workflow and enroll yourself to verify emails
2. **Clear Subject Lines**: Make it obvious what the email is about
3. **Provide Value**: Each email should offer something useful
4. **Don't Over-Email**: Space out emails appropriately
5. **Monitor Progress**: Check enrollment status regularly
6. **Update Content**: Keep email content fresh and relevant
7. **Use Placeholders**: Personalize emails with client names
8. **Include Unsubscribe**: Consider adding opt-out language for marketing emails

## Troubleshooting

### Emails Not Sending

1. Check that workflow is **Active**
2. Verify CRON job is running (check Scheduled Tasks logs)
3. Confirm enrollment status is **Active**
4. Check email service configuration in Settings
5. Review task logs for error messages

### Wrong Send Time

1. Verify delay type and delay value
2. Check step order
3. Ensure enrollment timestamp is correct
4. Review workflow_step_executions table

### Client Not Auto-Enrolled

1. Verify trigger is configured and active
2. Check that appointment type or form matches trigger
3. Ensure workflow is active
4. Review error logs in task_logs table

## Support

For additional help:
- Review the CRON Job Setup Guide in `/backend/CRON_SETUP.md`
- Check scheduled task logs in Admin Panel
- Review workflow enrollment status
- Contact system administrator for technical issues
