# Email Configuration Guide

This guide will help you configure email sending for Brook's Dog Training Academy CRM system.

## Overview

The system uses PHPMailer library to send emails reliably. You can configure it to use either:
- **PHP mail()** - Basic email function (not recommended for production)
- **SMTP** - Recommended for production use with any SMTP provider

## Quick Setup

1. Log into the Admin Panel
2. Go to **Settings** → **Email**
3. Choose your email service:
   - For testing: Use `PHP mail()` (default)
   - For production: Use `SMTP` with the configuration below

## SMTP Configuration Examples

### Gmail

**Prerequisites:**
- Enable 2-Factor Authentication on your Google account
- Generate an App Password: https://myaccount.google.com/apppasswords

**Settings:**
```
Email Service: SMTP
SMTP Host: smtp.gmail.com
SMTP Port: 587
SMTP Encryption: tls
SMTP Username: your-email@gmail.com
SMTP Password: your-16-character-app-password
SMTP Debug Mode: Off (enable only for troubleshooting)
```

### Zoho Mail

**Settings:**
```
Email Service: SMTP
SMTP Host: smtp.zoho.com
SMTP Port: 587
SMTP Encryption: tls
SMTP Username: your-email@zoho.com
SMTP Password: your-zoho-password
SMTP Debug Mode: Off (enable only for troubleshooting)
```

**Important for Zoho:**
- Make sure your account is verified
- If using a custom domain, verify the domain in Zoho
- For security, use an App-Specific Password instead of your main password

### Microsoft 365 / Outlook.com

**Settings:**
```
Email Service: SMTP
SMTP Host: smtp-mail.outlook.com
SMTP Port: 587
SMTP Encryption: tls
SMTP Username: your-email@outlook.com
SMTP Password: your-outlook-password
SMTP Debug Mode: Off (enable only for troubleshooting)
```

### SendGrid (Recommended for High Volume)

**Prerequisites:**
- Sign up at https://sendgrid.com
- Create an API key with "Mail Send" permissions

**Settings:**
```
Email Service: SMTP
SMTP Host: smtp.sendgrid.net
SMTP Port: 587
SMTP Encryption: tls
SMTP Username: apikey
SMTP Password: your-sendgrid-api-key
SMTP Debug Mode: Off (enable only for troubleshooting)
```

### Amazon SES

**Prerequisites:**
- AWS Account with SES access
- Verify your sending email address or domain
- Generate SMTP credentials in AWS console

**Settings:**
```
Email Service: SMTP
SMTP Host: email-smtp.us-east-1.amazonaws.com (adjust region as needed)
SMTP Port: 587
SMTP Encryption: tls
SMTP Username: your-aws-smtp-username
SMTP Password: your-aws-smtp-password
SMTP Debug Mode: Off (enable only for troubleshooting)
```

### Mailgun

**Prerequisites:**
- Sign up at https://mailgun.com
- Verify your domain
- Get SMTP credentials from Mailgun dashboard

**Settings:**
```
Email Service: SMTP
SMTP Host: smtp.mailgun.org
SMTP Port: 587
SMTP Encryption: tls
SMTP Username: postmaster@your-domain.mailgun.org
SMTP Password: your-mailgun-smtp-password
SMTP Debug Mode: Off (enable only for troubleshooting)
```

## Testing Your Configuration

After configuring your SMTP settings:

1. Create a test booking from the public booking page
2. Check if you receive the confirmation email
3. If email doesn't arrive, check:
   - SMTP credentials are correct
   - Port 587 is not blocked by your firewall
   - Check server error logs: `tail -f /var/log/apache2/error.log`

## Troubleshooting

### New Email Configuration Settings (v2)

The email system now includes enhanced SMTP configuration:

1. **SMTP Encryption Type**
   - `tls` (default) - Use STARTTLS on port 587
   - `ssl` - Use SSL/TLS on port 465
   - `none` - No encryption (not recommended)

2. **SMTP Debug Mode**
   - Enable this to see detailed SMTP communication logs
   - Logs are written to the server error log
   - Useful for diagnosing connection issues
   - **Remember to disable after troubleshooting**

3. **Optional Authentication**
   - SMTP Username and Password are now optional
   - Leave them empty if your SMTP server doesn't require authentication
   - Authentication is automatically disabled when credentials are not provided

### "SMTP connect() failed" Error

**Possible causes:**
1. Incorrect SMTP host or port
2. Firewall blocking port 587 or 465
3. Wrong encryption type selected
4. Server not responding

**Solutions:**
- Verify your SMTP settings with your email provider
- Try alternative encryption types:
  - If using port 587, select "tls" encryption
  - If using port 465, select "ssl" encryption
- Enable SMTP Debug Mode in settings to see detailed connection logs
- Check firewall rules: `sudo ufw status`
- Test connectivity: `telnet smtp.gmail.com 587`
- Check server error logs for detailed PHPMailer debug output

### "SMTP Error: Could not authenticate"

**Possible causes:**
1. Wrong password
2. 2FA enabled without app password
3. Account security restrictions
4. Authentication enabled but credentials empty

**Solutions:**
- For Gmail: Generate and use App Password
- For Zoho: Use App-Specific Password
- If your SMTP server doesn't require authentication, leave username and password empty
- Enable SMTP Debug Mode to see the exact authentication error
- Check if "Less secure app access" needs to be enabled (not recommended)

### "Connection timed out"

**Possible causes:**
1. Port blocked by firewall or hosting provider
2. Incorrect SMTP host
3. Network connectivity issues

**Solutions:**
- Try alternative ports:
  - Port 587 with TLS encryption
  - Port 465 with SSL encryption
  - Port 25 (often blocked by hosting providers)
- Contact your hosting provider to ensure SMTP ports are not blocked
- Enable SMTP Debug Mode to see where the connection hangs
- Test with telnet: `telnet smtp.example.com 587`

### Emails Going to Spam

**Solutions:**
1. Verify your domain with SPF, DKIM, and DMARC records
2. Use a proper "From" address that matches your domain
3. Warm up your sending reputation by starting with low volume
4. Use a dedicated email service (SendGrid, Mailgun, etc.)
5. Ensure "From" address matches SMTP username for Gmail/Zoho

### PHP mail() Not Working

The default PHP `mail()` function requires:
- A properly configured mail server on your server
- Correct PHP `sendmail_path` configuration
- Open port 25 (often blocked by hosting providers)

**Solution:** Use SMTP instead - it's more reliable and easier to configure.

### Debug Mode Instructions

To enable detailed SMTP debugging:

1. Go to Admin Panel → Settings → Email
2. Enable "SMTP Debug Mode"
3. Try sending a test email or creating a booking
4. Check your server error log for detailed output:
   ```bash
   tail -f /var/log/apache2/error.log
   # or
   tail -f /var/log/nginx/error.log
   # or for PHP-FPM
   tail -f /var/log/php-fpm/error.log
   ```
5. Look for lines starting with "PHPMailer Debug:"
6. **Important:** Disable debug mode after troubleshooting for security

### Migrating Existing Database

If you're upgrading from a previous version, run the migration script:

```bash
cd /path/to/bdta/backend/includes
php migrate_email_settings.php
```

This will add the new email settings (smtp_encryption and smtp_debug) to your database.

## Security Best Practices

1. **Never commit credentials to Git**
   - Settings are stored in the database, not in code
   - Database files are in .gitignore

2. **Use App-Specific Passwords**
   - Don't use your main email password
   - Generate app passwords for Gmail, Zoho, etc.

3. **Restrict Access**
   - Only give SMTP credentials to authorized users
   - Regularly rotate passwords

4. **Enable TLS**
   - Always use port 587 with STARTTLS
   - Or port 465 with SSL/TLS

5. **Monitor Usage**
   - Check email sending logs regularly
   - Set up alerts for failed sends

## Email Features in BDTA

The following features send emails:

1. **Booking Confirmations**
   - Sent when a new booking is created
   - Contains calendar export links (Google Calendar & iCal)
   - Includes appointment details

2. **Password Reset**
   - Sent when user requests password reset
   - Contains secure token link (expires in 1 hour)

3. **Future Features** (can be added):
   - Quote notifications
   - Invoice reminders
   - Appointment reminders
   - Contract signing notifications

## Advanced Configuration

### Custom Email Templates

Email templates are hardcoded in `backend/includes/email_service.php`. To customize:

1. Edit the HTML and text templates in the file
2. Use inline CSS for styling (many email clients strip `<style>` tags)
3. Test with multiple email clients

### Email Logging

To log all email attempts:

```php
// In backend/includes/email_service.php
// Add to sendEmail() method:
error_log("Email sent to: " . $to . " - Subject: " . $subject);
```

### Using SendGrid/Mailgun APIs Directly

While SMTP works for all providers, you can also use API integrations:

1. Install required libraries via Composer
2. Update `email_service.php` to support API mode
3. Add API key configuration in settings

## Support

For issues with email configuration:
1. Check your email provider's documentation
2. Review server error logs
3. Test SMTP connection independently
4. Contact your hosting provider if ports are blocked

## References

- [PHPMailer Documentation](https://github.com/PHPMailer/PHPMailer)
- [Gmail SMTP Settings](https://support.google.com/mail/answer/7126229)
- [Zoho SMTP Settings](https://www.zoho.com/mail/help/zoho-smtp.html)
- [SendGrid Documentation](https://docs.sendgrid.com/)
