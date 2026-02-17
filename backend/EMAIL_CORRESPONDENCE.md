# Client Email Correspondence Management

## Overview

The Client Email Correspondence Management system enables per-client email tracking, composition, scheduling, and history management. This feature integrates with the existing email template system and provides a comprehensive communication log for each client.

## Features

### 1. Email History Tab
- Each client profile includes a dedicated "Email" tab
- Displays chronological list of all sent, scheduled, and received emails
- Shows email status with color-coded badges (pending, scheduled, sent, delivered, failed)
- Tracks template usage for each email

### 2. Email Composition
- **Compose Email Button**: Opens modal to create new email
- **Template Selection**: Choose from existing email templates or write custom messages
- **Variable Substitution**: Templates automatically populate with client data
  - `{{client_name}}` - Client's name
  - `{{client_email}}` - Client's email address
  - `{{client_phone}}` - Client's phone number
  - `{{client_address}}` - Client's address
  - `{{dog_name}}` - Dog's name (if registered)
  - `{{dog_breed}}` - Dog's breed
  - `{{today_date}}` - Current date
- **HTML Support**: Email body supports HTML formatting
- **Live Preview**: See how template looks with client data before sending

### 3. Email Scheduling
- **Send Immediately**: Emails sent right away upon clicking "Send Email"
- **Schedule for Later**: Check "Schedule for later" to choose date and time
- **Automated Sending**: CRON task automatically sends scheduled emails at specified time
- **Status Tracking**: Scheduled emails show in list with "Scheduled" badge

### 4. Email Status Tracking
- **Pending**: Email queued but not yet sent
- **Scheduled**: Email set to send at future date/time
- **Sent**: Email successfully sent to client
- **Received**: Email received from client (via IMAP)
- **Delivered**: Email confirmed delivered (future enhancement)
- **Failed**: Email send failed with error message logged

### 5. Email Receiving (IMAP)
- **Automatic Sync**: CRON task fetches incoming emails every 15 minutes
- **Client Matching**: Incoming emails automatically matched to clients by email address
- **Unified View**: Received emails displayed alongside sent emails
- **IMAP Configuration**: Configure in **Settings → Email** (IMAP section)

## Usage Guide

### Viewing Client Email History

1. Navigate to **Clients** → **Client List**
2. Click **View Profile** for desired client
3. Click the **Email** tab
4. View chronological list of all emails

### Composing an Email

1. From client's Email tab, click **Compose Email**
2. **Optional**: Select a template from dropdown
   - Template automatically fills subject and body
   - Variables replaced with client data
3. Enter or edit **Subject**
4. Enter or edit **Message** (HTML supported)
5. Choose send option:
   - **Send Now**: Click "Send Email"
   - **Schedule**: Check "Schedule for later" → Select date/time → Click "Schedule Email"

### Creating Email Templates

1. Navigate to **Email Templates** in admin menu
2. Click **Create New Template**
3. Fill in template details:
   - Name (e.g., "Welcome Email", "Appointment Reminder")
   - Template Type
   - Subject (can include variables)
   - Body HTML (can include variables)
   - Body Text (plain text version)
4. Click **Save Template**
5. Template now available in compose modal dropdown

### Configuring IMAP Email Receiving

**Important:** If you don't see IMAP settings in Settings → Email, run the migration script first:
```bash
php /path/to/backend/add_imap_settings.php
```

1. Navigate to **Settings** → **Email** in admin panel
2. **Scroll down** to the **IMAP Settings** section (below SMTP, SendGrid, Mailgun settings)
3. Configure IMAP connection:
   - **Enable IMAP**: Check to enable email receiving
   - **IMAP Host**: Your mail server (e.g., `imap.gmail.com`)
   - **IMAP Port**: Usually `993` for SSL or `143` for TLS
   - **IMAP Encryption**: Choose `ssl`, `tls`, or `none`
   - **IMAP Username**: Your email address
   - **IMAP Password**: Your email password or app-specific password
   - **IMAP Folder**: Mailbox folder (default: `INBOX`)
   - **Sync Days**: How many days of emails to fetch (default: `30`)
4. Click **Save Settings**
5. CRON task will automatically fetch emails every 15 minutes

**Gmail Users:** You may need to:
- Enable "Less secure app access" OR
- Use an [App Password](https://support.google.com/accounts/answer/185833)
- Enable IMAP in Gmail settings

**Office 365/Outlook Users:**
- Host: `outlook.office365.com`
- Port: `993`
- Encryption: `ssl`

### Viewing Email Details

1. From Email tab, click on any email in the list
2. Modal shows:
   - Full subject and message
   - From/To addresses
   - Status and timestamps
   - Error messages (if failed)
   - Template used (if applicable)

## Database Schema

### `client_emails` Table

```sql
CREATE TABLE client_emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    direction TEXT NOT NULL,              -- 'outgoing' or 'incoming'
    status TEXT NOT NULL,                 -- 'pending', 'scheduled', 'sent', 'failed', 'delivered'
    from_email TEXT NOT NULL,
    to_email TEXT NOT NULL,
    subject TEXT NOT NULL,
    body_html TEXT,                       -- HTML version of email
    body_text TEXT,                       -- Plain text version
    template_id INTEGER,                  -- Reference to email template used
    scheduled_at TIMESTAMP,               -- When email is scheduled to send
    sent_at TIMESTAMP,                    -- When email was actually sent
    delivered_at TIMESTAMP,               -- When email was delivered
    failed_at TIMESTAMP,                  -- When email send failed
    error_message TEXT,                   -- Error details if failed
    created_by INTEGER,                   -- Admin user who created email
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL
)
```

## API Endpoints

### Client Emails API (`client/client_emails_api.php`)

#### GET - List Emails
```
GET /client/client_emails_api.php?client_id=1
```
Returns all emails for a specific client.

**Response:**
```json
{
  "success": true,
  "emails": [
    {
      "id": 1,
      "client_id": 1,
      "direction": "outgoing",
      "status": "sent",
      "from_email": "bookings@example.com",
      "to_email": "client@example.com",
      "subject": "Welcome!",
      "body_html": "<h1>Welcome!</h1>",
      "body_text": "Welcome!",
      "template_id": 1,
      "template_name": "Welcome Email",
      "scheduled_at": null,
      "sent_at": "2026-02-16 10:30:00",
      "created_at": "2026-02-16 10:29:55",
      "created_by_username": "admin"
    }
  ]
}
```

#### POST - Send/Schedule Email
```
POST /client/client_emails_api.php
Content-Type: application/json

{
  "client_id": 1,
  "subject": "Welcome to BDTA",
  "body_html": "<h1>Welcome!</h1><p>We're excited to work with you!</p>",
  "body_text": "Welcome! We're excited to work with you!",
  "template_id": 1,  // Optional
  "scheduled_at": "2026-02-17 09:00:00"  // Optional - omit to send immediately
}
```

**Response (immediate send):**
```json
{
  "success": true,
  "message": "Email sent successfully",
  "email_id": 1
}
```

**Response (scheduled):**
```json
{
  "success": true,
  "message": "Email scheduled successfully",
  "email_id": 1
}
```

#### DELETE - Remove Email
```
DELETE /client/client_emails_api.php?id=1
```
Only allows deletion of draft, scheduled, or failed emails.

### Email Templates API (`client/email_templates_api.php`)

#### GET - List Templates
```
GET /client/email_templates_api.php?action=list
```

#### GET - Get Template
```
GET /client/email_templates_api.php?action=get&id=1
```

#### GET - Preview Template with Client Data
```
GET /client/email_templates_api.php?action=preview&id=1&client_id=1
```

Returns template with all variables replaced with actual client data.

## CRON Task Setup

The scheduled email sender task automatically processes scheduled emails.

### Task Configuration

Task is registered in `backend/cron/init_tasks.php`:

```php
[
    'task_name' => 'Send Scheduled Emails',
    'task_type' => 'scheduled_email_sender',
    'schedule_type' => 'interval',
    'schedule_value' => '15',  // Every 15 minutes
    'is_active' => 1
]
```

### How It Works

1. **CRON runs every 15 minutes** (configured in system crontab)
2. **Task checks** for emails where `status = 'scheduled'` AND `scheduled_at <= NOW()`
3. **For each due email:**
   - Sends email via EmailService
   - Updates status to `'sent'` with `sent_at` timestamp on success
   - Updates status to `'failed'` with error message on failure
4. **Logs** all activity to `task_logs` table

### Manual Execution

To manually run the scheduled email sender:

```bash
php /path/to/backend/cron/cron.php
```

## JavaScript API

### Load Emails for Client
```javascript
async function loadEmails() {
    const response = await fetch(`client_emails_api.php?client_id=${clientId}`);
    const data = await response.json();
    if (data.success) {
        displayEmails(data.emails);
    }
}
```

### Send Email
```javascript
const data = {
    client_id: 1,
    subject: "Test Email",
    body_html: "<p>Test message</p>",
    scheduled_at: "2026-02-17 09:00:00"  // Optional
};

const response = await fetch('client_emails_api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
});

const result = await response.json();
if (result.success) {
    console.log(result.message);
}
```

### Load Template with Preview
```javascript
const response = await fetch(
    `email_templates_api.php?action=preview&id=${templateId}&client_id=${clientId}`
);
const data = await response.json();
if (data.success) {
    document.getElementById('emailSubject').value = data.preview.subject;
    document.getElementById('emailBody').value = data.preview.body_html;
}
```

## Security Considerations

### XSS Protection
- Email HTML content is sanitized before display
- User input is properly escaped in JavaScript
- Consider implementing DOMPurify for enhanced HTML sanitization in production

### SQL Injection Protection
- All database queries use prepared statements with parameter binding
- No raw SQL concatenation with user input

### Access Control
- All API endpoints require authentication (`requireLogin()`)
- Emails are restricted to authorized client data
- Created_by field tracks which admin user sent email

### Email Headers
- SPF, DKIM, and DMARC should be configured on email server
- See EMAIL_CONFIGURATION.md for details

## Troubleshooting

### Emails Not Sending

1. **Check email configuration:**
   - Navigate to **Settings** → **Email**
   - Verify SMTP settings are correct
   - Test connection using test email feature

2. **Check email status:**
   - Look at email in client's Email tab
   - If status is "failed", view error message
   - Common issues: SMTP authentication, firewall blocking

3. **Check CRON is running:**
   ```bash
   tail -f /path/to/logs/cron.log
   ```

### Scheduled Emails Not Sending

1. **Verify CRON is running:**
   ```bash
   crontab -l  # Check if cron job is registered
   ```

2. **Check task logs:**
   - Navigate to **Scheduled Tasks** in admin panel
   - View execution logs for "Send Scheduled Emails" task

3. **Manual test:**
   ```bash
   php /path/to/backend/cron/cron.php
   ```

### Template Variables Not Replacing

1. **Check variable syntax:** Must be exactly `{{variable_name}}`
2. **Check client has data:** Variables replace with empty string if client data missing
3. **Supported variables:** See template variable documentation above

### Incoming Emails Not Appearing

1. **Check IMAP is enabled:**
   - Navigate to **Settings** → **Email**
   - Verify "Enable IMAP Email Receiving" is checked
   - Confirm IMAP credentials are correct

2. **Test IMAP connection:**
   ```bash
   php /path/to/backend/cron/cron.php
   ```
   - Check task logs for "Receive Emails (IMAP)" task

3. **Common issues:**
   - **Gmail:** Requires app-specific password or "Less secure app access"
   - **Firewall:** IMAP port (993/143) must be open
   - **Client email mismatch:** Incoming email sender must match a client email address

4. **Check email is matched to client:**
   - Incoming emails only appear if sender email matches a client in the database
   - Verify client email address is correct

## Future Enhancements

- **Delivery tracking:** Webhook integration for email delivery status
- **Attachments:** Support for file attachments in both sent and received emails
- **Rich text editor:** WYSIWYG editor for email composition
- **Email threading:** Group related emails as conversations
- **Email analytics:** Track open rates and click-through rates
- **Advanced sanitization:** Integrate DOMPurify for production-grade XSS protection
- **Reply functionality:** Quick reply to received emails from the UI

## Related Documentation

- [EMAIL_CONFIGURATION.md](EMAIL_CONFIGURATION.md) - Email server setup
- [CRON_SETUP.md](CRON_SETUP.md) - CRON job configuration
- [WORKFLOWS_GUIDE.md](WORKFLOWS_GUIDE.md) - Automated email workflows
