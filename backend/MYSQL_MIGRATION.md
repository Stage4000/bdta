# MySQL Deployment And Legacy Import Guide

## Overview

The application is now MySQL-only at runtime. Use this guide to configure a fresh MySQL deployment or import legacy SQLite data into MySQL before starting the app.

## Fresh MySQL Setup

1. Install MySQL 5.7+ or MariaDB 10.2+.
2. Create the database and user:

```sql
CREATE DATABASE bdta CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bdta_user'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON bdta.* TO 'bdta_user'@'localhost';
FLUSH PRIVILEGES;
```

3. Copy `.env.example` to `.env` and set:

```env
DB_TYPE=mysql
DB_HOST=localhost
DB_PORT=3306
DB_NAME=bdta
DB_USER=bdta_user
DB_PASSWORD=your_secure_password
```

4. Start the application. Tables and default settings are created automatically on first run.

## Importing Legacy SQLite Data

SQLite is no longer a supported runtime backend, but it can still be used as a legacy source for one-time migration work.

1. Back up the old SQLite file.
2. Export the legacy data from SQLite using your preferred migration tooling.
3. Start the application once against MySQL so it creates the current schema.
4. Transform and import the legacy data into the MySQL tables.
5. Verify counts and spot-check key records such as admin users, clients, bookings, invoices, and settings.

## Verification

After setup or import, verify the MySQL database is healthy:

```bash
mysql -u bdta_user -p bdta -e "SHOW TABLES;"
mysql -u bdta_user -p bdta -e "SELECT COUNT(*) FROM admin_users;"
mysql -u bdta_user -p bdta -e "SELECT COUNT(*) FROM clients;"
mysql -u bdta_user -p bdta -e "SELECT COUNT(*) FROM bookings;"
```

## Backup And Restore

```bash
# Backup
mysqldump -u bdta_user -p bdta > bdta_backup.sql

# Restore
mysql -u bdta_user -p bdta < bdta_backup.sql
```

## Troubleshooting

### Connection failures

- Confirm MySQL is running.
- Confirm `.env` contains the correct host, port, database, user, and password.
- Confirm the configured user has `CREATE`, `ALTER`, `INDEX`, `INSERT`, `UPDATE`, and `DELETE` privileges.

### Schema creation problems

- Check the PHP error log for the failing SQL statement.
- Verify the database user can create and alter tables.
- Make sure you are pointing at the intended database.

### Legacy data import issues

- Import into the MySQL schema created by the current application, not into an old SQLite-shaped schema dump.
- Validate text field sizes and timestamp formats during transformation.
- Re-run spot checks for row counts and critical records after import.
