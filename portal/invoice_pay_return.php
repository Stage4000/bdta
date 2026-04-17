<?php
/**
 * Handle the return from Stripe Checkout.
 * Verifies the session status and marks the invoice as paid if successful.
 * Supports two auth modes:
 *   - Guest (token): ?token=SECURE_TOKEN&session_id=...  — no portal login required
 *   - Portal session: ?id=INVOICE_ID&session_id=...      — requires portal login
 */
require_once '../backend/includes/config.php';
require_once '../backend/includes/invoice_status.php';

$db   = new Database();
$conn = $db->getConnection();

$session_id = trim(scalar_string($_GET['session_id'] ?? ''));
$token      = trim(scalar_string($_GET['token'] ?? ''));

if (empty($session_id)) {
    // No session_id — likely direct navigation, just redirect home
    header('Location: /portal/invoices.php');
    exit;
}

if (!empty($token)) {
    // ── Guest flow: authenticate by pay_token ──────────────────────────────
    $stmt = $conn->prepare("
        SELECT i.*, c.name as client_name, c.email as client_email
        FROM invoices i
        JOIN clients c ON i.client_id = c.id
        WHERE i.pay_token = ?
    ");
    $stmt->execute([$token]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        http_response_code(404);
        die('Invoice not found.');
    }

    $id            = $invoice['id'];
    $client_id     = null;
    $cancel_url    = 'invoice_pay.php?token=' . urlencode($token);
    $success_url   = 'invoice_pay.php?token=' . urlencode($token);

} else {
    // ── Portal session flow: authenticate by session ───────────────────────
    require_once '../backend/includes/config.php';
    requirePortalLogin();

    $client_id = portalClientId();
    $id        = safe_int($_GET['id'] ?? 0);

    if ($id <= 0) {
        redirect(PORTAL_URL . 'invoices.php');
    }

    $stmt = $conn->prepare("
        SELECT i.*, c.name as client_name, c.email as client_email
        FROM invoices i
        JOIN clients c ON i.client_id = c.id
        WHERE i.id = ? AND i.client_id = ?
    ");
    $stmt->execute([$id, $client_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        redirect(PORTAL_URL . 'invoices.php');
    }

    $cancel_url  = PORTAL_URL . 'invoice_view.php?id=' . $id;
    $success_url = PORTAL_URL . 'invoice_view.php?id=' . $id;
}

// Already settled — nothing to do
if (!bdta_invoice_is_payable($invoice)) {
    setFlashMessage('This invoice is no longer payable.', 'info');
    header('Location: ' . $success_url);
    exit;
}

require_once '../backend/includes/stripe_config.php';

if (!isStripeEnabled()) {
    header('Location: ' . $cancel_url);
    exit;
}

$secret_key = STRIPE_SECRET_KEY;

// Retrieve the Checkout Session from Stripe to verify payment status
$ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($session_id));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => scalar_string($secret_key) . ':',
]);
$response = curl_exec($ch);
if ($response === false) {
    $curl_error = curl_error($ch);
    curl_close($ch);
    error_log("Stripe session retrieval curl failed: $curl_error");
    setFlashMessage('Could not verify payment. If you were charged, please contact us.', 'danger');
    header('Location: ' . $cancel_url);
    exit;
}
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$session = decode_json_assoc(scalar_string($response));

if ($http_code !== 200 || empty($session['id'])) {
    error_log("Stripe session retrieval failed for session $session_id (HTTP $http_code)");
    setFlashMessage('Could not verify payment. If you were charged, please contact us.', 'danger');
    header('Location: ' . $cancel_url);
    exit;
}

if (array_string_value($session, 'payment_status') !== 'paid') {
    // Payment not completed (e.g., user cancelled)
    setFlashMessage('Payment was not completed. You can try again below.', 'warning');
    header('Location: ' . $cancel_url);
    exit;
}

// Payment confirmed — mark invoice as paid
if (array_string_value($session, 'payment_intent') === '') {
    error_log("Stripe session $session_id has no payment_intent despite paid status");
    setFlashMessage('Could not verify payment details. If you were charged, please contact us.', 'danger');
    header('Location: ' . $cancel_url);
    exit;
}
$session_metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
$payment_intent_id = array_string_value($session, 'payment_intent');
$session_invoice_id = safe_int($session_metadata['invoice_id'] ?? 0);
$session_client_id = safe_int($session_metadata['client_id'] ?? 0);
$session_amount_cents = safe_int($session_metadata['payment_amount_cents'] ?? 0);
$invoice_client_id = safe_int($invoice['client_id'] ?? 0);
$amount_total_cents = safe_int($session['amount_total'] ?? 0);
$payment_amount = round(safe_int($session['amount_total'] ?? 0) / 100, 2);

if (
    $session_invoice_id !== safe_int($id)
    || $session_client_id !== $invoice_client_id
    || $session_amount_cents <= 0
    || $session_amount_cents !== $amount_total_cents
) {
    error_log("Stripe session $session_id metadata mismatch for invoice $id");
    setFlashMessage('Could not verify that this payment belongs to the requested invoice. Please contact us if you were charged.', 'danger');
    header('Location: ' . $cancel_url);
    exit;
}

if ($payment_amount <= 0) {
    error_log("Stripe session $session_id returned a non-positive amount_total");
    setFlashMessage('Could not verify payment amount. If you were charged, please contact us.', 'danger');
    header('Location: ' . $cancel_url);
    exit;
}

$existing_payment_stmt = $conn->prepare("
    SELECT invoice_id
    FROM invoice_payments
    WHERE stripe_payment_intent_id = ?
    LIMIT 1
");
$existing_payment_stmt->execute([$payment_intent_id]);
$existing_payment_invoice_id = safe_int($existing_payment_stmt->fetchColumn());

if ($existing_payment_invoice_id > 0) {
    if ($existing_payment_invoice_id !== safe_int($id)) {
        error_log("Stripe payment intent $payment_intent_id already recorded for invoice $existing_payment_invoice_id");
        setFlashMessage('This payment was already recorded for a different invoice. Please contact us.', 'danger');
        header('Location: ' . $cancel_url);
        exit;
    }

    setFlashMessage('This payment was already recorded.', 'info');
    header('Location: ' . $success_url);
    exit;
}

$conn->beginTransaction();

try {
    $conn->prepare("
        INSERT INTO invoice_payments (invoice_id, amount, payment_date, payment_method, stripe_payment_intent_id, notes)
        VALUES (?, ?, CURRENT_DATE, 'credit_card', ?, ?)
    ")->execute([$id, $payment_amount, $payment_intent_id, 'Stripe Checkout session ' . $session_id]);

    $updated_summary = bdta_invoice_get_payment_summary($conn, $invoice);
    $invoice_update = $conn->prepare("
        UPDATE invoices
        SET status = ?,
            payment_method = 'credit_card',
            payment_date = CURRENT_DATE,
            stripe_payment_intent_id = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ? AND status NOT IN ('paid', 'refunded', 'void', 'cancelled')
    ");
    $invoice_update->execute([array_string_value($updated_summary, 'status', 'paid'), $payment_intent_id, $id]);
    $invoice_marked_paid = $invoice_update->rowCount() > 0;
    $invoice['status'] = array_string_value($updated_summary, 'status', 'paid');
    $invoice['payment_method'] = 'credit_card';
    $invoice['payment_date'] = date('Y-m-d');
    $invoice['stripe_payment_intent_id'] = $payment_intent_id;

    $conn->commit();
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
        setFlashMessage('This payment was already recorded.', 'info');
        header('Location: ' . $success_url);
        exit;
    }

    error_log('Failed to record Stripe invoice payment: ' . $e->getMessage());
    setFlashMessage('Payment was received but could not be recorded automatically. Please contact us.', 'danger');
    header('Location: ' . $cancel_url);
    exit;
}

// Send payment receipt email
require_once '../backend/includes/email_service.php';
$items_stmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$items_stmt->execute([$id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

if (array_string_value($invoice, 'status') === 'paid') {
    $email_service = new EmailService(null, $conn);
    $result = $email_service->sendPaymentReceipt($invoice, null, $items);
    if ($result['success']) {
        $conn->prepare("UPDATE invoices SET receipt_sent_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
    }
}

if ($client_id !== null) {
    // Only log activity for portal-logged-in users (guest doesn't have client_activity_log entry)
    logClientActivity($client_id, 'invoice_paid', 'Paid invoice #' . $invoice['invoice_number'] . ' via Stripe', $conn);
}

if ($invoice_marked_paid) {
    bdta_create_admin_notifications(
        $conn,
        'invoice',
        safe_int($id),
        'Invoice paid',
        'Invoice #' . array_string_value($invoice, 'invoice_number') . ' was paid by ' . array_string_value($invoice, 'client_name', array_string_value($invoice, 'client_email')),
        '/client/invoices_view.php?id=' . $id
    );
}

setFlashMessage('Payment successful! A receipt has been sent to ' . escape($invoice['client_email']) . '.', 'success');
header('Location: ' . $success_url);
exit;
