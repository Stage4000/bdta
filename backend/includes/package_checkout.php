<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/form_types.php';
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
 * @return array<string, mixed>|null
 */
function bdta_get_package_attached_form(PDO $conn, int $form_template_id): ?array
{
    if ($form_template_id <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT id, name, description, fields, form_type, is_active, COALESCE(is_internal, 0) AS is_internal
        FROM form_templates
        WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$form_template_id]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($form)) {
        return null;
    }

    $form_type = bdta_normalize_form_type(scalar_string($form['form_type'] ?? 'client_form'));
    if (safe_int($form['is_internal'] ?? 0) === 1 || bdta_form_type_forced_internal($form_type) === 1 || !bdta_form_type_allows_public_submission($form_type)) {
        return null;
    }

    $form['form_type'] = $form_type;
    $form['fields'] = bdta_package_checkout_fields($form['fields'] ?? []);

    return $form;
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
 * @param array<string, mixed> $package
 * @param list<array<string, mixed>> $items
 * @param array<string, mixed>|null $attached_form
 * @param array<int, mixed> $form_responses
 * @return array{client_id: int, client_package_id: int, form_submission_id: int}
 */
function bdta_finalize_package_purchase(
    PDO $conn,
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
    ?string $stripe_checkout_session_id = null
): array {
    if ($stripe_checkout_session_id !== null && $stripe_checkout_session_id !== '') {
        $existing_stmt = $conn->prepare("
            SELECT id, client_id
            FROM client_packages
            WHERE stripe_checkout_session_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $existing_stmt->execute([$stripe_checkout_session_id]);
        $existing_purchase = $existing_stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing_purchase)) {
            if ($view_id !== null && $view_id > 0) {
                $conn->prepare("UPDATE package_link_views SET purchased = 1, client_id = ? WHERE id = ?")
                    ->execute([safe_int($existing_purchase['client_id'] ?? 0), $view_id]);
            }

            return [
                'client_id' => safe_int($existing_purchase['client_id'] ?? 0),
                'client_package_id' => safe_int($existing_purchase['id'] ?? 0),
                'form_submission_id' => 0,
            ];
        }
    }

    $buyer_name = trim($buyer_name);
    $buyer_email = strtolower(trim($buyer_email));
    $buyer_phone = trim($buyer_phone);
    $notes = trim($notes);

    if ($buyer_name === '' || $buyer_email === '') {
        throw new InvalidArgumentException('Buyer name and email are required.');
    }

    $conn->beginTransaction();

    try {
        $client_lookup = $conn->prepare("
            SELECT id, name, phone
            FROM clients
            WHERE LOWER(email) = LOWER(?)
            ORDER BY updated_at DESC, created_at DESC, id DESC
            LIMIT 1
        ");
        $client_lookup->execute([$buyer_email]);
        $existing_client = $client_lookup->fetch(PDO::FETCH_ASSOC);

        if (is_array($existing_client)) {
            $client_id = safe_int($existing_client['id'] ?? 0);
            if ($client_id <= 0) {
                throw new RuntimeException('Unable to resolve existing client.');
            }

            $updated_name = trim(scalar_string($existing_client['name'] ?? ''));
            $updated_phone = trim(scalar_string($existing_client['phone'] ?? ''));
            if ($updated_name === '' && $buyer_name !== '') {
                $updated_name = $buyer_name;
            }
            if ($updated_phone === '' && $buyer_phone !== '') {
                $updated_phone = $buyer_phone;
            }

            $conn->prepare("UPDATE clients SET name = ?, phone = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$updated_name, $updated_phone !== '' ? $updated_phone : null, $client_id]);
        } else {
            $client_insert = $conn->prepare("INSERT INTO clients (name, email, phone) VALUES (?, ?, ?)");
            $client_insert->execute([$buyer_name, $buyer_email, $buyer_phone !== '' ? $buyer_phone : null]);
            $client_id = safe_int($conn->lastInsertId());
        }

        $expiration_days = safe_int($package['expiration_days'] ?? 0);
        $expires_at = null;
        if ($expiration_days > 0) {
            $expires_at = date('Y-m-d H:i:s', safe_timestamp(strtotime('+' . $expiration_days . ' days')));
        }

        $note_text = $notes !== '' ? $notes : 'Self-serve purchase via shareable link';
        $purchase_stmt = $conn->prepare("
            INSERT INTO client_packages
                (client_id, package_id, package_name, expires_at, is_active, notes, created_by, payment_method, stripe_checkout_session_id)
            VALUES (?, ?, ?, ?, 1, ?, NULL, ?, ?)
        ");
        $purchase_stmt->execute([
            $client_id,
            safe_int($package['id'] ?? 0),
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
                "Package '" . scalar_string($package['name'] ?? '') . "' purchased via shareable link",
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

        $conn->commit();

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
