<?php
/**
 * Handle the return from Stripe Checkout.
 * Verifies the session status and marks the invoice as paid if successful.
 * Supports two auth modes:
 *   - Guest (token): ?token=SECURE_TOKEN&session_id=...  — no portal login required
 *   - Portal session: ?id=INVOICE_ID&session_id=...      — requires portal login
 */
require_once '../backend/includes/config.php';
require_once '../backend/includes/invoice_payment.php';

$db   = new Database();
$conn = $db->getConnection();

$session_id = trim(scalar_string($_GET['session_id'] ?? ''));
$token      = trim(scalar_string($_GET['token'] ?? ''));
$requested_invoice_id = safe_int($_GET['id'] ?? 0);

if (empty($session_id)) {
    // Stripe can drop clients back here without a session_id after direct navigation,
    // stale tabs, or partial URL rewrites. Preserve the auth context instead of
    // forcing guests behind the portal login wall.
    $fallback_location = $requested_invoice_id > 0
        ? 'invoice_view.php?id=' . $requested_invoice_id
        : 'invoices.php';

    if ($token !== '') {
        $fallback_location = 'invoice_pay.php?token=' . urlencode($token);
    }

    header('Location: ' . $fallback_location);
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

// Verify paid returns even if another payment closed the invoice; excess must be retained.
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

if ($http_code !== 200 || array_string_value($session, 'id') !== $session_id) {
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
    || array_string_value($session, 'currency') !== scalar_string(STRIPE_CURRENCY)
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

try {
    $payment_result = bdta_invoice_record_checkout_payment($conn, safe_int($id), $invoice_client_id,
        $amount_total_cents, $session_id, $payment_intent_id, array_string_value($session, 'currency'));
    $invoice = array_merge($invoice, $payment_result['invoice']);
    $invoice_marked_paid = !$payment_result['replayed'] && $payment_result['applied_cents'] > 0 && array_string_value($invoice, 'status') === 'paid';
    if ($payment_result['excess_cents'] > 0) {
        setFlashMessage('Payment received. $' . number_format($payment_result['excess_cents'] / 100, 2)
            . ' exceeded the current invoice balance and has been flagged for reconciliation. Please contact us.', 'warning');
        header('Location: ' . $success_url);
        exit;
    }
    if ($payment_result['replayed']) {
        setFlashMessage('This payment was already recorded.', 'info');
        header('Location: ' . $success_url);
        exit;
    }
} catch (Throwable $e) {
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
    logClientActivity($client_id, 'invoice_paid', 'Paid invoice #' . array_string_value($invoice, 'invoice_number') . ' via Stripe', $conn);
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
