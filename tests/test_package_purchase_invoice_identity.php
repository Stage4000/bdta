#!/usr/bin/env php
<?php

// Use only synthetic, in-memory records. Do not open a production database or mail transport.
define('BDTA_TEST_MODE', true);
require_once dirname(__DIR__) . '/backend/includes/settings.php';
Settings::seedCacheForTesting(['timezone' => 'UTC']);
if (session_status() === PHP_SESSION_NONE) {
    session_save_path(sys_get_temp_dir());
    session_start();
    register_shutdown_function(static function (): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    });
}
require_once dirname(__DIR__) . '/backend/includes/package_checkout.php';

function assertPackageInvoiceIdentity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$conn = new SafePDO('sqlite::memory:');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);
$conn->exec('CREATE TABLE invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_number TEXT NOT NULL, client_id INTEGER NOT NULL,
    issue_date TEXT, due_date TEXT, subtotal REAL, tax_rate REAL, tax_amount REAL, total_amount REAL,
    notes TEXT, status TEXT, pay_token TEXT, payment_method TEXT, payment_date TEXT,
    stripe_payment_intent_id TEXT, invoice_sent_at TEXT, receipt_sent_at TEXT, updated_at TEXT
)');
$conn->exec('CREATE TABLE invoice_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER, item_type TEXT, reference_id INTEGER,
    description TEXT, quantity REAL, rate REAL, amount REAL
)');
$conn->exec('CREATE TABLE invoice_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER, amount REAL, payment_date TEXT,
    payment_method TEXT, stripe_payment_intent_id TEXT, notes TEXT
)');
$conn->exec('CREATE TABLE invoice_installments (invoice_id INTEGER, amount REAL, status TEXT)');
$conn->exec('CREATE TABLE client_emails (id INTEGER PRIMARY KEY, subject TEXT)');
$conn->exec("INSERT INTO client_emails VALUES (1, 'Existing synthetic receipt')");

$insert_invoice = $conn->prepare('INSERT INTO invoices (
    invoice_number, client_id, notes, subtotal, total_amount, status, pay_token, receipt_sent_at
) VALUES (?, ?, ?, 25, 25, ?, ?, ?)');
/** @var array<int, int> $invoice_ids */
$invoice_ids = [];
foreach ([1, 10, 100, 1000] as $purchase_id) {
    $insert_invoice->execute([
        'SYNTHETIC-' . $purchase_id, 7,
        bdta_package_purchase_invoice_note($purchase_id, 'Original package name'),
        'draft', 'synthetic-token-' . $purchase_id, '2026-01-01 00:00:00',
    ]);
    $invoice_ids[$purchase_id] = (int) $conn->lastInsertId();
}
$insert_invoice->execute(['OTHER-CLIENT', 8, bdta_package_purchase_invoice_note(1, 'Other client'), 'draft', 'other-client-token', null]);
$other_client_invoice_id = (int) $conn->lastInsertId();

foreach ([1, 10, 100] as $purchase_id) {
    $invoice = bdta_find_package_purchase_invoice($conn, 7, $purchase_id);
    $actual_id = safe_int($invoice['id'] ?? 0);
    assertPackageInvoiceIdentity(
        $actual_id === $invoice_ids[$purchase_id],
        'Purchase #' . $purchase_id . ' must select its exact invoice: expected '
            . $invoice_ids[$purchase_id] . ', actual ' . $actual_id . '.'
    );
}
assertPackageInvoiceIdentity(
    safe_int(bdta_find_package_purchase_invoice($conn, 8, 1)['id'] ?? 0) === $other_client_invoice_id,
    'The same purchase identifier must remain scoped to the requested client.'
);
assertPackageInvoiceIdentity(bdta_find_package_purchase_invoice($conn, 9, 1) === [], 'Another client must not reuse an invoice.');
assertPackageInvoiceIdentity(bdta_find_package_purchase_invoice($conn, 7, 0) === [], 'Invalid purchase identifiers must not match.');

// The package may have been renamed after purchase; match the identifier, not the display name.
$package = ['id' => 42, 'name' => 'Renamed package', 'description' => 'Synthetic training', 'price' => 25];
$before_unrelated = $conn->query('SELECT * FROM invoices WHERE id NOT IN (1, 2, 3) ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$before_mail = $conn->query('SELECT * FROM client_emails ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
foreach ([1, 10, 100] as $purchase_id) {
    foreach ([1, 2] as $attempt) {
        $context = bdta_ensure_package_purchase_invoice(
            $conn, 7, $purchase_id, $package, 'Synthetic buyer', 'buyer@example.invalid',
            'credit_card', 'cs_synthetic_' . $purchase_id,
            $purchase_id === 10 ? null : 'pi_synthetic_' . $purchase_id
        );
        assertPackageInvoiceIdentity(
            safe_int($context['invoice']['id'] ?? 0) === $invoice_ids[$purchase_id],
            'Recovery must reuse the exact invoice for purchase #' . $purchase_id . '.'
        );
        assertPackageInvoiceIdentity(count($context['items']) === 1, 'Retry must not duplicate package invoice items.');
        assertPackageInvoiceIdentity(
            ($context['invoice']['receipt_sent_at'] ?? '') === '2026-01-01 00:00:00',
            'Invoice recovery must preserve an existing receipt timestamp.'
        );
    }
    $stmt = $conn->prepare('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?');
    $stmt->execute([$invoice_ids[$purchase_id]]);
    assertPackageInvoiceIdentity(safe_int($stmt->fetchColumn()) === 1, 'Retry must record payment once for purchase #' . $purchase_id . '.');
}
assertPackageInvoiceIdentity(safe_int($conn->query('SELECT COUNT(*) FROM invoices')->fetchColumn()) === 5, 'Recovery must not create replacement invoices.');
assertPackageInvoiceIdentity(safe_int($conn->query('SELECT COUNT(*) FROM invoice_items')->fetchColumn()) === 3, 'Items must belong only to the three recovered purchases.');
assertPackageInvoiceIdentity(safe_int($conn->query('SELECT COUNT(*) FROM invoice_payments')->fetchColumn()) === 3, 'Payments must belong only to the three recovered purchases.');
// Exclude all deliberately recovered invoices, then compare complete unaffected records.
$after_unrelated = $conn->query('SELECT * FROM invoices WHERE id NOT IN (1, 2, 3) ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
assertPackageInvoiceIdentity($after_unrelated === $before_unrelated, 'Longer purchase identifiers and other clients must remain unchanged.');
assertPackageInvoiceIdentity($conn->query('SELECT * FROM client_emails ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $before_mail, 'Recovery must not queue or send a receipt.');

// Preserve recovery of an exact legacy prefix without a package-name suffix.
$insert_invoice->execute(['LEGACY-EXACT', 11, bdta_package_purchase_invoice_note_prefix(1), 'draft', 'legacy-token', null]);
$legacy_invoice_id = (int) $conn->lastInsertId();
$insert_invoice->execute(['LEGACY-DECOY', 11, bdta_package_purchase_invoice_note(10, 'Decoy'), 'draft', 'legacy-decoy-token', null]);
assertPackageInvoiceIdentity(safe_int(bdta_find_package_purchase_invoice($conn, 11, 1)['id'] ?? 0) === $legacy_invoice_id, 'An exact bare purchase prefix must remain recoverable.');

// A longer identifier cannot suppress creation of a genuinely missing invoice.
$insert_invoice->execute(['MISSING-DECOY', 12, bdta_package_purchase_invoice_note(10, 'Decoy'), 'draft', 'missing-decoy-token', null]);
$decoy_invoice = bdta_invoice_fetch_row($conn, (int) $conn->lastInsertId());
assertPackageInvoiceIdentity(bdta_find_package_purchase_invoice($conn, 12, 1) === [], 'A prefix-only collision must be treated as a missing invoice.');
$created = bdta_ensure_package_purchase_invoice($conn, 12, 1, $package, 'Synthetic buyer', 'buyer@example.invalid', 'offline');
assertPackageInvoiceIdentity(safe_int($created['invoice']['id'] ?? 0) !== safe_int($decoy_invoice['id'] ?? 0), 'A missing exact invoice must be created separately.');
assertPackageInvoiceIdentity(($created['invoice']['status'] ?? '') === 'draft', 'Offline recovery must not mark an invoice paid.');
assertPackageInvoiceIdentity(($created['invoice']['receipt_sent_at'] ?? null) === null, 'Offline recovery must not mark a receipt sent.');
assertPackageInvoiceIdentity(bdta_invoice_fetch_row($conn, safe_int($decoy_invoice['id'] ?? 0)) === $decoy_invoice, 'Creating a missing invoice must leave the longer-identifier invoice unchanged.');
assertPackageInvoiceIdentity(safe_int($conn->query('SELECT COUNT(*) FROM invoice_payments')->fetchColumn()) === 3, 'Offline recovery must not create a payment.');
assertPackageInvoiceIdentity($conn->query('SELECT * FROM client_emails ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $before_mail, 'Offline recovery must not queue or send a receipt.');

echo "Package purchase invoice identity test passed.\n";
