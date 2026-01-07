# Brook's Dog Training Academy - PHP Backend

Complete backend system with **blog, booking calendar, client management, time tracking, expense tracking, invoicing with Stripe, contract management, and admin panel** using **PHP and SQLite**.

## Features

### ✅ Client Management (CRM)
- Full client profiles with contact information
- Dog information tracking (name, breed)
- Notes and history
- Linked to bookings, time entries, invoices, and contracts

### ✅ Time Tracking
- Record billable and non-billable hours
- Automatic duration calculation
- Hourly rate configuration
- Service type tracking
- Link to specific bookings
- Ready for invoicing

### ✅ Expense Tracking
- Record business expenses by category
- Mark expenses as billable/non-billable
- Associate with clients
- Include in invoices
- Receipt file upload support

### ✅ Invoicing with Stripe Integration
- Create professional invoices
- Auto-generate invoice numbers
- Pull in unbilled time entries and expenses
- Tax calculation
- Multiple payment methods:
  - **Stripe online payment** (credit/debit cards)
  - **Manual payment** (cash, check, in-person)
- Payment status tracking (draft, sent, paid, overdue)
- Professional invoice view/print

### ✅ Contract Management
- Create digital contracts
- Electronic signature capture
- Contract status tracking (draft, sent, signed, expired)
- IP address and timestamp logging
- Version control

### ✅ Blog System
- Create, edit, and delete blog posts
- Publish/draft status
- SEO-friendly slugs
- Public blog listing and individual post pages

### ✅ Booking Calendar
- Online booking system with availability checking
- Service type selection
- Date and time slot selection  
- Client information collection
- Booking status management
- **Email confirmations with calendar export**
- **Google Calendar integration (optional)**
- **iCalendar (.ics) file download**
- **One-click "Add to Calendar" buttons**

### ✅ Admin Panel
- Secure login system with password hashing
- Comprehensive dashboard with statistics
- Client management
- Time tracking interface
- Expense management
- Invoice generation and management
- Contract creation and signing
- Blog post management
- Booking management
- Status updates across all modules

### ✅ Database (SQLite)
- No external database required
- Automatic initialization
- Tables for:
  - admin_users
  - clients
  - time_entries
  - expenses
  - invoices & invoice_items
  - contracts
  - blog_posts
  - bookings

## Requirements

- PHP 7.4 or higher
- SQLite3 PHP extension (usually included)
- Web server (Apache, Nginx, or PHP built-in server)
- (Optional) Composer for Google Calendar & Stripe integration

## Installation

### 1. Check PHP Version

```bash
php --version
```

### 2. Start PHP Built-in Server

```bash
cd backend
php -S localhost:8000
```

The application will be available at:
- **Website:** http://localhost:8000/../../index.html
- **Blog:** http://localhost:8000/public/blog.php
- **Admin Panel:** http://localhost:8000/admin/login.php

### 3. Default Admin Credentials

**Username:** admin  
**Password:** admin123

⚠️ **IMPORTANT:** Change these credentials immediately in production!

## Calendar Integration

### Email Confirmations (Works Immediately!)

When a booking is created, the system automatically:
1. ✅ Sends confirmation email to the client
2. ✅ Includes "Add to Google Calendar" button
3. ✅ Includes "Download iCal" button for any calendar app
4. ✅ Provides booking details and location

**No additional setup required!**

### iCalendar Export (.ics files)

Download URL: `/backend/public/download_ical.php?booking_id=X`

Works with:
- Google Calendar
- Apple Calendar (Mac/iPhone/iPad)
- Microsoft Outlook
- Yahoo Calendar
- Any app supporting iCalendar format

### Google Calendar Sync (Optional)

Automatically sync bookings to your Google Calendar.

**Quick Setup:**
1. Create Google Cloud project
2. Enable Google Calendar API
3. Create service account
4. Download credentials JSON
5. Share your calendar with service account
6. Install: `composer require google/apiclient`

**Detailed instructions:** See [CALENDAR_INTEGRATION.md](CALENDAR_INTEGRATION.md)

## Directory Structure

```
backend/
├── admin/                    # Admin panel pages
│   ├── index.php            # Dashboard
│   ├── login.php            # Login page
│   ├── logout.php           # Logout handler
│   ├── blog_list.php        # Blog posts list
│   ├── blog_edit.php        # Blog post editor
│   ├── blog_delete.php      # Delete blog post
│   └── bookings_list.php    # Bookings management
├── public/                   # Public pages
│   ├── blog.php             # Blog listing
│   ├── post.php             # Individual blog post
│   ├── api_bookings.php     # Booking API endpoint
│   └── download_ical.php    # iCalendar file download
├── includes/                 # Shared files
│   ├── config.php           # Configuration
│   ├── database.php         # Database class
│   ├── header.php           # Admin header
│   ├── footer.php           # Admin footer
│   ├── email_service.php    # Email confirmations
│   ├── icalendar.php        # iCalendar generator
│   └── google_calendar.php  # Google Calendar integration
└── bdta.db                   # SQLite database (auto-created)
```

## Usage

### Admin Panel

1. Navigate to `http://localhost:8000/admin/login.php`
2. Log in with default credentials
3. Access dashboard to manage blog posts and bookings

### Blog Management

- **List Posts:** admin/blog_list.php
- **New Post:** admin/blog_edit.php
- **Edit Post:** admin/blog_edit.php?id=X
- **Delete Post:** admin/blog_delete.php?id=X

### Booking Management

- **List Bookings:** admin/bookings_list.php
- **Update Status:** Change dropdown on bookings list
- **Delete Booking:** Click delete button

### Public Pages

- **Blog List:** public/blog.php
- **Blog Post:** public/post.php?slug=post-slug

### API Endpoints

#### Check Availability
```
GET public/api_bookings.php?date=2024-01-15
```

Response:
```json
{
  "date": "2024-01-15",
  "available_slots": ["09:00", "09:30", "10:00", ...]
}
```

#### Create Booking
```
POST public/api_bookings.php
Content-Type: application/json

{
  "client_name": "John Doe",
  "client_email": "john@example.com",
  "client_phone": "555-1234",
  "service_type": "Pet Manners at Home",
  "appointment_date": "2024-01-15",
  "appointment_time": "10:00",
  "notes": "First time client",
  "duration_minutes": 60
}
```

Response:
```json
{
  "success": true,
  "message": "Booking created successfully!",
  "booking_id": 123,
  "calendar_links": {
    "google_calendar": "https://calendar.google.com/calendar/render?...",
    "ical_download": "http://localhost:8000/backend/public/download_ical.php?booking_id=123"
  },
  "email_sent": true,
  "google_calendar_synced": false
}
```

## Production Deployment

### Apache Configuration

Create `.htaccess` files for security:

```apache
# In admin/ directory
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?url=$1 [L,QSA]
```

### Nginx Configuration

```nginx
location /backend/admin/ {
    try_files $uri $uri/ /backend/admin/index.php?$query_string;
}
```

### Email Service (Production)

Replace PHP's `mail()` with a professional service:

```bash
# Option 1: SendGrid (Recommended)
composer require sendgrid/sendgrid

# Option 2: Mailgun
composer require mailgun/mailgun-php

# Option 3: AWS SES
composer require aws/aws-sdk-php
```

Update `/backend/includes/email_service.php` accordingly.

## Security Notes

1. **Change Default Password** immediately
2. **File Permissions:** Set proper permissions on bdta.db (660)
3. **HTTPS:** Always use HTTPS in production
4. **Input Validation:** All inputs are validated and escaped
5. **SQL Injection Protection:** Using prepared statements
6. **XSS Protection:** All outputs are escaped
7. **Never commit** `google-calendar-credentials.json` to git

## Backup

### Backup Database

```bash
cp bdta.db bdta_backup_$(date +%Y%m%d).db
```

### Restore Database

```bash
cp bdta_backup_20240115.db bdta.db
```

## Troubleshooting

### Database Locked Error
- Ensure proper file permissions
- Restart PHP server

### Permission Denied
- Check file permissions: `chmod 660 bdta.db`
- Check directory permissions: `chmod 775 backend`

### Cannot Write to Database
- Ensure SQLite extension is enabled: `php -m | grep sqlite`
- Check file ownership

### Email Not Sending
- Check PHP `mail()` configuration
- For production, use SendGrid/Mailgun/AWS SES
- Check spam folder

### Calendar Integration Issues
- See [CALENDAR_INTEGRATION.md](CALENDAR_INTEGRATION.md) for detailed troubleshooting

## License

© 2024 Brook's Dog Training Academy. All rights reserved.
