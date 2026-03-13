<?php
/**
 * Create a Stripe Checkout Session for an invoice and redirect to Stripe.
 * Supports two auth modes:
 *   - Guest (token): ?token=SECURE_TOKEN  — no portal login required
 *   - Portal session: ?id=INVOICE_ID     — requires portal login
 */
require_once '../backend/includes/config.php';

$db   = new Database();
$conn = $db->getConnection();

$token = trim(scalar_string($_GET['token'] ?? ''));

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

    if (!$invoice || $invoice['status'] === 'paid') {
        header('Location: invoice_pay.php?token=' . urlencode($token));
        exit;
    }

    $id          = $invoice['id'];
    $cancel_path = 'invoice_pay.php?token=' . urlencode($token);
    $return_path = 'invoice_pay_return.php?token=' . urlencode($token) . '&session_id={CHECKOUT_SESSION_ID}';

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

    if (!$invoice || $invoice['status'] === 'paid') {
        redirect(PORTAL_URL . 'invoice_view.php?id=' . $id);
    }

    $cancel_path = 'invoice_view.php?id=' . $id;
    $return_path = 'invoice_pay_return.php?id=' . $id . '&session_id={CHECKOUT_SESSION_ID}';
}

require_once '../backend/includes/stripe_config.php';

if (!isStripeEnabled()) {
    $redirect = !empty($token)
        ? 'invoice_pay.php?token=' . urlencode($token)
        : PORTAL_URL . 'invoice_view.php?id=' . $id;
    setFlashMessage('Online payments are not currently available. Please contact us to arrange payment.', 'warning');
    header('Location: ' . $redirect);
    exit;
}

$base_url     = getDynamicBaseUrl();
$amount_cents = (int) round(safe_float($invoice['total_amount']) * 100, 0);
$currency     = STRIPE_CURRENCY;
$secret_key   = STRIPE_SECRET_KEY;

// Stripe requires a minimum charge amount (50 cents)
if ($amount_cents < 50) {
    setFlashMessage('Invoice amount is too low for online payment (minimum $0.50). Please contact us to arrange payment.', 'warning');
    header('Location: ' . (!empty($token) ? $base_url . '/portal/invoice_pay.php?token=' . urlencode($token) : $base_url . '/portal/invoice_view.php?id=' . $id));
    exit;
}

$success_url = $base_url . '/portal/' . $return_path;
$cancel_url  = $base_url . '/portal/' . $cancel_path;

// Create a Stripe Checkout Session via the Stripe API (no SDK needed)
$post_data = http_build_query([
    'mode'                          => 'payment',
    'success_url'                   => $success_url,
    'cancel_url'                    => $cancel_url,
    'customer_email'                => $invoice['client_email'],
    'line_items[0][quantity]'       => 1,
    'line_items[0][price_data][currency]'                 => $currency,
    'line_items[0][price_data][unit_amount]'              => $amount_cents,
    'line_items[0][price_data][product_data][name]'       => 'Invoice ' . $invoice['invoice_number'],
    'line_items[0][price_data][product_data][description]'=> 'Payment for invoice ' . $invoice['invoice_number'],
    'metadata[invoice_id]'          => $id,
    'metadata[client_id]'           => $invoice['client_id'],
    'payment_intent_data[metadata][invoice_id]' => $id,
    'payment_intent_data[metadata][client_id]'  => $invoice['client_id'],
    'payment_intent_data[description]'          => 'Invoice ' . $invoice['invoice_number'] . ' — ' . $invoice['client_name'],
]);

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $post_data,
    CURLOPT_USERPWD        => $secret_key . ':',
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);
$response = curl_exec($ch);
if ($response === false) {
    $curl_error = curl_error($ch);
    curl_close($ch);
    error_log("Stripe API request failed (curl): $curl_error");
    setFlashMessage('Could not initiate online payment. Please try again or contact us.', 'danger');
    header('Location: ' . $cancel_url);
    exit;
}
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$session = decode_json_assoc(scalar_string($response));

if ($http_code !== 200 || empty($session['url'])) {
    $session_error = decode_json_assoc($session['error'] ?? []);
    $error      = array_string_value($session_error, 'message', 'Unknown error');
    $error_type = array_string_value($session_error, 'type', 'unknown');
    $error_code = array_string_value($session_error, 'code');
    error_log("Stripe Checkout Session creation failed [$error_type/$error_code]: $error (HTTP $http_code)");
    setFlashMessage('Could not initiate online payment. Please try again or contact us.', 'danger');
    header('Location: ' . $cancel_url);
    exit;
}

// Redirect to Stripe-hosted checkout page
header('Location: ' . array_string_value($session, 'url'));
exit;
