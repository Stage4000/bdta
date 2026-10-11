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

## Legacy Package Credits (`session_type`)

Older package schemas store categories such as `group`, `mini`, `private`, and
`field_rental` instead of an `appointment_type_id`. A category can cover several
appointment types, so bootstrap cannot infer the correct allocation. It stops
before schema writes when any existing `package_items`, `client_package_credits`,
or `package_credit_transactions` table still has `session_type` or lacks
`appointment_type_id`. This also applies to empty legacy tables and interrupted
conversions. Repeated startup attempts leave the legacy data intact.

An operator must complete an explicit conversion offline before startup:

1. Stop application and scheduled writers, take a complete database backup, and
   verify restoration into a separate database. Work on that copy first.
2. Determine and review the appointment-type assignment for each legacy package
   item and credit row. Preserve original session labels in the backup/archive;
   do not guess from names or split balances across matching appointment types.
3. Convert into the current table definitions while retaining every primary key,
   purchase, quantity, used balance, signed ledger amount, timestamp, note, and
   relationship. Assign transactions consistently with their credit rows. Preserve
   `client_packages`, invoice references, and `bookings.package_credit_id` links.
4. Reconcile row counts, balances and references with the untouched source. Keep
   the source/archive until the converted data is verified. All three live tables
   must have the current `appointment_type_id` column and constraints and no
   remaining `session_type` column before restarting.
5. Retry startup only after completing the conversion. If a conversion fails,
   resume or restore the verified copy; do not clear the package tables to bypass
   the startup check. MySQL DDL commits implicitly, so a transaction alone cannot
   roll back a multi-table schema conversion.

Fresh/current schemas continue using normal bootstrap. This safeguard does not
restore rows that an earlier application version already deleted, and does not
provide an automatic converter for ambiguous legacy allocations.

The real bootstrap regression creates and removes only randomly named
`bdta_test_package_schema_*` databases. On a disposable loopback MySQL/MariaDB
server, run:

```bash
BDTA_MIGRATION_TEST_PORT=3307 BDTA_MIGRATION_TEST_USER=test_user \
  php tests/test_legacy_package_schema_preservation.php
```

Set `BDTA_MIGRATION_TEST_PASSWORD` if needed. The test user needs database creation
and removal privileges on this disposable server. No existing application schema
is selected. Without the opt-in variables, the test reports `SKIP`.

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
