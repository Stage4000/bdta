<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/email_service.php';
require_once __DIR__ . '/form_types.php';
require_once __DIR__ . '/invoice_status.php';
require_once __DIR__ . '/workflow_helper.php';

/**
 * @return list<array<string, mixed>>
 */
function bdta_package_checkout_fields(mixed $fields): array
{
    if (is_string($fields)) {
        $decoded = json_decode($fields, true);
        $fields = is_array($decoded) ? $decoded : [];
    }

    if (!is_array($fields)) {
        return [];
    }

    $normalized = [];
    foreach ($fields as $field) {
        if (is_array($field)) {
            $normalized[] = $field;
        }
    }

    return $normalized;
}

/**
 * @param array<string, mixed> $form
 */
function bdta_package_form_is_checkout_eligible(array $form): bool
{
    if (safe_int($form['is_active'] ?? 0) !== 1) {
        return false;
    }

    if (safe_int($form['is_internal'] ?? 0) === 1) {
        return false;
    }

    $form_type = bdta_normalize_form_type(scalar_string($form['form_type'] ?? 'client_form'));
    return bdta_form_type_forced_internal($form_type) !== 1;
}

/**
 * @return array{forms: list<array<string, mixed>>, selected_form: array<string, mixed>|null, selected_form_is_valid: bool}
 */
function bdta_get_package_checkout_form_options(SafePDO $conn, int $selected_form_template_id = 0): array
{
    $query = "
        SELECT id, name, form_type, is_active, COALESCE(is_internal, 0) AS is_internal
        FROM form_templates
        WHERE is_active = 1
    ";
    $params = [];
    if ($selected_form_template_id > 0) {
        $query = "
            SELECT id, name, form_type, is_active, COALESCE(is_internal, 0) AS is_internal
            FROM form_templates
            WHERE is_active = 1 OR id = ?
        ";
        $params[] = $selected_form_template_id;
    }

    $query .= " ORDER BY name";

    $stmt = $conn->prepare($query);
    $stmt->execute($params);

    $forms = [];
    $selected_form = null;
    $selected_form_is_valid = ($selected_form_template_id <= 0);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $form) {
        $form_id = safe_int($form['id'] ?? 0);
        if ($form_id === $selected_form_template_id) {
            $selected_form = $form;
        }

        if (!bdta_package_form_is_checkout_eligible($form)) {
            continue;
        }

        if ($form_id === $selected_form_template_id) {
            $selected_form_is_valid = true;
        }

        $forms[] = $form;
    }

    return [
        'forms' => $forms,
        'selected_form' => $selected_form,
        'selected_form_is_valid' => $selected_form_is_valid,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function bdta_get_package_attached_form(SafePDO $conn, int $form_template_id): ?array
{
    if ($form_template_id <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT id, name, description, fields, form_type, is_active, COALESCE(is_internal, 0) AS is_internal,
               required_frequency, appointment_type_id
        FROM form_templates
        WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$form_template_id]);
    /** @var array<string, mixed>|false $form */
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($form)) {
        return null;
    }

    if (!bdta_package_form_is_checkout_eligible($form)) {
        return null;
    }

    $form_type = bdta_normalize_form_type(scalar_string($form['form_type'] ?? 'client_form'));
    $form['form_type'] = $form_type;
    $form['fields'] = bdta_package_checkout_fields($form['fields'] ?? []);

    return $form;
}

/**
 * @return array{id: int, name: string, phone: string}|null
 */
function bdta_find_package_checkout_client_by_email(SafePDO $conn, string $buyer_email): ?array
{
    $normalized_email = strtolower(trim($buyer_email));
    if ($normalized_email === '') {
        return null;
    }

    $client_lookup = $conn->prepare("
        SELECT id, name, phone
        FROM clients
        WHERE LOWER(email) = ?
        ORDER BY updated_at DESC, created_at DESC, id DESC
        LIMIT 1
    ");
    $client_lookup->execute([$normalized_email]);
    $existing_client = $client_lookup->fetch(PDO::FETCH_ASSOC);

    if (!is_array($existing_client)) {
        return null;
    }

    $client_id = safe_int($existing_client['id'] ?? 0);
    if ($client_id <= 0) {
        return null;
    }

    return [
        'id' => $client_id,
        'name' => scalar_string($existing_client['name'] ?? ''),
        'phone' => scalar_string($existing_client['phone'] ?? ''),
    ];
}

/**
 * @param array<string, mixed>|null $form
 * @return array{form_due: bool, client_id: int, skip_message: string}
 */
function bdta_get_package_checkout_form_state(SafePDO $conn, ?array $form, string $buyer_email): array
{
    if (!$form) {
        return [
            'form_due' => false,
            'client_id' => 0,
            'skip_message' => '',
        ];
    }

    $normalized_email = strtolower(trim($buyer_email));
    if ($normalized_email === '' || !filter_var($normalized_email, FILTER_VALIDATE_EMAIL)) {
        return [
            'form_due' => true,
            'client_id' => 0,
            'skip_message' => '',
        ];
    }

    $existing_client = bdta_find_package_checkout_client_by_email($conn, $normalized_email);
    if (!is_array($existing_client)) {
        return [
            'form_due' => true,
            'client_id' => 0,
            'skip_message' => '',
        ];
    }

    $client_id = $existing_client['id'];
    $appointment_type_id = array_int_value($form, 'appointment_type_id');
    $form_due = bdta_form_template_needs_completion($conn, $form, $client_id, $appointment_type_id);
    if ($form_due) {
        return [
            'form_due' => true,
            'client_id' => $client_id,
            'skip_message' => '',
        ];
    }

    return [
        'form_due' => false,
        'client_id' => $client_id,
        'skip_message' => 'Your ' . array_string_value($form, 'name', 'required form') . ' submission is already on file and still current. No re-submission is needed for this package purchase.',
    ];
}

/**
 * @param array<string, mixed>|null $form
 * @param array<int|string, mixed> $submitted_values
 * @return array{responses: array<int, mixed>, errors: list<string>}
 */
function bdta_validate_package_form_submission(?array $form, array $submitted_values): array
{
    if (!$form) {
        return ['responses' => [], 'errors' => []];
    }

    /** @var list<array<string, mixed>> $fields */
    $fields = bdta_package_checkout_fields($form['fields'] ?? []);
    $responses = [];
    $errors = [];

    foreach ($fields as $index => $field) {
        $field_label = trim(scalar_string($field['label'] ?? 'Field ' . ($index + 1)));
        $field_type = trim(scalar_string($field['type'] ?? 'text'));
        $required = !empty($field['required']);
        $value = $submitted_values[$index] ?? null;

        if (bdta_form_field_is_display_only($field)) {
            continue;
        }

        if ($field_type === 'checkbox') {
            $normalized_values = [];
            if (is_array($value)) {
                foreach ($value as $selected_value) {
                    $selected_string = trim(scalar_string($selected_value));
                    if ($selected_string !== '') {
                        $normalized_values[] = $selected_string;
                    }
                }
            } elseif ($value !== null) {
                $selected_string = trim(scalar_string($value));
                if ($selected_string !== '') {
                    $normalized_values[] = $selected_string;
                }
            }

            if ($required && $normalized_values === []) {
                $errors[] = $field_label . ' is required.';
            }
            $responses[$index] = $normalized_values;
            continue;
        }

        $normalized_value = trim(scalar_string($value ?? ''));
        if ($required && $normalized_value === '') {
            $errors[] = $field_label . ' is required.';
        }
        $responses[$index] = $normalized_value;
    }

    return ['responses' => $responses, 'errors' => $errors];
}

/**
 * @param array<int|string, mixed> $form_responses
 * @return array<int, mixed>
 */
function bdta_reindex_pending_package_form_responses(array $form_responses): array
{
    $normalized = [];
    foreach ($form_responses as $key => $value) {
        $normalized[safe_int($key)] = $value;
    }
    ksort($normalized);

    return $normalized;
}

/**
 * @param array<int, mixed> $form_responses
 */
function bdta_store_pending_package_purchase(
    SafePDO $conn,
    int $package_id,
    string $package_token,
    string $stripe_checkout_session_id,
    string $buyer_name,
    string $buyer_email,
    string $buyer_phone = '',
    string $notes = '',
    array $form_responses = [],
    ?int $view_id = null
): void {
    if ($package_id <= 0 || $package_token === '' || $stripe_checkout_session_id === '') {
        throw new InvalidArgumentException('Package, token, and Stripe checkout session are required.');
    }

    $buyer_name = trim($buyer_name);
    $buyer_email = strtolower(trim($buyer_email));
    if ($buyer_name === '' || $buyer_email === '') {
        throw new InvalidArgumentException('Pending purchase buyer details are required.');
    }

    $encoded_form_responses = json_encode($form_responses);
    if ($encoded_form_responses === false) {
        throw new RuntimeException('Unable to encode pending package form responses.');
    }

    $existing_stmt = $conn->prepare("
        SELECT id
        FROM package_pending_purchases
        WHERE package_id = ? AND stripe_checkout_session_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $existing_stmt->execute([$package_id, $stripe_checkout_session_id]);
    $existing_pending_purchase = $existing_stmt->fetch(PDO::FETCH_ASSOC);

    if (is_array($existing_pending_purchase) && safe_int($existing_pending_purchase['id'] ?? 0) > 0) {
        $conn->prepare("
            UPDATE package_pending_purchases
            SET package_token = ?, buyer_name = ?, buyer_email = ?, buyer_phone = ?, notes = ?, form_responses = ?, view_id = ?
            WHERE id = ?
        ")->execute([
            $package_token,
            $buyer_name,
            $buyer_email,
            trim($buyer_phone) !== '' ? trim($buyer_phone) : null,
            trim($notes) !== '' ? trim($notes) : null,
            $encoded_form_responses,
            $view_id !== null && $view_id > 0 ? $view_id : null,
            safe_int($existing_pending_purchase['id'] ?? 0),
        ]);

        return;
    }

    $conn->prepare("
        INSERT INTO package_pending_purchases
            (package_id, package_token, stripe_checkout_session_id, buyer_name, buyer_email, buyer_phone, notes, form_responses, view_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $package_id,
        $package_token,
        $stripe_checkout_session_id,
        $buyer_name,
        $buyer_email,
        trim($buyer_phone) !== '' ? trim($buyer_phone) : null,
        trim($notes) !== '' ? trim($notes) : null,
        $encoded_form_responses,
        $view_id !== null && $view_id > 0 ? $view_id : null,
    ]);
}

/**
 * @return array{package_id: int, package_token: string, stripe_checkout_session_id: string, buyer_name: string, buyer_email: string, buyer_phone: string, notes: string, form_responses: array<int, mixed>, view_id: int}|null
 */
function bdta_get_pending_package_purchase(SafePDO $conn, int $package_id, string $stripe_checkout_session_id): ?array
{
    if ($package_id <= 0 || $stripe_checkout_session_id === '') {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT package_id, package_token, stripe_checkout_session_id, buyer_name, buyer_email, buyer_phone, notes, form_responses, view_id
        FROM package_pending_purchases
        WHERE package_id = ? AND stripe_checkout_session_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([$package_id, $stripe_checkout_session_id]);
    $pending_purchase = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($pending_purchase)) {
        return null;
    }

    $decoded_form_responses = json_decode(scalar_string($pending_purchase['form_responses'] ?? ''), true);

    return [
        'package_id' => safe_int($pending_purchase['package_id'] ?? 0),
        'package_token' => scalar_string($pending_purchase['package_token'] ?? ''),
        'stripe_checkout_session_id' => scalar_string($pending_purchase['stripe_checkout_session_id'] ?? ''),
        'buyer_name' => scalar_string($pending_purchase['buyer_name'] ?? ''),
        'buyer_email' => scalar_string($pending_purchase['buyer_email'] ?? ''),
        'buyer_phone' => scalar_string($pending_purchase['buyer_phone'] ?? ''),
        'notes' => scalar_string($pending_purchase['notes'] ?? ''),
        'form_responses' => bdta_reindex_pending_package_form_responses(is_array($decoded_form_responses) ? $decoded_form_responses : []),
        'view_id' => safe_int($pending_purchase['view_id'] ?? 0),
    ];
}

function bdta_delete_pending_package_purchase(SafePDO $conn, int $package_id, string $stripe_checkout_session_id): void
{
    if ($package_id <= 0 || $stripe_checkout_session_id === '') {
        return;
    }

    $conn->prepare("
        DELETE FROM package_pending_purchases
        WHERE package_id = ? AND stripe_checkout_session_id = ?
    ")->execute([$package_id, $stripe_checkout_session_id]);
}

function bdta_generate_package_invoice_number(SafePDO $conn): string
{
    $stmt = $conn->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_number = ?");
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt->execute([$invoice_number]);
        if (safe_int($stmt->fetchColumn()) === 0) {
            return $invoice_number;
        }
    }

    $invoice_number = 'INV-' . date('Ymd') . '-' . bin2hex(random_bytes(8));
    $stmt->execute([$invoice_number]);
    if (safe_int($stmt->fetchColumn()) > 0) {
        throw new RuntimeException('Unable to generate a unique invoice number for the package purchase.');
    }

    return $invoice_number;
}

/**
 * @return array{name: string, email: string}
 */
function bdta_get_package_checkout_client_contact(SafePDO $conn, int $client_id): array
{
    if ($client_id <= 0) {
        return ['name' => '', 'email' => ''];
    }

    $stmt = $conn->prepare("SELECT name, email FROM clients WHERE id = ? LIMIT 1");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client)) {
        return ['name' => '', 'email' => ''];
    }

    return [
        'name' => scalar_string($client['name'] ?? ''),
        'email' => strtolower(trim(scalar_string($client['email'] ?? ''))),
    ];
}

function bdta_build_package_invoice_description(array $package): string
{
    $package_name = trim(scalar_string($package['name'] ?? 'Package'));
    $package_description = trim(scalar_string($package['description'] ?? ''));

    if ($package_name === '') {
        $package_name = 'Package';
    }

    return $package_description !== ''
        ? $package_name . ' — ' . $package_description
        : $package_name;
}

function bdta_package_purchase_invoice_note_prefix(int $client_package_id): string
{
    return 'Auto-generated for package purchase #' . $client_package_id;
}

function bdta_package_purchase_invoice_note(int $client_package_id, string $package_name): string
{
    return bdta_package_purchase_invoice_note_prefix($client_package_id) . ' (' . $package_name . ')';
}

/**
 * @return array<string, mixed>
 */
function bdta_find_package_purchase_invoice(SafePDO $conn, int $client_id, int $client_package_id): array
{
    if ($client_id <= 0 || $client_package_id <= 0) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT *
        FROM invoices
        WHERE client_id = ?
          AND notes LIKE ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([
        $client_id,
        bdta_package_purchase_invoice_note_prefix($client_package_id) . '%',
    ]);

    return assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
}

/**
 * @return list<array<string, mixed>>
 */
function bdta_get_package_invoice_items(SafePDO $conn, int $invoice_id): array
{
    if ($invoice_id <= 0) {
        return [];
    }

    $stmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
    $stmt->execute([$invoice_id]);

    return assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function bdta_mark_package_invoice_sent(SafePDO $conn, int $invoice_id): void
{
    if ($invoice_id <= 0) {
        return;
    }

    $conn->prepare("
        UPDATE invoices
        SET
            status = CASE WHEN status = 'draft' THEN 'sent' ELSE status END,
            invoice_sent_at = COALESCE(invoice_sent_at, CURRENT_TIMESTAMP)
        WHERE id = ?
    ")->execute([$invoice_id]);
}

function bdta_mark_package_receipt_sent(SafePDO $conn, int $invoice_id): void
{
    if ($invoice_id <= 0) {
        return;
    }

    $conn->prepare("
        UPDATE invoices
        SET receipt_sent_at = COALESCE(receipt_sent_at, CURRENT_TIMESTAMP)
        WHERE id = ?
    ")->execute([$invoice_id]);
}

/**
 * @param array<string, mixed> $invoice
 * @param list<array<string, mixed>> $invoice_items
 */
function bdta_send_package_purchase_email(
    SafePDO $conn,
    array $invoice,
    array $invoice_items,
    bool $payment_confirmed
): void {
    $invoice_id = safe_int($invoice['id'] ?? 0);
    if ($invoice_id <= 0) {
        return;
    }

    $email_service = new EmailService(null, $conn);
    if ($payment_confirmed) {
        if (trim(scalar_string($invoice['receipt_sent_at'] ?? '')) !== '') {
            return;
        }

        $receipt_result = $email_service->sendPaymentReceipt($invoice, null, $invoice_items);
        if (!empty($receipt_result['success'])) {
            bdta_mark_package_receipt_sent($conn, $invoice_id);
        }

        return;
    }

    if (!bdta_invoice_is_payable($invoice) || trim(scalar_string($invoice['invoice_sent_at'] ?? '')) !== '') {
        return;
    }

    $invoice_result = $email_service->sendInvoiceEmail($invoice, $invoice_items);
    if (!empty($invoice_result['success'])) {
        bdta_mark_package_invoice_sent($conn, $invoice_id);
    }
}

/**
 * @param array<string, mixed> $package
 * @return array{invoice: array<string, mixed>, items: list<array<string, mixed>>, payment_confirmed: bool}
 */
function bdta_ensure_package_purchase_invoice(
    SafePDO $conn,
    int $client_id,
    int $client_package_id,
    array $package,
    string $client_name,
    string $client_email,
    ?string $payment_method = null,
    ?string $stripe_checkout_session_id = null,
    ?string $stripe_payment_intent_id = null
): array {
    if ($client_id <= 0 || $client_package_id <= 0) {
        throw new InvalidArgumentException('Client and package purchase identifiers are required to create a package invoice.');
    }

    $package_name = trim(scalar_string($package['name'] ?? 'Package'));
    if ($package_name === '') {
        $package_name = 'Package';
    }

    $package_price = round(max(0, safe_float($package['price'] ?? 0)), 2);
    $payment_method = trim((string) $payment_method);
    $payment_confirmed = ($payment_method === 'credit_card');
    $invoice_is_paid = $payment_confirmed || $package_price <= 0.0;
    $invoice_payment_method = $payment_confirmed ? 'credit_card' : null;
    $invoice_payment_date = $payment_confirmed ? date('Y-m-d') : null;
    $invoice_note = bdta_package_purchase_invoice_note($client_package_id, $package_name);

    $invoice = bdta_find_package_purchase_invoice($conn, $client_id, $client_package_id);
    $invoice_id = safe_int($invoice['id'] ?? 0);
    if ($invoice_id <= 0) {
        $invoice_number = bdta_generate_package_invoice_number($conn);
        $issue_date = date('Y-m-d');
        $due_date = $issue_date;
        $pay_token = bdta_generate_invoice_pay_token();

        $stmt = $conn->prepare("
            INSERT INTO invoices (
                invoice_number,
                client_id,
                issue_date,
                due_date,
                subtotal,
                tax_rate,
                tax_amount,
                total_amount,
                notes,
                status,
                pay_token,
                payment_method,
                payment_date,
                stripe_payment_intent_id
            )
            VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $invoice_number,
            $client_id,
            $issue_date,
            $due_date,
            $package_price,
            $package_price,
            $invoice_note,
            $invoice_is_paid ? 'paid' : 'draft',
            $pay_token,
            $invoice_payment_method,
            $invoice_payment_date,
            $payment_confirmed && $stripe_payment_intent_id !== null && trim($stripe_payment_intent_id) !== ''
                ? trim($stripe_payment_intent_id)
                : null,
        ]);
        $invoice_id = safe_int($conn->lastInsertId());
        $invoice = bdta_invoice_fetch_row($conn, $invoice_id);
    } else {
        $pay_token = bdta_ensure_invoice_pay_token($conn, $invoice_id, $invoice['pay_token'] ?? null);
        if (scalar_string($invoice['pay_token'] ?? '') !== $pay_token) {
            $invoice['pay_token'] = $pay_token;
        }
    }

    if ($invoice_id <= 0) {
        throw new RuntimeException('Unable to create or locate the package invoice.');
    }

    $line_description = bdta_build_package_invoice_description($package);
    $item_stmt = $conn->prepare("
        SELECT *
        FROM invoice_items
        WHERE invoice_id = ?
          AND item_type = 'package'
          AND reference_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $package_id = safe_int($package['id'] ?? 0);
    $item_stmt->execute([$invoice_id, $package_id]);
    $existing_item = $item_stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($existing_item)) {
        $conn->prepare("
            INSERT INTO invoice_items (invoice_id, item_type, reference_id, description, quantity, rate, amount)
            VALUES (?, 'package', ?, ?, 1, ?, ?)
        ")->execute([
            $invoice_id,
            $package_id,
            $line_description,
            $package_price,
            $package_price,
        ]);
    }

    if ($payment_confirmed) {
        $payment_note = $stripe_checkout_session_id !== null && trim($stripe_checkout_session_id) !== ''
            ? 'Stripe Checkout session ' . trim($stripe_checkout_session_id)
            : 'Stripe package checkout';
        $payment_exists = false;

        $normalized_payment_intent_id = $stripe_payment_intent_id !== null ? trim($stripe_payment_intent_id) : '';
        if ($normalized_payment_intent_id !== '') {
            $existing_payment_stmt = $conn->prepare("
                SELECT invoice_id
                FROM invoice_payments
                WHERE stripe_payment_intent_id = ?
                LIMIT 1
            ");
            $existing_payment_stmt->execute([$normalized_payment_intent_id]);
            $existing_payment_invoice_id = safe_int($existing_payment_stmt->fetchColumn());
            if ($existing_payment_invoice_id > 0 && $existing_payment_invoice_id !== $invoice_id) {
                throw new RuntimeException('This Stripe payment intent is already linked to a different invoice.');
            }
            $payment_exists = ($existing_payment_invoice_id === $invoice_id);
        } else {
            $existing_payment_stmt = $conn->prepare("
                SELECT id
                FROM invoice_payments
                WHERE invoice_id = ?
                  AND payment_method = 'credit_card'
                  AND notes = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $existing_payment_stmt->execute([$invoice_id, $payment_note]);
            $payment_exists = safe_int($existing_payment_stmt->fetchColumn()) > 0;
        }

        if (!$payment_exists && $package_price > 0.0) {
            $conn->prepare("
                INSERT INTO invoice_payments (invoice_id, amount, payment_date, payment_method, stripe_payment_intent_id, notes)
                VALUES (?, ?, ?, 'credit_card', ?, ?)
            ")->execute([
                $invoice_id,
                $package_price,
                date('Y-m-d'),
                $normalized_payment_intent_id !== '' ? $normalized_payment_intent_id : null,
                $payment_note,
            ]);
        }

        $payment_summary = bdta_invoice_get_payment_summary($conn, bdta_invoice_fetch_row($conn, $invoice_id));
        $conn->prepare("
            UPDATE invoices
            SET status = ?,
                payment_method = 'credit_card',
                payment_date = COALESCE(payment_date, ?),
                stripe_payment_intent_id = COALESCE(NULLIF(?, ''), stripe_payment_intent_id),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([
            array_string_value($payment_summary, 'status', 'paid'),
            date('Y-m-d'),
            $normalized_payment_intent_id,
            $invoice_id,
        ]);
    } elseif ($invoice_is_paid && strtolower(array_string_value($invoice, 'status', 'draft')) !== 'paid') {
        $conn->prepare("
            UPDATE invoices
            SET status = 'paid',
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$invoice_id]);
    }

    $invoice = bdta_invoice_fetch_row($conn, $invoice_id);
    if ($invoice === []) {
        throw new RuntimeException('Package invoice could not be loaded after save.');
    }

    $invoice['client_name'] = $client_name;
    $invoice['client_email'] = $client_email;

    return [
        'invoice' => $invoice,
        'items' => bdta_get_package_invoice_items($conn, $invoice_id),
        'payment_confirmed' => $payment_confirmed,
    ];
}

/**
 * @param array<string, mixed> $package
 * @param list<array<string, mixed>> $items
 * @param array<string, mixed>|null $attached_form
 * @param array<int, mixed> $form_responses
 * @return array{client_id: int, client_package_id: int, form_submission_id: int}
 */
function bdta_finalize_package_purchase(
    SafePDO $conn,
    array $package,
    array $items,
    string $buyer_name,
    string $buyer_email,
    string $buyer_phone = '',
    string $notes = '',
    ?array $attached_form = null,
    array $form_responses = [],
    ?int $view_id = null,
    ?string $payment_method = null,
    ?string $stripe_checkout_session_id = null,
    ?string $stripe_payment_intent_id = null
): array {
    $package_id = safe_int($package['id'] ?? 0);

    if ($stripe_checkout_session_id !== null && $stripe_checkout_session_id !== '') {
        $package_id = safe_int($package['id'] ?? 0);
        if ($package_id > 0) {
            $existing_stmt = $conn->prepare("
                SELECT id, client_id
                FROM client_packages
                WHERE stripe_checkout_session_id = ? AND package_id = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $existing_stmt->execute([$stripe_checkout_session_id, $package_id]);
            $existing_purchase = $existing_stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($existing_purchase)) {
                if ($view_id !== null && $view_id > 0) {
                    $conn->prepare("UPDATE package_link_views SET purchased = 1, client_id = ? WHERE id = ?")
                        ->execute([safe_int($existing_purchase['client_id'] ?? 0), $view_id]);
                }

                try {
                    $existing_client_id = safe_int($existing_purchase['client_id'] ?? 0);
                    $existing_client_contact = bdta_get_package_checkout_client_contact($conn, $existing_client_id);
                    $invoice_context = bdta_ensure_package_purchase_invoice(
                        $conn,
                        $existing_client_id,
                        safe_int($existing_purchase['id'] ?? 0),
                        $package,
                        $existing_client_contact['name'] !== '' ? $existing_client_contact['name'] : $buyer_name,
                        $existing_client_contact['email'] !== '' ? $existing_client_contact['email'] : strtolower(trim($buyer_email)),
                        $payment_method,
                        $stripe_checkout_session_id,
                        $stripe_payment_intent_id
                    );
                    bdta_send_package_purchase_email(
                        $conn,
                        $invoice_context['invoice'],
                        $invoice_context['items'],
                        $invoice_context['payment_confirmed']
                    );
                } catch (Throwable $invoiceRecoveryError) {
                    error_log('Package checkout invoice recovery failed for package purchase #' . safe_int($existing_purchase['id'] ?? 0) . ': ' . $invoiceRecoveryError->getMessage());
                }

                return [
                    'client_id' => safe_int($existing_purchase['client_id'] ?? 0),
                    'client_package_id' => safe_int($existing_purchase['id'] ?? 0),
                    'form_submission_id' => 0,
                ];
            }
        }
    }

    $buyer_name = trim($buyer_name);
    $buyer_email = strtolower(trim($buyer_email));
    $buyer_phone = trim($buyer_phone);
    $buyer_phone_value = $buyer_phone !== '' ? $buyer_phone : null;
    $notes = trim($notes);
    $purchase_default_note = match ($payment_method) {
        'credit_card' => 'Self-serve package purchase via Stripe checkout',
        'offline' => 'Self-serve package purchase via public checkout',
        default => 'Self-serve package purchase',
    };
    $purchase_audit_channel = match ($payment_method) {
        'credit_card' => 'Stripe checkout',
        'offline' => 'Public checkout',
        default => 'package checkout',
    };

    if ($buyer_name === '' || $buyer_email === '') {
        throw new InvalidArgumentException('Buyer name and email are required.');
    }

    $invoice_context = null;
    $conn->beginTransaction();

    try {
        $existing_client = bdta_find_package_checkout_client_by_email($conn, $buyer_email);

        if (is_array($existing_client)) {
            $client_id = $existing_client['id'];
            $updated_name = trim($existing_client['name']);
            $updated_phone = trim($existing_client['phone']);
            if ($updated_name === '') {
                $updated_name = $buyer_name;
            }
            if ($updated_phone === '' && $buyer_phone_value !== null) {
                $updated_phone = $buyer_phone_value;
            }

            $conn->prepare("UPDATE clients SET name = ?, phone = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$updated_name, $updated_phone !== '' ? $updated_phone : null, $client_id]);
        } else {
            $client_insert = $conn->prepare("INSERT INTO clients (name, email, phone) VALUES (?, ?, ?)");
            $client_insert->execute([$buyer_name, $buyer_email, $buyer_phone_value]);
            $client_id = safe_int($conn->lastInsertId());
        }

        $expiration_days = safe_int($package['expiration_days'] ?? 0);
        $expires_at = null;
        if ($expiration_days > 0) {
            $expires_at = date('Y-m-d H:i:s', safe_timestamp(strtotime('+' . $expiration_days . ' days')));
        }

        $note_text = $notes !== '' ? $notes : $purchase_default_note;
        $purchase_stmt = $conn->prepare("
            INSERT INTO client_packages
                (client_id, package_id, package_name, expires_at, is_active, notes, created_by, payment_method, stripe_checkout_session_id)
            VALUES (?, ?, ?, ?, 1, ?, NULL, ?, ?)
        ");
        $purchase_stmt->execute([
            $client_id,
            $package_id,
            scalar_string($package['name'] ?? ''),
            $expires_at,
            $note_text,
            $payment_method !== null && $payment_method !== '' ? $payment_method : null,
            $stripe_checkout_session_id !== null && $stripe_checkout_session_id !== '' ? $stripe_checkout_session_id : null,
        ]);
        $client_package_id = safe_int($conn->lastInsertId());

        $credit_stmt = $conn->prepare("
            INSERT INTO client_package_credits
                (client_package_id, client_id, appointment_type_id, total_credits, used_credits)
            VALUES (?, ?, ?, ?, 0)
        ");
        foreach ($items as $item) {
            $credit_stmt->execute([
                $client_package_id,
                $client_id,
                safe_int($item['appointment_type_id'] ?? 0),
                safe_int($item['quantity'] ?? 0),
            ]);
        }

        $credit_rows_stmt = $conn->prepare("SELECT * FROM client_package_credits WHERE client_package_id = ?");
        $credit_rows_stmt->execute([$client_package_id]);
        $tx_stmt = $conn->prepare("
            INSERT INTO package_credit_transactions
                (client_package_credit_id, client_id, appointment_type_id, transaction_type, amount, notes, created_by)
            VALUES (?, ?, ?, 'purchase', ?, ?, NULL)
        ");
        foreach ($credit_rows_stmt->fetchAll(PDO::FETCH_ASSOC) as $credit_row) {
            $tx_stmt->execute([
                safe_int($credit_row['id'] ?? 0),
                $client_id,
                safe_int($credit_row['appointment_type_id'] ?? 0),
                safe_int($credit_row['total_credits'] ?? 0),
                "Package '" . scalar_string($package['name'] ?? '') . "' purchased via " . $purchase_audit_channel,
            ]);
        }

        $form_submission_id = 0;
        if ($attached_form && $form_responses !== []) {
            $form_insert = $conn->prepare("
                INSERT INTO form_submissions (client_id, template_id, responses, status, submitted_at)
                VALUES (?, ?, ?, 'submitted', CURRENT_TIMESTAMP)
            ");
            $form_insert->execute([
                $client_id,
                safe_int($attached_form['id'] ?? 0),
                json_encode($form_responses),
            ]);
            $form_submission_id = safe_int($conn->lastInsertId());

            $workflow_helper = new WorkflowHelper($conn);
            $workflow_helper->checkFormTriggers($form_submission_id);
        }

        if ($view_id !== null && $view_id > 0) {
            $conn->prepare("UPDATE package_link_views SET purchased = 1, client_id = ? WHERE id = ?")
                ->execute([$client_id, $view_id]);
        }

        $package_name = scalar_string($package['name'] ?? 'Package');
        $invoice_context = bdta_ensure_package_purchase_invoice(
            $conn,
            $client_id,
            $client_package_id,
            $package,
            $buyer_name,
            $buyer_email,
            $payment_method,
            $stripe_checkout_session_id,
            $stripe_payment_intent_id
        );
        $conn->commit();

        try {
            bdta_create_admin_notifications(
                $conn,
                'package',
                $client_package_id,
                'Package purchased',
                $buyer_name . ' purchased ' . $package_name,
                '/client/credits_manage.php?client_id=' . $client_id
            );
            bdta_create_notification(
                $conn,
                'portal',
                $client_id,
                'package',
                $client_package_id,
                'Package credits added',
                'Your ' . $package_name . ' credits are now available in the portal.',
                '/portal/credits.php'
            );
        } catch (Throwable $notificationError) {
            error_log('Package checkout notification creation failed: ' . $notificationError->getMessage());
        }

        if (is_array($invoice_context)) {
            try {
                bdta_send_package_purchase_email(
                    $conn,
                    $invoice_context['invoice'],
                    $invoice_context['items'],
                    !empty($invoice_context['payment_confirmed'])
                );
            } catch (Throwable $emailError) {
                error_log('Package checkout email failed for package purchase #' . $client_package_id . ': ' . $emailError->getMessage());
            }
        }

        return [
            'client_id' => $client_id,
            'client_package_id' => $client_package_id,
            'form_submission_id' => $form_submission_id,
        ];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}
