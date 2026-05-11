# Google Calendar & iCalendar Integration

Complete guide for setting up calendar integration for the booking system.

## Features

✅ **Email Confirmations** - Automatic booking confirmation emails with calendar links  
✅ **Google Calendar Integration (OAuth)** - Per-user OAuth 2.0 calendar sync (recommended)  
✅ **Google Calendar Integration (Service Account)** - Legacy shared-calendar sync  
✅ **iCalendar Export** - Download .ics files for any calendar app  
✅ **Calendar Links** - One-click "Add to Calendar" buttons  

## Quick Start

### Email Confirmations (Works Immediately)

The system automatically sends confirmation emails with calendar links when a booking is created. No additional setup required!

**Email includes:**
- Booking details
- "Add to Google Calendar" button (opens in browser)
- "Download iCal" button (downloads .ics file)
- Works with: Google Calendar, Apple Calendar, Outlook, Yahoo Calendar, etc.

### Calendar Links in Custom Email Templates

When using a custom booking confirmation or reminder template (configured in **Admin → Email Templates**), you can include calendar invite links using these template variables:

| Variable | Description |
|---|---|
| `{{google_calendar_link}}` | URL that opens Google Calendar to add the appointment |
| `{{ical_link}}` | URL to download an iCal (.ics) file (Apple Calendar, Outlook, etc.) |

**Example HTML to add to your custom template:**

```html
<p>Add this appointment to your calendar:</p>
<p>
  <a href="{{google_calendar_link}}">📅 Add to Google Calendar</a><br>
  <a href="{{ical_link}}">📲 Download iCal File</a>
</p>
```

These variables are available in:
- Booking confirmation templates (`booking_confirmation` task type)
- Booking reminder templates (`booking_reminder` task type)

### iCalendar (.ics) File Download

**Already configured!** When users book an appointment, they receive:
1. Email with download link
2. Direct download URL: `/backend/public/download_ical.php?booking_id=X`

The .ics file works with:
- ✅ Google Calendar
- ✅ Apple Calendar (Mac/iPhone/iPad)
- ✅ Microsoft Outlook
- ✅ Yahoo Calendar
- ✅ Any calendar app supporting iCalendar format

---

## Google Calendar OAuth Integration (Recommended)

The OAuth 2.0 method lets each admin user connect their **personal or business Google Calendar**
directly from the admin panel.  No file uploads or calendar sharing required.

### How it works

1. Admin enters OAuth credentials in **Settings → Calendar**.
2. Admin clicks **Connect Google Calendar** — Google's consent page opens.
3. After granting access, tokens are stored securely in the database.
4. Admin selects which calendar to sync bookings to.
5. All new bookings are automatically added to that calendar.
6. Admin can disconnect at any time (tokens are revoked and deleted).

### Step 1: Create Google Cloud Project

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project (e.g., "BDTA Booking System")
3. Enable **Google Calendar API**:
   - Navigate to "APIs & Services" → "Library"
   - Search for "Google Calendar API"
   - Click "Enable"

### Step 2: Create OAuth 2.0 Credentials

1. Go to "APIs & Services" → "Credentials"
2. Click "Create Credentials" → **"OAuth client ID"**
3. Choose **Application type: Web application**
4. Fill in details:
   - Name: `BDTA Calendar OAuth`
   - Authorised redirect URIs: `https://yourdomain.com/backend/public/google_oauth_callback.php`
5. Click "Create" and note the **Client ID** and **Client Secret**

> ⚠️ If prompted, configure the **OAuth consent screen** first:
> - User type: **External** (or Internal for Google Workspace)
> - Scopes: `../auth/calendar` and `openid`, `email`
> - Add your domain to authorised domains
> - Publishing status: use **Testing** only during setup. For live use, switch to **Production** (or use **Internal** for Google Workspace). External apps left in **Testing** issue Calendar refresh tokens that expire after **7 days**.

### Step 3: Configure in Admin Panel

1. Go to **Admin Panel → Settings → Calendar**
2. Fill in:
   - **OAuth Client ID** – paste the Client ID from step 2
   - **OAuth Client Secret** – paste the Client Secret from step 2
   - **OAuth Redirect URI** – must match exactly what you entered in Google Cloud Console
3. Click **Save Settings**

### Step 4: Connect Your Google Calendar

1. Still in **Settings → Calendar**, scroll to the **Google Calendar – OAuth Connection** panel
2. Click **Connect Google Calendar**
3. Sign in with your Google account and grant Calendar access
4. You are redirected back – a success message confirms the connection
5. Use the **Choose Booking Calendar** dropdown to select which calendar receives new bookings

> ⚠️ If you connected while the Google OAuth app was still in **Testing**, publish it to **Production** first and then **reconnect** Google Calendar so Google issues a long-lived refresh token.

### Step 5: Test

Create a test booking and verify the event appears in your Google Calendar.

### Disconnecting

Click **Disconnect Google Calendar** in **Settings → Calendar**.  
The stored tokens are immediately revoked with Google and removed from the database.

---

## Google Calendar Integration (Service Account – Legacy)

Automatically sync bookings to a **shared** Google Calendar using a service account.
This method is kept for backwards compatibility.

### Step 1: Create Google Cloud Project

1. Go to "APIs & Services" → "Credentials"
2. Click "Create Credentials" → "Service Account"
3. Fill in details:
   - Service account name: `bdta-calendar-sync`
   - Service account ID: (auto-generated)
   - Description: "Booking system calendar sync"
4. Click "Create and Continue"
5. Grant role: "Editor" (or create custom role with Calendar access)
6. Click "Done"

### Step 3: Download Credentials

1. Click on the service account you just created
2. Go to "Keys" tab
3. Click "Add Key" → "Create new key"
4. Choose JSON format
5. Download the file
6. Rename it to `google-calendar-credentials.json`
7. Place it in: `/backend/includes/google-calendar-credentials.json`

### Step 4: Share Your Calendar

1. Open [Google Calendar](https://calendar.google.com/)
2. Find your calendar in the left sidebar
3. Click the three dots → "Settings and sharing"
4. Scroll to "Share with specific people"
5. Click "Add people"
6. Enter the service account email (found in the JSON file or console)
   - Format: `bdta-calendar-sync@project-name.iam.gserviceaccount.com`
7. Set permission: "Make changes to events"
8. Click "Send"

### Step 5: Install Google API Client

```bash
cd backend
composer require google/apiclient
```

If you don't have Composer:
```bash
# Install Composer
curl -sS https://getcomposer.org/installer | php
php composer.phar require google/apiclient
```

### Step 6: Update Configuration

Edit `/backend/includes/google_calendar.php`:

```php
// Change this to your calendar ID (found in Google Calendar settings)
private $calendar_id = 'your-calendar-id@group.calendar.google.com';

// Or use 'primary' for your main calendar
private $calendar_id = 'primary';
```

### Step 7: Test Integration

Create a test booking and check:
1. Email confirmation received ✅
2. Google Calendar link works ✅
3. Event appears in your Google Calendar ✅
4. iCal download works ✅

## Configuration Options

### Email Settings

Edit `/backend/includes/email_service.php`:

```php
private $from_email = 'bookings@brooksdogtraining.com'; // Your email
private $from_name = 'Brook\'s Dog Training Academy';   // Your business name
private $base_url = 'https://yourdomain.com';           // Your website URL
```

### Calendar Settings

Edit `/backend/includes/icalendar.php` if needed:

```php
// Timezone for appointments (line in generate() method)
$location = self::escapeString('Your Location Here');
```

## API Response Format

When a booking is created, the API returns:

```json
{
  "success": true,
  "message": "Booking created successfully!",
  "booking_id": 123,
  "calendar_links": {
    "google_calendar": "https://calendar.google.com/calendar/render?action=TEMPLATE&...",
    "ical_download": "https://yourdomain.com/backend/public/download_ical.php?booking_id=123"
  },
  "email_sent": true,
  "google_calendar_synced": false
}
```

## Frontend Integration Example

Add calendar buttons to your booking confirmation:

```javascript
fetch('/backend/public/api_bookings.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(bookingData)
})
.then(response => response.json())
.then(data => {
    if (data.success) {
        // Show success message
        alert('Booking confirmed!');
        
        // Show calendar buttons
        const googleBtn = `<a href="${data.calendar_links.google_calendar}" target="_blank" class="btn btn-primary">
            📅 Add to Google Calendar
        </a>`;
        
        const icalBtn = `<a href="${data.calendar_links.ical_download}" class="btn btn-success">
            📲 Download iCal File
        </a>`;
        
        document.getElementById('calendar-buttons').innerHTML = googleBtn + icalBtn;
    }
});
```

## Email Service Setup (Production)

For production, replace PHP's `mail()` function with a professional email service:

### Option 1: SendGrid (Recommended)
```bash
composer require sendgrid/sendgrid
```

### Option 2: Mailgun
```bash
composer require mailgun/mailgun-php
```

### Option 3: AWS SES
```bash
composer require aws/aws-sdk-php
```

Update `/backend/includes/email_service.php` to use your chosen service.

## Troubleshooting

### Email not sending
- Check PHP `mail()` is configured on your server
- For production, use SendGrid/Mailgun/AWS SES
- Check spam folder
- Verify email address is valid

### Google Calendar not syncing
- Verify credentials file exists and is valid JSON
- Check service account has calendar access
- Install Google API client: `composer require google/apiclient`
- Check error logs: `/backend/logs/`

### Google Calendar reconnect needed every 7 days
- If your OAuth app audience is **External** and the publishing status is **Testing**, Google expires Calendar refresh tokens after **7 days**.
- In Google Auth Platform / OAuth consent screen settings, switch the publishing status to **Production** (or use **Internal** for Google Workspace).
- After publishing, disconnect and **reconnect** Google Calendar so a new long-lived refresh token is issued.

### iCal download not working
- Check file permissions on `/backend/public/`
- Verify booking ID is correct
- Check PHP error logs

### Calendar link opens but doesn't add event
- Verify date/time format is correct
- Check timezone settings
- Test with different calendar apps

## Security Notes

⚠️ **Important:**
- Never commit `google-calendar-credentials.json` to git (already in .gitignore)
- Keep service account credentials secure
- **OAuth tokens are stored in the database** – keep your database secure and backed up
- **OAuth Client Secret** is stored as a masked/secret setting in the admin panel
- Users can revoke their OAuth tokens at any time via the admin panel
- Always use HTTPS in production so tokens are never transmitted in plaintext
- Use environment variables for sensitive data in production

## Testing

Test the complete flow:

```bash
# 1. Start PHP server
cd backend
php -S localhost:8000

# 2. Create test booking
curl -X POST http://localhost:8000/public/api_bookings.php \
  -H "Content-Type: application/json" \
  -d '{
    "client_name": "Test User",
    "client_email": "test@example.com",
    "client_phone": "555-1234",
    "service_type": "Pet Manners at Home",
    "appointment_date": "2024-02-15",
    "appointment_time": "10:00",
    "notes": "First session"
  }'

# 3. Check response includes calendar links
# 4. Try downloading iCal file
# 5. Check email inbox
# 6. Verify Google Calendar (if configured)
```

## Support

For issues or questions:
- Check `/backend/logs/` for error messages
- Review this documentation
- Test with curl/Postman before frontend integration

## License

© 2024 Brook's Dog Training Academy
