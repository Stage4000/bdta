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
SMTP Username: your-email@gmail.com
SMTP Password: your-16-character-app-password
```

### Zoho Mail

**Settings:**
```
Email Service: SMTP
SMTP Host: smtp.zoho.com
SMTP Port: 587
SMTP Username: your-email@zoho.com
SMTP Password: your-zoho-password
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
SMTP Username: your-email@outlook.com
SMTP Password: your-outlook-password
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
SMTP Username: apikey
SMTP Password: your-sendgrid-api-key
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
SMTP Username: your-aws-smtp-username
SMTP Password: your-aws-smtp-password
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
SMTP Username: postmaster@your-domain.mailgun.org
SMTP Password: your-mailgun-smtp-password
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

### "SMTP connect() failed" Error

**Possible causes:**
1. Incorrect SMTP host or port
2. Firewall blocking port 587 or 465
3. Wrong username or password

**Solutions:**
- Verify your SMTP settings with your email provider
- Try alternative ports (465 for SSL)
- Check firewall rules: `sudo ufw status`
- Test connectivity: `telnet smtp.gmail.com 587`

### "SMTP Error: Could not authenticate"

**Possible causes:**
1. Wrong password
2. 2FA enabled without app password
3. Account security restrictions

**Solutions:**
- For Gmail: Generate and use App Password
- For Zoho: Use App-Specific Password
- Check if "Less secure app access" needs to be enabled (not recommended)

### Emails Going to Spam

**Solutions:**
1. Verify your domain with SPF, DKIM, and DMARC records
2. Use a proper "From" address that matches your domain
3. Warm up your sending reputation by starting with low volume
4. Use a dedicated email service (SendGrid, Mailgun, etc.)

### PHP mail() Not Working

The default PHP `mail()` function requires:
- A properly configured mail server on your server
- Correct PHP `sendmail_path` configuration
- Open port 25 (often blocked by hosting providers)

**Solution:** Use SMTP instead - it's more reliable and easier to configure.

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
