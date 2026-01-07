# Brook's Dog Training Academy - PHP Backend

Complete backend system with blog functionality, booking calendar, and admin panel using **PHP and SQLite**.

## Features

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

### ✅ Admin Panel
- Secure login system
- Dashboard with statistics
- Blog post management
- Booking management
- Status updates

### ✅ Database (SQLite)
- No external database required
- Automatic initialization
- Tables for admin users, blog posts, and bookings

## Requirements

- PHP 7.4 or higher
- SQLite3 PHP extension (usually included)
- Web server (Apache, Nginx, or PHP built-in server)

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
│   └── api_bookings.php     # Booking API endpoint
├── includes/                 # Shared files
│   ├── config.php           # Configuration
│   ├── database.php         # Database class
│   ├── header.php           # Admin header
│   └── footer.php           # Admin footer
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
  "notes": "First time client"
}
```

Response:
```json
{
  "success": true,
  "message": "Booking created successfully!",
  "booking_id": 123
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

## Security Notes

1. **Change Default Password** immediately
2. **File Permissions:** Set proper permissions on bdta.db (660)
3. **HTTPS:** Always use HTTPS in production
4. **Input Validation:** All inputs are validated and escaped
5. **SQL Injection Protection:** Using prepared statements
6. **XSS Protection:** All outputs are escaped

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

## License

© 2024 Brook's Dog Training Academy. All rights reserved.
