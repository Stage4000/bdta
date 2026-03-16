# IMAP Settings Migration

> Note: SQLite support has been removed. The sqlite3 commands below are only for exporting legacy data before importing into MySQL.

## Problem
If you don't see IMAP email receiving settings in **Settings → Email**, your database may not have been updated with the new IMAP configuration fields.

## Solution
Run the migration script to add IMAP settings to your existing database:

```bash
cd /path/to/bdta/backend
php add_imap_settings.php
```

## What This Does
The script adds 8 IMAP configuration settings to your `settings` table:
- Enable IMAP Email Receiving
- IMAP Host
- IMAP Port
- IMAP Encryption
- IMAP Username
- IMAP Password
- IMAP Folder
- Sync Days

## After Running
1. Refresh your browser (hard refresh: Ctrl+F5 or Cmd+Shift+R)
2. Navigate to **Settings → Email** in the admin panel
3. **Scroll down** past the SMTP and other email service settings
4. You should now see the **IMAP Settings** section

## Verification
To verify the settings were added:
```bash
cd /path/to/bdta/backend
sqlite3 bdta.db "SELECT setting_key FROM settings WHERE setting_key LIKE 'imap%';"
```

You should see 8 IMAP-related settings.

## Troubleshooting
If you still don't see the settings:
1. Clear your browser cache
2. Check browser console for JavaScript errors
3. Verify the database file permissions
4. Try logging out and back in

## Need Help?
Check the full documentation: `backend/EMAIL_CORRESPONDENCE.md`
