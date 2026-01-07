# Brook's Dog Training Academy - Business Management System

## Complete Feature Set

This comprehensive business management system includes:

✅ **Client Management**
- Full CRM with client profiles
- Dog information tracking
- Contact details and notes

✅ **Time Tracking**
- Record billable and non-billable hours
- Automatic duration calculation
- Hourly rate configuration
- Service type tracking
- Link to specific bookings

✅ **Expense Tracking**
- Record business expenses
- Categorize expenses
- Mark billable/non-billable
- Associate with clients
- Receipt file upload support

✅ **Invoicing with Stripe Integration**
- Create professional invoices
- Auto-generate invoice numbers
- Pull in time entries and expenses
- Tax calculation
- Multiple payment methods:
  - **Stripe online payment** (credit/debit cards)
  - **Manual payment** (cash, check, in-person)
- Payment status tracking
- Email invoice delivery
- PDF generation ready

✅ **Contract Management**
- Digital contract creation
- Template system
- Electronic signature capture
- Contract status tracking
- Version control
- Client IP address logging

---

## File Structure

```
backend/
├── admin/
│   ├── clients_list.php          # Client list view
│   ├── clients_edit.php          # Add/edit client
│   ├── time_entries_list.php     # Time tracking list
│   ├── time_entries_edit.php     # Add/edit time entry
│   ├── expenses_list.php         # Expense list
│   ├── expenses_edit.php         # Add/edit expense
│   ├── invoices_list.php         # Invoice list
│   ├── invoices_create.php       # Create/edit invoice
│   ├── invoices_view.php         # View invoice
│   ├── invoices_payment.php      # Record payment
│   ├── contracts_list.php        # Contract list
│   ├── contracts_create.php      # Create/edit contract
│   └── contracts_sign.php        # E-signature interface
├── includes/
│   ├── stripe_config.php         # Stripe API configuration
│   └── database.php              # Database with new tables
└── public/
    └── api_invoices.php          # Invoice payment API
```

---

## Database Schema

### clients
- id, name, email, phone, address
- dog_name, dog_breed
- notes, created_at, updated_at

### time_entries
- id, client_id, booking_id (optional)
- service_type, description
- date, start_time, end_time, duration_minutes
- hourly_rate, total_amount
- billable, invoiced
- created_at

### expenses
- id, client_id (optional)
- category, description, amount
- expense_date, receipt_file
- billable, invoiced, notes
- created_at

### invoices
- id, invoice_number, client_id
- issue_date, due_date
- subtotal, tax_rate, tax_amount, total_amount
- status (draft, sent, paid, overdue, cancelled)
- payment_method, payment_date
- stripe_payment_intent_id
- notes, created_at, updated_at

### invoice_items
- id, invoice_id
- item_type (time, expense, custom)
- reference_id (links to time_entries or expenses)
- description, quantity, rate, amount
- created_at

### contracts
- id, contract_number, client_id
- title, description, contract_text
- status (draft, sent, signed, expired, cancelled)
- created_date, effective_date, expiration_date
- signed_date, signature_data, ip_address
- created_at, updated_at

---

## Quick Start

### 1. Install Stripe PHP SDK (Optional - for online payments)

```bash
cd backend
composer require stripe/stripe-php
```

### 2. Configure Stripe API Keys

Edit `backend/includes/stripe_config.php`:

```php
define('STRIPE_PUBLISHABLE_KEY', 'pk_live_YOUR_KEY');
define('STRIPE_SECRET_KEY', 'sk_live_YOUR_KEY');
```

Get your keys from: https://dashboard.stripe.com/apikeys

### 3. Access Admin Panel

```bash
cd backend
php -S localhost:8000
```

Navigate to: http://localhost:8000/admin/login.php

**Default Login:** admin / admin123

---

## Usage Guide

### Managing Clients

1. **Add Client**: Admin Panel → Clients → Add New Client
2. Fill in client details including dog information
3. Save to database

### Tracking Time

1. **Add Time Entry**: Time Tracking → Add Time Entry
2. Select client and service type
3. Enter date, start/end times, and hourly rate
4. Mark as billable/non-billable
5. System automatically calculates duration and total

### Recording Expenses

1. **Add Expense**: Expenses → Add Expense
2. Enter category (Supplies, Travel, etc.)
3. Enter amount and date
4. Optionally link to a client
5. Mark as billable if client should be charged

### Creating Invoices

1. **Create Invoice**: Invoices → Create Invoice
2. Select client
3. System auto-loads:
   - Unbilled time entries for that client
   - Unbilled billable expenses for that client
4. Review and adjust line items
5. Set tax rate (if applicable)
6. Save as draft or mark as sent

### Processing Payments

#### Option 1: Stripe Online Payment

1. Open invoice → Click "Pay Now"
2. Client enters credit card details
3. Payment processes through Stripe
4. Invoice automatically marked as paid
5. Receipt email sent

#### Option 2: Manual Payment (Cash/Check)

1. Open invoice → Click "Record Payment"
2. Select payment method: Cash, Check, Bank Transfer
3. Enter payment date
4. Mark as paid
5. System updates invoice status

### Contract Management

1. **Create Contract**: Contracts → Create Contract
2. Select client and enter contract details
3. Write or paste contract text
4. Save as draft
5. Send to client for signature
6. Client signs electronically
7. System captures signature, timestamp, and IP
8. Contract marked as signed

---

## Stripe Integration Guide

### Setup Process

1. **Create Stripe Account**: https://dashboard.stripe.com/register
2. **Get API Keys**: Dashboard → Developers → API keys
3. **Test Mode**: Use `pk_test_...` and `sk_test_...` for testing
4. **Live Mode**: Use `pk_live_...` and `sk_live_...` for production

### Payment Flow

```
Client → Invoice View → "Pay Now" Button
    ↓
Stripe Checkout / Payment Intent
    ↓
Enter Card Details
    ↓
Stripe Processes Payment
    ↓
Webhook Confirms Payment
    ↓
Invoice Marked as Paid
```

### Test Cards

```
Success: 4242 4242 4242 4242
Declined: 4000 0000 0000 0002
```

Any future expiry date and any CVC.

---

## API Endpoints

### Invoice Payment API

**Create Payment Intent**
```
POST /backend/public/api_invoices.php
Content-Type: application/json

{
  "action": "create_payment",
  "invoice_id": 123
}

Response:
{
  "success": true,
  "client_secret": "pi_xxx_secret_xxx",
  "publishable_key": "pk_xxx"
}
```

**Record Manual Payment**
```
POST /backend/public/api_invoices.php
Content-Type: application/json

{
  "action": "record_payment",
  "invoice_id": 123,
  "payment_method": "cash",
  "payment_date": "2024-01-15"
}

Response:
{
  "success": true,
  "message": "Payment recorded successfully"
}
```

---

## Security Features

✅ **Payment Security**
- PCI DSS compliant (Stripe handles card data)
- No card numbers stored in database
- Secure payment intent flow
- Webhook signature verification

✅ **Contract Security**
- IP address logging
- Timestamp verification
- Signature image stored as base64
- Audit trail maintained

✅ **General Security**
- Session-based authentication
- Password hashing (bcrypt)
- SQL injection protection (prepared statements)
- XSS protection (output escaping)
- CSRF protection ready

---

## Production Checklist

### Before Launch

- [ ] Change default admin password
- [ ] Set up live Stripe API keys
- [ ] Configure email service (SendGrid/Mailgun)
- [ ] Enable HTTPS (required for Stripe)
- [ ] Set proper file permissions
- [ ] Configure regular database backups
- [ ] Test all payment flows end-to-end
- [ ] Review contract templates
- [ ] Set up Stripe webhooks
- [ ] Configure tax rates

### Stripe Webhook Setup

1. Go to Stripe Dashboard → Developers → Webhooks
2. Add endpoint: `https://yourdomain.com/backend/public/stripe_webhook.php`
3. Select events:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
4. Copy webhook signing secret to config

### Email Configuration

Update `backend/includes/email_service.php` with your SMTP settings or API keys.

---

## Customization

### Invoice Template

Edit `backend/admin/invoices_view.php` to customize:
- Company logo
- Header/footer content
- Color scheme
- Layout

### Contract Templates

Create contract templates in `backend/admin/contracts_create.php`:
- Training agreement
- Liability waiver
- Boarding contract
- Service agreement

### Tax Rates

Default: 0% (no tax)

To enable tax:
1. Edit invoice creation form
2. Set default tax rate (e.g., 7% for sales tax)
3. System auto-calculates tax on invoice total

---

## Support & Troubleshooting

### Common Issues

**Stripe not working?**
- Check API keys are correct
- Ensure HTTPS is enabled
- Verify Composer installed Stripe SDK

**Payments not recording?**
- Check webhook URL is accessible
- Verify webhook signing secret
- Check webhook event logs in Stripe dashboard

**Database errors?**
- Ensure SQLite extension enabled
- Check file permissions on `bdta.db`
- Run database initialization

### Database Backup

```bash
# Backup
cp backend/bdta.db backups/bdta_$(date +%Y%m%d).db

# Restore
cp backups/bdta_20240115.db backend/bdta.db
```

---

## Reporting

### Available Reports

- Time summary by client
- Revenue by service type
- Expense breakdown by category
- Outstanding invoices
- Payment history
- Contract status summary

### Exporting Data

All data can be exported via SQL queries:

```sql
-- Monthly revenue
SELECT SUM(total_amount) FROM invoices 
WHERE status = 'paid' 
AND strftime('%Y-%m', payment_date) = '2024-01';

-- Client time summary
SELECT c.name, SUM(te.duration_minutes)/60 as hours, SUM(te.total_amount) as total
FROM time_entries te
JOIN clients c ON te.client_id = c.id
GROUP BY c.id;
```

---

## Next Steps

1. **Set up clients** - Add your first client
2. **Track time** - Log training sessions
3. **Create invoice** - Generate your first invoice
4. **Test payment** - Use Stripe test mode
5. **Create contract** - Draft training agreement
6. **Go live** - Switch to production mode

**Need help?** Check the README files in each module directory.

---

**Built with:** PHP 7.4+, SQLite3, Stripe API, Bootstrap 5

**License:** Proprietary - Brook's Dog Training Academy

**Version:** 1.0.0 - Complete Business Management System
