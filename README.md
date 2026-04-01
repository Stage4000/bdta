# Brook's Dog Training Academy

Modern website and CRM for Brook's Dog Training Academy, with public booking, client/admin tools, invoicing, contracts, forms, quotes, blog management, and scheduled task processing.

## Stack

- PHP 7.4+
- MySQL 5.7+ or MariaDB 10.2+
- PDO MySQL
- Bootstrap 5

## Repository Layout

- `assets/`: shared front-end assets
- `backend/`: includes, public endpoints, cron tasks, uploads, and supporting docs
- `blog/`, `client/`, `portal/`: web entry points grouped by experience
- `tests/`: PHP smoke, integration, and workflow checks

## Key Capabilities

- Public booking flow with availability, confirmations, and calendar exports
- CRM views for clients, pets, notes, credits, forms, quotes, invoices, and contracts
- Admin-side scheduling, email templates, task automation, and reporting
- Stripe and Google Calendar integrations where configured

## Requirements

- PHP 7.4 or higher
- `pdo_mysql`
- `gd` or `imagick`
- `mbstring`
- `openssl`
- MySQL 5.7+ or MariaDB 10.2+

## Quick Start

1. Clone the repository.
2. Create the database and user:

```sql
CREATE DATABASE bdta CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bdta_user'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON bdta.* TO 'bdta_user'@'localhost';
FLUSH PRIVILEGES;
```

3. Copy `.env.example` to `.env` and fill in the MySQL credentials.
4. Start the app from `backend/`:

```bash
php -S localhost:8000
```

5. Open the app:

- Main website: `http://localhost:8000/../../index.html`
- Public booking: `http://localhost:8000/public/book.php`
- Blog: `http://localhost:8000/public/blog.php`
- Admin panel: `http://localhost:8000/client/login.php`

Default admin credentials:

- Username: `admin`
- Password: `admin123`

Change the default password immediately after first login.

## Database Behavior

- The runtime is MySQL-only.
- Tables and default settings are created automatically on first run.
- Backup and export utilities now target MySQL.
- If you still have an older SQLite file, import that data into MySQL before starting the application.

See `backend/MYSQL_MIGRATION.md` for MySQL deployment and legacy import guidance.

## Helpful Docs

- `backend/README.md`
- `backend/MYSQL_MIGRATION.md`
- `backend/EMAIL_CONFIGURATION.md`
- `backend/CALENDAR_INTEGRATION.md`
