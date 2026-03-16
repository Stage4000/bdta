# MySQL Migration Guide

This guide explains how to migrate legacy SQLite data into MySQL. SQLite is no longer supported at runtime.

## Overview

The Brook's Dog Training Academy backend now requires **MySQL** (or MariaDB). Legacy conversion helpers remain to assist with migrating an existing SQLite database into MySQL.

## Quick Start

### Option 1: Use SQLite (Default)

**No configuration needed!** The application works out-of-the-box with SQLite.

Just start the PHP server:
```bash
cd backend
php -S localhost:8000
```

### Option 2: Use MySQL

1. **Install MySQL** (if not already installed)
   ```bash
   # Ubuntu/Debian
   sudo apt-get update
   sudo apt-get install mysql-server
   
   # macOS (using Homebrew)
   brew install mysql
   brew services start mysql
   
   # Windows: Download from https://dev.mysql.com/downloads/mysql/
   ```

2. **Create Database and User**
   ```sql
   -- Log into MySQL as root
   mysql -u root -p
   
   -- Create database
   CREATE DATABASE bdta CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   
   -- Create user and grant permissions
   CREATE USER 'bdta_user'@'localhost' IDENTIFIED BY 'your_secure_password';
   GRANT ALL PRIVILEGES ON bdta.* TO 'bdta_user'@'localhost';
   FLUSH PRIVILEGES;
   
   -- Exit MySQL
   EXIT;
   ```

3. **Configure Environment**
   ```bash
   # Copy the example .env file
   cp .env.example .env
   
   # Edit with your credentials
   nano .env
   ```
   
Update `.env`:
```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=bdta
DB_USER=bdta_user
DB_PASSWORD=your_secure_password
```

4. **Start the Application**
   ```bash
   cd backend
   php -S localhost:8000
   ```
   
   Tables will be created automatically on first run!

## Migrating Data from SQLite to MySQL

If you have existing data in SQLite and want to migrate to MySQL:

### Step 1: Backup Your SQLite Database
```bash
cp backend/bdta.db backend/bdta_backup_$(date +%Y%m%d).db
```

### Step 2: Export SQLite Data
```bash
# Export all data as SQL
sqlite3 backend/bdta.db .dump > sqlite_export.sql
```

### Step 3: Convert SQLite SQL to MySQL Format

The exported SQL needs some modifications for MySQL compatibility:

```bash
# Remove SQLite-specific commands
sed -i '/PRAGMA/d' sqlite_export.sql
sed -i '/BEGIN TRANSACTION/d' sqlite_export.sql
sed -i '/COMMIT/d' sqlite_export.sql

# Change AUTOINCREMENT to AUTO_INCREMENT
sed -i 's/AUTOINCREMENT/AUTO_INCREMENT/g' sqlite_export.sql

# Fix integer types
sed -i 's/INTEGER PRIMARY KEY AUTO_INCREMENT/INT AUTO_INCREMENT PRIMARY KEY/g' sqlite_export.sql
```

### Step 4: Setup MySQL (as shown above)

Create the database and user, then configure `.env` with your MySQL credentials.

### Step 5: Let Application Create Tables

Start the application once to create all tables:
```bash
cd backend
php -S localhost:8000 &
sleep 5
kill %1
```

### Step 6: Import Data Only

Now you need to import just the data (not table definitions):

```bash
# Extract only INSERT statements
grep "^INSERT" sqlite_export.sql > data_only.sql

# Import into MySQL
mysql -u bdta_user -p bdta < data_only.sql
```

### Step 7: Verify Migration

```bash
# Test the database
php test_database.php

# Check data manually
mysql -u bdta_user -p bdta -e "SELECT COUNT(*) FROM admin_users;"
mysql -u bdta_user -p bdta -e "SELECT COUNT(*) FROM clients;"
mysql -u bdta_user -p bdta -e "SELECT COUNT(*) FROM bookings;"
```

## Testing Both Databases

MySQL is required; ensure `.env` contains valid connection details and run `php test_database.php` to verify connectivity.

## Production Deployment

### Recommended Setup

1. **Production:** Use MySQL
   - Better performance for concurrent users
   - Supports larger datasets
   - Industry standard for web applications
   - Better backup and replication options

2. **Development/Testing:** Use SQLite
   - Zero setup required
   - Fast for single-user scenarios
   - Perfect for CI/CD pipelines
   - Easy to reset and test

### Production Checklist

- [ ] MySQL server installed and running
- [ ] Database created with UTF8MB4 character set
- [ ] User created with strong password
- [ ] `.env` file created with correct credentials
- [ ] `.env` file has restrictive permissions (600)
- [ ] `.env` is NOT committed to version control
- [ ] Application tested with MySQL
- [ ] Regular backups configured
- [ ] Admin password changed from default

## Troubleshooting

### "MySQL Connection Failed, Falling Back to SQLite"

Check the PHP error log for the exact error. Common issues:

1. **MySQL server not running**
   ```bash
   sudo systemctl status mysql  # Linux
   brew services list           # macOS
   ```

2. **Wrong credentials**
   - Verify username/password in `.env`
   - Test: `mysql -u bdta_user -p bdta`

3. **Database doesn't exist**
   ```sql
   mysql -u root -p -e "CREATE DATABASE bdta;"
   ```

4. **Insufficient permissions**
   ```sql
   mysql -u root -p -e "GRANT ALL PRIVILEGES ON bdta.* TO 'bdta_user'@'localhost';"
   ```

### "Table Already Exists" Errors

This happens if you have mixed table definitions. Solution:

```bash
# Drop all tables and let the application recreate them
mysql -u bdta_user -p bdta -e "SET FOREIGN_KEY_CHECKS=0; DROP DATABASE bdta; CREATE DATABASE bdta;"
# Restart application to recreate tables
```

### Performance Issues with MySQL

1. **Enable query cache** (if supported by your MySQL version)
2. **Add indexes** on frequently queried columns
3. **Optimize tables** regularly:
   ```sql
   OPTIMIZE TABLE admin_users, clients, bookings, invoices;
   ```

### Character Encoding Issues

Ensure UTF8MB4 is used throughout:

```sql
-- Check database charset
SHOW CREATE DATABASE bdta;

-- Convert if needed
ALTER DATABASE bdta CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
```

## Best Practices

1. **Use Environment Variables** - Never hardcode credentials
2. **Keep .env Out of Git** - Already in .gitignore
3. **Use Strong Passwords** - For MySQL users
4. **Regular Backups** - Automate with cron
5. **Test Fallback** - Occasionally test SQLite fallback
6. **Monitor Performance** - Use MySQL slow query log
7. **Secure MySQL** - Run `mysql_secure_installation`

## Support

For issues or questions:
1. Check the troubleshooting section in README.md
2. Review PHP error logs
3. Test database connection manually
4. Verify .env configuration

## References

- [MySQL Documentation](https://dev.mysql.com/doc/)
- [SQLite Documentation](https://www.sqlite.org/docs.html)
- [PHP PDO Documentation](https://www.php.net/manual/en/book.pdo.php)
