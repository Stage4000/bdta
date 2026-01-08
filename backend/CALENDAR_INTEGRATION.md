# Google Calendar & iCalendar Integration

Complete guide for setting up calendar integration for the booking system.

## Features

✅ **Email Confirmations** - Automatic booking confirmation emails with calendar links  
✅ **Google Calendar Integration** - Optional sync to your Google Calendar  
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

## Google Calendar Integration (Optional)

Automatically sync bookings to your Google Calendar in real-time.

### Step 1: Create Google Cloud Project

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project: "BDTA Booking System"
3. Enable **Google Calendar API**:
   - Navigate to "APIs & Services" → "Library"
   - Search for "Google Calendar API"
   - Click "Enable"

### Step 2: Create Service Account

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
- Use environment variables for sensitive data in production
- Enable HTTPS for all calendar links in production

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
