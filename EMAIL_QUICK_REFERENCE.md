# Quick Email Setup Reference Card

## Most Common Email Providers

### Gmail
```
Email Service: SMTP
Host: smtp.gmail.com
Port: 587
Username: your-email@gmail.com
Password: [16-char App Password from https://myaccount.google.com/apppasswords]
```
⚠️ **Must enable 2FA and generate App Password**

---

### Zoho Mail
```
Email Service: SMTP
Host: smtp.zoho.com
Port: 587
Username: your-email@zoho.com
Password: [App-Specific Password from Zoho Mail settings]
```
⚠️ **Use App-Specific Password, not main password**

---

### Microsoft Outlook.com / Office 365
```
Email Service: SMTP
Host: smtp-mail.outlook.com  (or smtp.office365.com for business)
Port: 587
Username: your-email@outlook.com
Password: your-outlook-password
```

---

### Yahoo Mail
```
Email Service: SMTP
Host: smtp.mail.yahoo.com
Port: 587
Username: your-email@yahoo.com
Password: [App Password from Yahoo Account Security]
```
⚠️ **Must generate App Password**

---

### SendGrid (Recommended for Production)
```
Email Service: SMTP
Host: smtp.sendgrid.net
Port: 587
Username: apikey
Password: [Your SendGrid API Key]
```
💡 **Free tier: 100 emails/day**

---

### Mailgun
```
Email Service: SMTP
Host: smtp.mailgun.org
Port: 587
Username: postmaster@your-domain.mailgun.org
Password: [Your Mailgun SMTP password]
```
💡 **Free tier: 100 emails/day**

---

### Amazon SES
```
Email Service: SMTP
Host: email-smtp.us-east-1.amazonaws.com
Port: 587
Username: [AWS SMTP Username]
Password: [AWS SMTP Password]
```
💡 **Very cheap: $0.10 per 1,000 emails**

---

## Quick Testing

After configuration:
1. Visit: `http://localhost:8000/backend/public/test_email.php`
2. Enter your email address
3. Send test email
4. Check inbox (and spam folder)

## Common Ports

- **587** - STARTTLS (recommended)
- **465** - SSL/TLS (alternative)
- **25** - Plain (often blocked)

## Troubleshooting

| Error | Solution |
|-------|----------|
| SMTP connect() failed | Check host/port, verify firewall |
| Could not authenticate | Use App Password, verify credentials |
| Connection timeout | Port blocked by host, try 465 |
| Email in spam | Configure SPF/DKIM records |

## Security Notes

✅ **DO:**
- Use App Passwords (not main passwords)
- Enable 2-Factor Authentication
- Use port 587 with STARTTLS
- Delete test_email.php after testing

❌ **DON'T:**
- Commit credentials to Git
- Share SMTP passwords
- Use "less secure apps" options
- Leave test scripts accessible

## Need More Help?

See detailed documentation:
- `backend/EMAIL_CONFIGURATION.md` - Complete setup guide
- `README.md` - Troubleshooting section
- `EMAIL_FIX_SUMMARY.md` - Implementation details

---

**Brook's Dog Training Academy CRM**
Email System powered by PHPMailer v6.9.1
