# BDTA Backend

The backend powers the public booking flow, CRM/admin tools, invoicing, contracts, forms, blog publishing, and scheduled tasks for Brook's Dog Training Academy.

## Runtime Requirements

- PHP 7.4+
- MySQL 5.7+ or MariaDB 10.2+
- `pdo_mysql`
- Web server or PHP built-in server
- Optional Composer dependencies for Stripe and Google Calendar features

## Database

- The backend runs on MySQL only.
- Configure connection settings in `.env` before startup.
- Schema creation and follow-up migrations run automatically on first launch.
- Connection testing, SQL export, and backup utilities all target MySQL.

Example `.env` values:

```env
DB_TYPE=mysql
DB_HOST=localhost
DB_PORT=3306
DB_NAME=bdta
DB_USER=bdta_user
DB_PASSWORD=your_mysql_password
```

If you still have historical SQLite data, import it into MySQL before using the application. See `backend/MYSQL_MIGRATION.md`.

## Start Locally

```bash
cd backend
php -S localhost:8000
```

Common entry points:

- Website: `http://localhost:8000/../../index.html`
- Blog: `http://localhost:8000/public/blog.php`
- Admin panel: `http://localhost:8000/client/login.php`

Default admin credentials:

- Username: `admin`
- Password: `admin123`

Change the password immediately after first login.

## Security Notes

- Never commit `.env`.
- Restrict `.env` permissions where possible.
- Use HTTPS in production.
- Keep SQL dumps and API credentials out of version control.

## Backup And Restore

```bash
# Backup
mysqldump -u bdta_user -p bdta > bdta_backup.sql

# Restore
mysql -u bdta_user -p bdta < bdta_backup.sql
```

## Troubleshooting

### Cannot connect to MySQL

- Verify MySQL is running.
- Verify the host, port, database, user, and password in `.env`.
- Verify the MySQL user has permission to create and modify tables.

### Tables were not created

- Check the PHP error log.
- Confirm the configured MySQL user has `CREATE`, `ALTER`, and `INDEX` privileges.
- Confirm the application is pointed at the expected database.

### Need to verify the database

Run the test script or query MySQL directly:

```bash
php tests/test_database.php
mysql -u bdta_user -p bdta -e "SHOW TABLES;"
```
