#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') { exit(1); }
define('BDTA_INVOICE_FIXTURE_ONLY', true);
$case = 'helper';
require __DIR__ . '/fixtures/invoice_settlement_request.inc';
/** @var SafePDO $conn */
require_once dirname(__DIR__) . '/backend/includes/invoice_payment.php';

function checkInvoiceAtomicity(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}
function resetInvoiceAtomicity(SafePDO $conn): void
{
    foreach (['invoice_package_fulfillments', 'package_credit_transactions', 'client_package_credits', 'client_packages', 'invoice_checkout_receipts', 'invoice_payments', 'notifications', 'invoice_installments'] as $table) {
        $conn->exec('DELETE FROM ' . $table);
    }
    $conn->exec("UPDATE invoices SET status = 'sent', total_amount = 100, stripe_payment_intent_id = NULL");
    $conn->exec('UPDATE invoice_items SET quantity = 1');
    $conn->exec('UPDATE packages SET is_active = 1');
}

try {
    $result = bdta_invoice_record_checkout_payment($conn, 1, 1, 4000, 'cs_partial', 'pi_partial', 'usd');
    checkInvoiceAtomicity($result['invoice']['status'] === 'partial', 'Partial checkout must preserve partial status.');
    checkInvoiceAtomicity(safe_int($conn->query('SELECT COUNT(*) FROM client_packages')->fetchColumn()) === 0, 'Partial checkout must not fulfill.');
    bdta_invoice_record_manual_payment($conn, 1, 60, 'cash', date('Y-m-d'), 1);
    $replay = bdta_invoice_record_checkout_payment($conn, 1, 1, 4000, 'cs_partial', 'pi_partial', 'usd');
    checkInvoiceAtomicity($replay['replayed'], 'Original partial checkout must replay after manual completion.');
    checkInvoiceAtomicity(safe_int($conn->query('SELECT COUNT(*) FROM client_packages')->fetchColumn()) === 1, 'Mixed manual/online completion must fulfill once.');
    checkInvoiceAtomicity(safe_float($conn->query('SELECT SUM(amount) FROM invoice_payments')->fetchColumn()) === 100.0, 'Replay must preserve collected total.');
    echo "PASS partial checkout, manual completion and return replay\n";

    resetInvoiceAtomicity($conn);
    $conn->exec('UPDATE invoice_items SET quantity = 2');
    bdta_invoice_record_checkout_payment($conn, 1, 1, 10000, 'cs_first', 'pi_first', 'usd');
    bdta_invoice_record_checkout_payment($conn, 1, 1, 10000, 'cs_first', 'pi_first', 'usd');
    $second = bdta_invoice_record_checkout_payment($conn, 1, 1, 10000, 'cs_second', 'pi_second', 'usd');
    checkInvoiceAtomicity($second['applied_cents'] === 0 && $second['excess_cents'] === 10000, 'Second paid checkout must be retained entirely as excess.');
    checkInvoiceAtomicity(safe_int($conn->query('SELECT COUNT(*) FROM invoice_package_fulfillments')->fetchColumn()) === 2, 'Quantity two must have exactly two durable identities.');
    checkInvoiceAtomicity(safe_int($conn->query('SELECT SUM(total_credits) FROM client_package_credits')->fetchColumn()) === 10, 'Quantity and replay must preserve exactly ten credits.');
    foreach ([['cs_first', 'pi_first', 1, 9999], ['cs_first', 'pi_other', 1, 10000], ['cs_other', 'pi_first', 2, 10000]] as [$session, $intent, $client, $cents]) {
        $rejected = false;
        try { bdta_invoice_record_checkout_payment($conn, 1, $client, $cents, $session, $intent, 'usd'); }
        catch (RuntimeException $e) { $rejected = true; }
        checkInvoiceAtomicity($rejected, 'Conflicting replay or changed invoice client must fail.');
    }
    echo "PASS repeated and distinct paid sessions, quantity and identity conflicts\n";

    foreach (['client_package_credits', 'package_credit_transactions', 'invoice_package_fulfillments'] as $fault_table) {
        resetInvoiceAtomicity($conn);
        $conn->exec("CREATE TRIGGER fail_atomicity BEFORE INSERT ON {$fault_table} BEGIN SELECT RAISE(ABORT, 'Synthetic interrupted payment'); END");
        $failed = false;
        try { bdta_invoice_record_checkout_payment($conn, 1, 1, 10000, 'cs_retry', 'pi_retry', 'usd'); }
        catch (PDOException $e) { $failed = true; }
        checkInvoiceAtomicity($failed, 'Injected persistence failure must propagate.');
        foreach (['client_packages', 'client_package_credits', 'package_credit_transactions', 'invoice_payments', 'invoice_checkout_receipts', 'invoice_package_fulfillments', 'notifications'] as $table) {
            checkInvoiceAtomicity(safe_int($conn->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn()) === 0, 'Interrupted transition must roll back ' . $table);
        }
        checkInvoiceAtomicity($conn->query('SELECT status FROM invoices')->fetchColumn() === 'sent', 'Interrupted transition must not mark paid.');
        $conn->exec('DROP TRIGGER fail_atomicity');
        bdta_invoice_record_checkout_payment($conn, 1, 1, 10000, 'cs_retry', 'pi_retry', 'usd');
        bdta_invoice_record_checkout_payment($conn, 1, 1, 10000, 'cs_retry', 'pi_retry', 'usd');
        checkInvoiceAtomicity(safe_int($conn->query('SELECT COUNT(*) FROM client_packages')->fetchColumn()) === 1, 'Successful retry must issue only one package.');
        checkInvoiceAtomicity(safe_int($conn->query('SELECT SUM(total_credits) FROM client_package_credits')->fetchColumn()) === 5, 'Successful retry must issue complete credits.');
        echo 'PASS interrupted ' . $fault_table . " persistence and retry\n";
    }

    resetInvoiceAtomicity($conn);
    $conn->exec('UPDATE packages SET is_active = 0');
    $failed = false;
    try { bdta_invoice_record_manual_payment($conn, 1, 100, 'cash', date('Y-m-d'), 1); }
    catch (RuntimeException $e) { $failed = true; }
    checkInvoiceAtomicity($failed && safe_int($conn->query('SELECT COUNT(*) FROM invoice_payments')->fetchColumn()) === 0, 'Unavailable package must never silently lose fulfillment.');
    $conn->exec('UPDATE packages SET is_active = 1');
    bdta_invoice_record_manual_payment($conn, 1, 100, 'cash', date('Y-m-d'), 1);
    checkInvoiceAtomicity(safe_int($conn->query('SELECT COUNT(*) FROM client_packages')->fetchColumn()) === 1, 'Manual retry must fulfill once.');
    echo "PASS unavailable package fails atomically and manual retry completes\n";

    resetInvoiceAtomicity($conn);
    bdta_invoice_record_manual_payment($conn, 1, 40, 'cash', date('Y-m-d'), 1);
    $failed = false;
    try { bdta_invoice_record_manual_payment($conn, 1, 100, 'cash', date('Y-m-d'), 1); }
    catch (RuntimeException $e) { $failed = true; }
    checkInvoiceAtomicity($failed && safe_float($conn->query('SELECT SUM(amount) FROM invoice_payments')->fetchColumn()) === 40.0, 'Stale manual amount must be rejected against current balance.');
    echo "PASS stale manual balance validation\n";
} finally {
    session_destroy();
}
