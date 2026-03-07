<?php
/**
 * Handle the return from Stripe Checkout.
 * Verifies the session status and marks the invoice as paid if successful.
 * Supports two auth modes:
 *   - Guest (token): ?token=SECURE_TOKEN&session_id=...  — no portal login required
 *   - Portal session: ?id=INVOICE_ID&session_id=...      — requires portal login
 */
require_once '../backend/includes/config.php';

$db   = new Database();
$conn = $db->getConnection();

$session_id = trim($_GET['session_id'] ?? '');
$token      = trim($_GET['token'] ?? '');

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

    $client_id = intval($_SESSION['portal_client_id']);
    $id        = intval($_GET['id'] ?? 0);

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

// Already paid — nothing to do
if ($invoice['status'] === 'paid') {
    setFlashMessage('This invoice has already been paid.', 'info');
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
    CURLOPT_USERPWD        => $secret_key . ':',
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

$session = json_decode($response, true);

if ($http_code !== 200 || empty($session['id'])) {
    error_log("Stripe session retrieval failed for session $session_id (HTTP $http_code)");
    setFlashMessage('Could not verify payment. If you were charged, please contact us.', 'danger');
    header('Location: ' . $cancel_url);
    exit;
}

if ($session['payment_status'] !== 'paid') {
    // Payment not completed (e.g., user cancelled)
    setFlashMessage('Payment was not completed. You can try again below.', 'warning');
    header('Location: ' . $cancel_url);
    exit;
}

// Payment confirmed — mark invoice as paid
if (empty($session['payment_intent'])) {
    error_log("Stripe session $session_id has no payment_intent despite paid status");
    setFlashMessage('Could not verify payment details. If you were charged, please contact us.', 'danger');
    header('Location: ' . $cancel_url);
    exit;
}
$payment_intent_id = $session['payment_intent'];

$conn->prepare("
    UPDATE invoices
    SET status = 'paid',
        payment_method = 'credit_card',
        payment_date = CURRENT_DATE,
        stripe_payment_intent_id = ?,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = ? AND status != 'paid'
")->execute([$payment_intent_id, $id]);

// Send payment receipt email
require_once '../backend/includes/email_service.php';
$items_stmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$items_stmt->execute([$id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

$email_service = new EmailService(null, $conn);
$result = $email_service->sendPaymentReceipt($invoice, null, $items);
if ($result['success']) {
    $conn->prepare("UPDATE invoices SET receipt_sent_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
}

if ($client_id !== null) {
    // Only log activity for portal-logged-in users (guest doesn't have client_activity_log entry)
    logClientActivity($client_id, 'invoice_paid', 'Paid invoice #' . $invoice['invoice_number'] . ' via Stripe', $conn);
}

setFlashMessage('Payment successful! A receipt has been sent to ' . escape($invoice['client_email']) . '.', 'success');
header('Location: ' . $success_url);
exit;
