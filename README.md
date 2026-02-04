# Brook's Dog Training Academy - Complete CRM System

Modern, responsive website for Brook's Dog Training Academy with **complete PHP-based CRM backend** featuring client management, booking system, time tracking, invoicing, contracts, quotes, expenses, and blog functionality.

## About

Brook's Dog Training Academy was founded in 2018 by Brook Lefkowitz, an Animal Behavior College Certified Dog Trainer. Based in Highlands County, Florida (serving Sebring, Avon Park, and Lake Placid), BDTA provides private dog training and group events.

**Tagline**: "Teaching Humans to Speak Dog"

**Status**: Certified & Insured

## System Overview

This is a **complete business management system** combining a public-facing website with a powerful backend CRM designed specifically for service-based businesses. The system handles everything from client acquisition through booking, service delivery, time tracking, invoicing, and contract management.

## Features

### 🌐 Public Website (Frontend)
- **Modern Bootstrap 5 Design**: Fully responsive and mobile-first
- **Public Booking System**: Multi-step booking flow accessible directly from website
  - Appointment type selection with descriptions
  - Real-time availability checking
  - Date and time slot picker
  - Instant confirmation with calendar export
  - Unique shareable URLs per appointment type
- **Public Blog**: SEO-friendly blog with published posts
- **Contact Information**: Service showcase and event listings
- **Smooth Animations**: AOS (Animate On Scroll) library integration
- **SEO Optimized**: Proper meta tags and semantic HTML

### 💼 Complete CRM Backend (PHP + SQLite)
- ✅ **Client Management**
  - Comprehensive client profiles with all related data
  - Pet information (multiple pets per client)
  - Client notes and history
  - Credit balance tracking
  - View: appointments, contracts, forms, quotes, invoices in one place
  
- ✅ **Booking & Scheduling**
  - Public online booking (no login required)
  - Admin-side manual booking with override capabilities
  - Appointment types with custom rules
  - Multi-pet appointment support
  - Booking confirmation emails with calendar links
  - Google Calendar integration (optional)
  
- ✅ **Time Tracking**
  - Active timer with start/stop functionality (stopwatch)
  - Real-time elapsed time display
  - Automatic time entry creation
  - Billable/non-billable tracking
  - Hourly rate configuration
  - Mobile-optimized interface
  
- ✅ **Expense Management**
  - Expense tracking by category
  - Receipt photo/PDF upload
  - Client-linked expenses
  - Billable/non-billable designation
  
- ✅ **Invoicing & Payments**
  - Professional invoice generation
  - Stripe payment integration
  - Manual payment recording (cash, check)
  - Pull unbilled time/expenses
  - Tax calculation
  - Payment status tracking
  
- ✅ **Quote Management**
  - Full lifecycle: Draft → Sent → Viewed → Accepted/Declined/Expired
  - Public quote viewing (no login)
  - Status tracking and management
  - Re-send capability
  - Client accept/decline with tracking
  
- ✅ **Contract Management**
  - Create contracts from templates
  - Edit/delete draft contracts
  - Status workflow: Draft → Sent → Signed → Expired
  - Public contract viewing/signing
  - Electronic signature capture
  - Version control and audit trail
  
- ✅ **Form Management**
  - Custom form templates
  - Form submissions tracking
  - Client-facing forms
  - Internal assessment forms
  
- ✅ **Blog System**
  - Create, edit, delete blog posts
  - Draft/published workflow
  - SEO-friendly slugs
  - Public blog listing and detail pages
  
- ✅ **Automated Task Scheduling (CRON Jobs)**
  - Booking reminder emails (24 hours before appointments)
  - Contract reminder emails (for unsigned contracts)
  - Form reminder emails (for incomplete forms)
  - Customizable task schedules
  - Task execution monitoring and logging
  - Admin panel for managing scheduled tasks
  
- ✅ **Settings & Configuration**
  - Business information
  - Email settings
  - Payment gateway configuration
  - Calendar integration
  - Invoice settings
  - Time tracking defaults

## Services Offered

- Pet Manners at Home I & II
- Walking Etiquette
- Social Manners
- Pawtner Support (for anxious dogs)
- Introducing Equipment
- Pet Sitting Services
- Group Workshops & Events

## Technology Stack

### Frontend
- **HTML5** - Semantic markup
- **CSS3** - Custom styles + Bootstrap 5.3.2
- **JavaScript (ES6+)** - Interactive features
- **Bootstrap 5.3.2** - Responsive framework
- **Font Awesome 6.5.1** - Icon library
- **Google Fonts** - Poppins & Montserrat
- **AOS Library** - Scroll animations

### Backend
- **PHP 7.4+** - Server-side language
- **SQLite 3** - Embedded database (no external DB required)
- **PDO** - Database abstraction layer with prepared statements
- **Sessions** - User authentication and state management
- **File Uploads** - Receipt and document storage

### Optional Integrations
- **Stripe** - Online payment processing
- **Google Calendar API** - Calendar synchronization
- **Email Services** - SendGrid, Mailgun, or AWS SES for transactional emails

### Branding & Colors
The system uses the official BDTA brand colors:
- **Primary (Purple)**: `#9a0073` - Main branding color
- **Secondary (Teal)**: `#0a9a9c` - Accent and success states
- **Accent (Tan)**: `#a39f89` - Supporting color

These colors are consistently applied across:
- Frontend website (CSS variables in `/css/style.css`)
- Admin panel (inline styles in `/backend/includes/header.php`)
- Public booking and contract pages
- Email templates

To customize branding colors, update the CSS variables:
```css
:root {
    --primary-color: #9a0073;
    --secondary-color: #0a9a9c;
    --accent-color: #a39f89;
}
```

## System Requirements

- **PHP**: 7.4 or higher
- **PHP Extensions**:
  - `sqlite3` (usually included)
  - `pdo_sqlite` (usually included)
  - `gd` or `imagick` (for image processing)
  - `mbstring` (for string handling)
  - `openssl` (for secure sessions)
- **Web Server**: Apache, Nginx, or PHP built-in server
- **Optional**: Composer (for Stripe and Google Calendar integrations)

## Installation & Setup

### Quick Start (Development)

1. **Clone the repository**
   ```bash
   git clone https://github.com/Stage4000/bdta.git
   cd bdta
   ```

2. **Start PHP built-in server**
   ```bash
   cd backend
   php -S localhost:8000
   ```

3. **Access the application**
   - **Main Website**: http://localhost:8000/../../index.html
   - **Public Booking**: http://localhost:8000/public/book.php
   - **Blog**: http://localhost:8000/public/blog.php
   - **Admin Panel**: http://localhost:8000/client/login.php

4. **Default Admin Credentials**
   - Username: `admin`
   - Password: `admin123`
   
   ⚠️ **Change immediately after first login!**

### Database Initialization

The SQLite database (`bdta.db`) is automatically created on first run with:
- Admin user table (with default admin account)
- All CRM tables (clients, pets, bookings, etc.)
- Default settings
- Sample appointment types

No manual database setup required!

### File Structure

```
bdta/
├── index.html                 # Main website homepage
├── css/
│   └── style.css             # Custom styles
├── js/
│   └── script.js             # Frontend JavaScript
├── assets/
│   └── images/               # Website images
├── client/                   # Client/Admin panel pages (44 files)
│   ├── index.php            # Dashboard
│   ├── login.php            # Login page
│   ├── clients_list.php     # Client management
│   ├── clients_view.php     # Client detail view (NEW)
│   ├── bookings_create.php  # Manual booking
│   ├── quotes_view.php      # Quote management (ENHANCED)
│   ├── contracts_view.php   # Contract management (ENHANCED)
│   ├── expenses_edit.php    # Expense with receipts (ENHANCED)
│   ├── time_tracker.php     # Active timer (NEW)
│   └── ... (40 more files)
├── backend/
│   ├── public/              # Public-facing pages
│   │   ├── book.php        # Public booking flow (NEW)
│   │   ├── blog.php        # Blog listing
│   │   ├── post.php        # Individual blog post
│   │   ├── quote.php       # Public quote view
│   │   ├── api_bookings.php # Booking API
│   │   └── download_ical.php # Calendar export
│   ├── includes/            # Shared PHP files
│   │   ├── config.php      # Configuration
│   │   ├── database.php    # Database class & initialization
│   │   ├── header.php      # Admin header/navigation
│   │   ├── footer.php      # Admin footer
│   │   ├── email_service.php # Email sending
│   │   ├── icalendar.php   # Calendar file generation
│   │   ├── google_calendar.php # Google Calendar sync
│   │   ├── settings.php    # Settings helper
│   │   └── stripe_config.php # Stripe configuration
│   ├── uploads/
│   │   └── receipts/       # Expense receipt uploads
│   ├── assets/
│   │   └── css/
│   │       └── mobile.css  # Mobile-optimized styles
│   └── bdta.db             # SQLite database (auto-created)
├── README.md               # This file
└── .gitignore
```

## Configuration

### Email Settings

**IMPORTANT:** The system now uses PHPMailer for reliable email delivery. PHP's default `mail()` function is unreliable and often doesn't work without proper server configuration.

Configure email delivery in the Admin Panel:
1. Go to **Settings** → **Email**
2. Choose email service:
   - **PHP mail()** - Basic PHP function (not recommended for production)
   - **SMTP** - Recommended for production (works with Gmail, Zoho, Outlook, etc.)

**Quick Start with SMTP:**

For **Gmail**:
```
Email Service: SMTP
Host: smtp.gmail.com
Port: 587
Username: your-email@gmail.com
Password: your-app-password (generate at https://myaccount.google.com/apppasswords)
```

For **Zoho**:
```
Email Service: SMTP
Host: smtp.zoho.com
Port: 587
Username: your-email@zoho.com
Password: your-app-specific-password
```

**Testing Your Configuration:**
After configuring SMTP settings, test your email by visiting:
- `http://localhost:8000/backend/public/test_email.php`

⚠️ **Delete test_email.php after testing** for security.

For detailed configuration instructions for other email providers (Outlook, SendGrid, Mailgun, AWS SES), see [backend/EMAIL_CONFIGURATION.md](backend/EMAIL_CONFIGURATION.md).

### Stripe Payment Integration

1. Create account at [stripe.com](https://stripe.com)
2. Get your API keys (test and live)
3. Configure in **Settings** → **Payment**:
   ```
   Test Publishable Key: pk_test_...
   Test Secret Key: sk_test_...
   ```
4. Enable Stripe in settings
5. For production, add live keys

### Google Calendar Sync (Optional)

For automatic calendar synchronization:

1. **Create Google Cloud Project**
   - Go to [console.cloud.google.com](https://console.cloud.google.com)
   - Create new project
   - Enable Google Calendar API

2. **Create Service Account**
   - Go to IAM & Admin → Service Accounts
   - Create service account
   - Download JSON credentials file

3. **Configure in Backend**
   ```bash
   mv credentials.json backend/google-calendar-credentials.json
   ```

4. **Share Calendar**
   - Open Google Calendar
   - Share your calendar with service account email
   - Grant "Make changes to events" permission

5. **Enable in Settings**
   - Admin Panel → Settings → Calendar
   - Enable Google Calendar Sync
   - Set Calendar ID (usually your email or "primary")

See [backend/CALENDAR_INTEGRATION.md](backend/CALENDAR_INTEGRATION.md) for detailed instructions.

## Usage Guide

### For Clients (Public Users)

#### Booking an Appointment
1. Visit the main website
2. Click "Book Now" button
3. Select appointment type
4. Choose date and available time slot
5. Fill in your information
6. Confirm booking
7. Receive confirmation email with calendar links

Direct booking link example:
- General: `http://yoursite.com/backend/public/book.php`
- Specific type: `http://yoursite.com/backend/public/book.php?type=1`

#### View Quote
1. Receive quote email with link
2. Click link to view quote details
3. Accept or decline the quote
4. System tracks your response

#### Sign Contract
1. Receive contract email with link
2. Review contract terms
3. Sign electronically
4. Receive confirmation

### For Administrators

#### Managing Clients
1. **Admin Panel** → **Clients**
2. **View Client**: Click eye icon for comprehensive profile
   - See all appointments, contracts, forms, quotes, invoices
   - Manage credits
   - Add notes
3. **Edit Client**: Update information and pet details
4. **Create Booking**: Direct link from client profile

#### Creating Manual Bookings
1. **Admin Panel** → **Bookings** → **Create Booking**
2. Select client and appointment type
3. Choose date/time
4. Select pets involved
5. Add notes
6. Override rules if needed (forms, contracts, credits)
7. Save booking

#### Time Tracking with Timer
1. **Admin Panel** → **Time Tracker**
2. Select client and service type
3. Click "Start Timer"
4. Work on task (timer runs in background)
5. Click "Stop Timer" when done
6. Time entry automatically created

#### Creating Quotes
1. **Admin Panel** → **Quotes** → **Create Quote**
2. Select client and add line items
3. Set expiration date
4. Save as draft or send immediately
5. Copy public link to send to client
6. Track status (viewed, accepted, declined)
7. Re-send if needed

#### Managing Contracts
1. **Admin Panel** → **Contracts** → **Create Contract**
2. Select client and template
3. Customize contract text
4. Save as draft to edit
5. Change status to "Sent" when ready
6. Share public link with client
7. Monitor for signature

#### Recording Expenses
1. **Admin Panel** → **Expenses** → **Add Expense**
2. Fill in details and amount
3. Upload receipt photo/PDF
4. Mark as billable if applicable
5. Link to client if relevant
6. Save expense

#### Generating Invoices
1. **Admin Panel** → **Invoices** → **Create Invoice**
2. Select client
3. Add line items or pull unbilled time/expenses
4. Set due date and tax rate
5. Send to client with payment link
6. Record payments (Stripe or manual)

## API Documentation

### Booking API

#### Check Availability
```http
GET /backend/public/api_bookings.php?date=2024-01-15

Response:
{
  "date": "2024-01-15",
  "available_slots": ["09:00", "09:30", "10:00", "10:30", ...]
}
```

#### Create Booking
```http
POST /backend/public/api_bookings.php
Content-Type: application/json

{
  "appointment_type_id": 1,
  "client_name": "John Doe",
  "client_email": "john@example.com",
  "client_phone": "555-1234",
  "service_type": "Consultation",
  "appointment_date": "2024-01-15",
  "appointment_time": "10:00",
  "duration_minutes": 60,
  "notes": "First time client"
}

Response:
{
  "success": true,
  "message": "Booking created successfully!",
  "booking_id": 123,
  "calendar_links": {
    "google_calendar": "https://calendar.google.com/calendar/render?...",
    "ical_download": "http://yoursite.com/backend/public/download_ical.php?booking_id=123"
  },
  "email_sent": true,
  "google_calendar_synced": false
}
```

## Production Deployment

### Apache Setup

1. **Configure Virtual Host**
   ```apache
   <VirtualHost *:80>
       ServerName brooksdogtraining.com
       DocumentRoot /var/www/bdta
       
       <Directory /var/www/bdta>
           Options -Indexes +FollowSymLinks
           AllowOverride All
           Require all granted
       </Directory>
       
       <Directory /var/www/bdta/client>
           Require all granted
       </Directory>
       
       ErrorLog ${APACHE_LOG_DIR}/bdta-error.log
       CustomLog ${APACHE_LOG_DIR}/bdta-access.log combined
   </VirtualHost>
   ```

2. **Create .htaccess for Security**
   ```apache
   # In backend/includes/ and backend/uploads/
   Order deny,allow
   Deny from all
   
   # In backend/ directory
   RewriteEngine On
   RewriteCond %{REQUEST_FILENAME} !-f
   RewriteCond %{REQUEST_FILENAME} !-d
   RewriteRule ^(.*)$ index.php?url=$1 [L,QSA]
   ```

3. **Set File Permissions**
   ```bash
   chmod 755 /var/www/bdta
   chmod 755 /var/www/bdta/backend
   chmod 775 /var/www/bdta/backend/uploads
   chmod 660 /var/www/bdta/backend/bdta.db
   chown -R www-data:www-data /var/www/bdta/backend
   ```

### Nginx Setup

```nginx
server {
    listen 80;
    server_name brooksdogtraining.com;
    root /var/www/bdta;
    index index.html index.php;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location /client/ {
        try_files $uri $uri/ /client/index.php?$query_string;
    }

    location /backend/includes/ {
        deny all;
    }

    location /backend/uploads/ {
        internal;
    }
}
```

### SSL/HTTPS (Required for Production)

```bash
# Using Let's Encrypt (recommended)
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d brooksdogtraining.com
```

### Security Checklist

- [ ] Change default admin password
- [ ] Enable HTTPS/SSL
- [ ] Set proper file permissions (660 for db, 644 for PHP files)
- [ ] Configure firewall (allow 80, 443 only)
- [ ] Use strong database passwords
- [ ] Enable PHP security settings:
  ```ini
  ; In php.ini
  expose_php = Off
  display_errors = Off
  log_errors = On
  error_log = /var/log/php-errors.log
  ```
- [ ] Regular database backups
- [ ] Keep PHP updated
- [ ] Use production email service (not PHP mail())
- [ ] Never commit credentials to git

## Backup & Maintenance

### Automated Backup Script

```bash
#!/bin/bash
# Save as backup.sh

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/var/backups/bdta"
DB_FILE="/var/www/bdta/backend/bdta.db"
UPLOADS_DIR="/var/www/bdta/backend/uploads"

mkdir -p $BACKUP_DIR

# Backup database
cp $DB_FILE $BACKUP_DIR/bdta_$DATE.db

# Backup uploads
tar -czf $BACKUP_DIR/uploads_$DATE.tar.gz $UPLOADS_DIR

# Keep only last 30 days
find $BACKUP_DIR -name "bdta_*.db" -mtime +30 -delete
find $BACKUP_DIR -name "uploads_*.tar.gz" -mtime +30 -delete

echo "Backup completed: $DATE"
```

### Cron Job for Daily Backups

```bash
# Edit crontab
crontab -e

# Add this line (runs daily at 2 AM)
0 2 * * * /usr/local/bin/backup.sh
```

### Cron Job for Automated Tasks

The system includes automated task scheduling for sending reminders and notifications. See [backend/CRON_SETUP.md](backend/CRON_SETUP.md) for detailed setup instructions.

Quick setup (runs every 15 minutes):
```bash
# Edit crontab
crontab -e

# Add this line
*/15 * * * * php /var/www/bdta/backend/cron/cron.php >> /var/log/bdta-cron.log 2>&1
```

**Automated tasks include:**
- Booking reminders (sent 24 hours before appointments)
- Contract signature reminders (for unsigned contracts)
- Form completion reminders (for pending forms)

**Managing scheduled tasks:**
1. Navigate to Admin Panel → Scheduled Tasks
2. View task execution logs
3. Enable/disable tasks
4. Configure task schedules
0 2 * * * /path/to/backup.sh
```

### Restore from Backup

```bash
# Stop web server
sudo systemctl stop apache2

# Restore database
cp /var/backups/bdta/bdta_20240115_020000.db /var/www/bdta/backend/bdta.db

# Restore uploads
tar -xzf /var/backups/bdta/uploads_20240115_020000.tar.gz -C /var/www/bdta/backend/

# Set permissions
sudo chown -R www-data:www-data /var/www/bdta/backend

# Start web server
sudo systemctl start apache2
```

## Troubleshooting

### Database Issues

**Error: "Database is locked"**
```bash
# Check file permissions
ls -l backend/bdta.db

# Fix permissions
chmod 660 backend/bdta.db
chown www-data:www-data backend/bdta.db

# Restart web server
sudo systemctl restart apache2
```

**Error: "Unable to open database"**
```bash
# Verify SQLite extension
php -m | grep sqlite

# If missing, install
sudo apt install php-sqlite3
sudo systemctl restart apache2
```

### Email Not Sending

**The system now uses PHPMailer with SMTP support for reliable email delivery.**

#### Quick Fix:
1. Go to **Settings** → **Email** in the Admin Panel
2. Change "Email Service" from "PHP mail()" to "SMTP"
3. Configure SMTP settings for your email provider (Gmail, Zoho, etc.)
4. Test using `http://localhost:8000/backend/public/test_email.php`

#### Common Issues:

**"SMTP connect() failed"**
- Verify SMTP host and port are correct
- Check firewall is not blocking port 587
- Try port 465 if 587 doesn't work

**"Could not authenticate"**
- For Gmail: Use App Password, not your regular password
- For Zoho: Use App-Specific Password
- Verify username and password are correct

**"Connection refused" or "Connection timed out"**
- Port 587 or 465 may be blocked by your hosting provider
- Contact your hosting provider to enable SMTP
- Consider using a dedicated email service (SendGrid, Mailgun)

For detailed troubleshooting, see [backend/EMAIL_CONFIGURATION.md](backend/EMAIL_CONFIGURATION.md)

#### Old PHP mail() Issues (Not Recommended):

1. **Check PHP mail() configuration**
   ```bash
   php -i | grep sendmail
   ```

2. **Test with PHP script**
   ```php
   <?php
   mail('test@example.com', 'Test', 'Test message');
   ?>
   ```

**Note:** PHP mail() is unreliable and not recommended. Use SMTP instead.

### File Upload Issues

**Error: "Failed to upload receipt"**
```bash
# Check directory exists and is writable
ls -ld backend/uploads/receipts/
mkdir -p backend/uploads/receipts
chmod 775 backend/uploads/receipts
chown www-data:www-data backend/uploads/receipts

# Check PHP upload limits
php -i | grep upload_max_filesize
php -i | grep post_max_size

# Increase if needed in php.ini
upload_max_filesize = 10M
post_max_size = 10M
```

### Session Issues

**Error: "Session failed to start"**
```bash
# Check session directory permissions
ls -ld /var/lib/php/sessions/
sudo chmod 1733 /var/lib/php/sessions/

# Or set custom session path
# In backend/includes/config.php, add:
session_save_path('/var/www/bdta/backend/sessions');
```

### Performance Optimization

1. **Enable OPcache**
   ```ini
   ; In php.ini
   opcache.enable=1
   opcache.memory_consumption=128
   opcache.max_accelerated_files=10000
   opcache.revalidate_freq=60
   ```

2. **Database Optimization**
   ```bash
   # Vacuum SQLite database (reclaim space)
   sqlite3 backend/bdta.db "VACUUM;"
   ```

3. **Enable Gzip Compression**
   ```apache
   # In .htaccess
   <IfModule mod_deflate.c>
       AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript application/javascript
   </IfModule>
   ```

## Browser Support

- ✅ Chrome (latest)
- ✅ Firefox (latest)
- ✅ Safari (latest)
- ✅ Edge (latest)
- ✅ Mobile browsers (iOS Safari, Chrome Mobile)
- ✅ Tablet browsers

## Support & Documentation

- **Backend Documentation**: [backend/README.md](backend/README.md)
- **Calendar Integration**: [backend/CALENDAR_INTEGRATION.md](backend/CALENDAR_INTEGRATION.md)
- **Business Specs**: [backend/BUSINESS_MANAGEMENT.md](backend/BUSINESS_MANAGEMENT.md)

## Credits

- **Design & Development**: Modern Bootstrap 5 responsive design with PHP CRM backend
- **Training Services**: Brook's Dog Training Academy
- **Icons**: Font Awesome 6.5.1
- **Fonts**: Google Fonts (Poppins & Montserrat)
- **Framework**: Bootstrap 5.3.2

## Contact

For more information about Brook's Dog Training Academy:
- **Website**: https://brooksdogtrainingacademy.com
- **Facebook**: https://www.facebook.com/BrooksDogTrainingAcademy
- **Instagram**: https://www.instagram.com/brooksdogtrainingacademy

## License

© 2024 Brook's Dog Training Academy. All rights reserved.