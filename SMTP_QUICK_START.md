# Quick Start: SMTP Email Fix

This update fixes email deliverability issues with the public booking form when using SMTP settings.

## What Was Fixed

- ✅ SMTP authentication now works correctly (optional when credentials not needed)
- ✅ Support for both TLS and SSL encryption
- ✅ Better error messages and logging
- ✅ Debug mode for troubleshooting
- ✅ Timeout configuration to prevent hanging
- ✅ Proper validation of SMTP settings

## Quick Setup

### For New Installations
No action needed - new settings are automatically created.

### For Existing Installations

1. **Run the migration script** (one time only):
   ```bash
   cd backend/includes
   php migrate_email_settings.php
   ```

2. **Update your SMTP configuration**:
   - Go to Admin Panel → Settings → Email
   - Set "SMTP Encryption" to `tls` (for port 587) or `ssl` (for port 465)
   - Save settings

3. **Test your configuration**:
   ```bash
   cd backend/includes
   php test_email_service.php
   ```

## Common Configurations

### Gmail
```
SMTP Host: smtp.gmail.com
SMTP Port: 587
SMTP Encryption: tls
SMTP Username: your-email@gmail.com
SMTP Password: your-app-password (from Google)
```

### Zoho
```
SMTP Host: smtp.zoho.com
SMTP Port: 587
SMTP Encryption: tls
SMTP Username: your-email@zoho.com
SMTP Password: your-app-specific-password
```

### SendGrid
```
SMTP Host: smtp.sendgrid.net
SMTP Port: 587
SMTP Encryption: tls
SMTP Username: apikey
SMTP Password: your-sendgrid-api-key
```

### Local SMTP (No Authentication)
```
SMTP Host: localhost
SMTP Port: 25
SMTP Encryption: none
SMTP Username: (leave empty)
SMTP Password: (leave empty)
```

## Troubleshooting

### Email not sending?

1. **Enable debug mode**:
   - Admin Panel → Settings → Email
   - Enable "SMTP Debug Mode"
   - Try sending email
   - Check server error log

2. **Check your settings**:
   ```bash
   cd backend/includes
   php test_email_service.php
   ```

3. **Common issues**:
   - Wrong port/encryption combination (587=TLS, 465=SSL)
   - Invalid credentials (use app passwords for Gmail/Zoho)
   - Firewall blocking SMTP ports
   - SMTP host not configured

### Still having issues?

See the complete documentation:
- `backend/EMAIL_CONFIGURATION.md` - Detailed configuration guide
- `SMTP_FIX_SUMMARY.md` - Complete fix details

## Testing

After configuration, test the booking form:

1. Visit the public booking page
2. Create a test booking
3. Check if confirmation email arrives
4. Check spam folder if not in inbox

## Need Help?

Run the test script for diagnostic information:
```bash
cd backend/includes
php test_email_service.php
```

The script will:
- Display your current configuration
- Check for common issues
- Allow sending a test email
- Provide troubleshooting guidance
