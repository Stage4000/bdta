# Invoice Closeout Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let admins extend invoice due dates after send, close partially paid invoices without voiding them, and keep payment/reminder/reporting behavior accurate.

**Architecture:** Keep invoice status and payment/refund math centralized in `backend/includes/invoice_status.php`, then call that logic from the admin invoice view. Add a terminal `settled` status alongside an admin override path that can mark a partially paid invoice as `paid` at the current collected amount. Update dependent UI and dashboard/report queries so they use collected amounts instead of assuming `paid` always means the invoice total was collected.

**Tech Stack:** PHP 8-style typed helper patterns already used in the repo, PDO, Bootstrap 5, standalone PHP regression scripts in `tests/`

---

### Task 1: Lock Down Regression Coverage

**Files:**
- Modify: `tests/test_invoice_payment_progress.php`
- Modify: `tests/test_invoice_reminder.php`
- Create: `tests/test_invoice_admin_closeout.php`

- [ ] **Step 1: Write the failing tests**

Add assertions for:

```php
$manual_paid_invoice = [
    'id' => 404,
    'total_amount' => 300.00,
    'status' => 'paid',
    'payment_method' => 'cash',
];

$summary = bdta_invoice_get_payment_summary($conn, $manual_paid_invoice);
assertInvoicePaymentProgress($summary['paid_total'] === 100.00, 'Expected admin-paid invoices to preserve the actual collected amount.');
assertInvoicePaymentProgress($summary['remaining_amount'] === 0.00, 'Expected admin-paid invoices to stop showing a balance due.');
assertInvoicePaymentProgress($summary['closed_balance_amount'] === 200.00, 'Expected admin-paid invoices to expose the waived balance separately.');
```

and a closeout helper flow like:

```php
$result = bdta_close_invoice_at_current_amount($conn, $invoice_id, 'settled');
assertInvoiceAdminCloseout($result['status'] === 'settled', 'Expected closeout helper to support settled status.');
assertInvoiceAdminCloseout($result['remaining_amount'] === 0.00, 'Expected settled invoices to stop showing an amount due.');
assertInvoiceAdminCloseout($result['paid_total'] === 100.00, 'Expected settled invoices to preserve the recorded payment total.');
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```powershell
& 'C:\Program Files\PHP\current\php.exe' tests\test_invoice_payment_progress.php
& 'C:\Program Files\PHP\current\php.exe' tests\test_invoice_reminder.php
& 'C:\Program Files\PHP\current\php.exe' tests\test_invoice_admin_closeout.php
```

Expected: failures for missing `settled` handling, missing closeout/due-date helpers, or old balance/refund assumptions.

- [ ] **Step 3: Write minimal implementation**

Implement only the helpers required to make the new tests executable:

```php
function bdta_invoice_can_adjust_due_date(array $invoice): bool { /* ... */ }
function bdta_update_invoice_due_date(PDO $conn, int $invoice_id, string $due_date): array { /* ... */ }
function bdta_close_invoice_at_current_amount(PDO $conn, int $invoice_id, string $target_status): array { /* ... */ }
```

- [ ] **Step 4: Run tests to verify they pass**

Run:

```powershell
& 'C:\Program Files\PHP\current\php.exe' tests\test_invoice_payment_progress.php
& 'C:\Program Files\PHP\current\php.exe' tests\test_invoice_reminder.php
& 'C:\Program Files\PHP\current\php.exe' tests\test_invoice_admin_closeout.php
```

Expected: PASS

### Task 2: Update Central Invoice Status and Refund Logic

**Files:**
- Modify: `backend/includes/invoice_status.php`
- Modify: `tests/test_invoice_void_refund.php`

- [ ] **Step 1: Write the failing refund/summary assertions**

Add assertions for:

```php
assertInvoicePaymentProgress($settled_summary['status'] === 'settled', 'Expected settled invoices to keep their terminal status.');
assertInvoicePaymentProgress($settled_summary['remaining_amount'] === 0.00, 'Expected settled invoices to have no client balance due.');
```

and:

```php
if (safe_float($refund_result['remaining_amount']) !== 60.00) {
    throw new RuntimeException('Expected refunds on a partially closed invoice to be capped by collected funds, not invoice total.');
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```powershell
& 'C:\Program Files\PHP\current\php.exe' tests\test_invoice_payment_progress.php
& 'C:\Program Files\PHP\current\php.exe' tests\test_invoice_void_refund.php
```

Expected: FAIL with old refund cap or summary behavior.

- [ ] **Step 3: Write minimal implementation**

Update the helper layer to:

```php
return [
    'paid_total' => $paid_total,
    'remaining_amount' => $balance_due,
    'uncollected_amount' => $uncollected_amount,
    'closed_balance_amount' => $closed_balance_amount,
    'status' => $effective_status,
];
```

and use collected totals, not invoice totals, when limiting refunds and deciding whether a refund is complete.

- [ ] **Step 4: Run tests to verify they pass**

Run:

```powershell
& 'C:\Program Files\PHP\current\php.exe' tests\test_invoice_payment_progress.php
& 'C:\Program Files\PHP\current\php.exe' tests\test_invoice_void_refund.php
```

Expected: PASS

### Task 3: Wire Admin Invoice Actions

**Files:**
- Modify: `client/invoices_view.php`

- [ ] **Step 1: Write the failing behavioral checks**

Use the new helper coverage from Task 1 as the red state for this task, then add view assertions if needed around the new action labels:

```php
assert(str_contains($page, 'Update Due Date'));
assert(str_contains($page, 'Mark Paid at Current Amount'));
assert(str_contains($page, 'Settle / Close Invoice'));
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```powershell
& 'C:\Program Files\PHP\current\php.exe' tests\test_invoice_admin_closeout.php
```

Expected: FAIL if the page or helper entry points are still missing.

- [ ] **Step 3: Write minimal implementation**

Add POST handlers and UI controls that:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_due_date'])) { /* ... */ }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_invoice_at_current_amount'])) { /* ... */ }
```

and show the recalculated paid/closed balance details on the invoice page.

- [ ] **Step 4: Run tests to verify they pass**

Run:

```powershell
& 'C:\Program Files\PHP\current\php.exe' tests\test_invoice_admin_closeout.php
```

Expected: PASS

### Task 4: Correct Dashboard and Notification Consumers

**Files:**
- Modify: `client/index.php`
- Modify: `backend/includes/notifications.php`
- Modify: `backend/includes/database.php`
- Modify: `tests/test_admin_dashboard_enhancements.php`

- [ ] **Step 1: Write the failing checks**

Add assertions that the dashboard uses invoice income events and that sticky notifications/archive behavior exclude `settled`:

```php
assert_dashboard_contains(
    str_contains($dashboard, 'bdta_invoice_get_income_events'),
    'Admin dashboard should base invoice revenue summaries on collected income events.'
);
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```powershell
& 'C:\Program Files\PHP\current\php.exe' tests\test_admin_dashboard_enhancements.php
```

Expected: FAIL with the old dashboard/query assumptions.

- [ ] **Step 3: Write minimal implementation**

Update dashboard counts/activity to use collected payment events and treat `settled` as a closed invoice everywhere client-facing reminders or archive exclusions are derived.

- [ ] **Step 4: Run tests to verify they pass**

Run:

```powershell
& 'C:\Program Files\PHP\current\php.exe' tests\test_admin_dashboard_enhancements.php
```

Expected: PASS
