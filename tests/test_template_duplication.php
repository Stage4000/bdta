#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';
require_once dirname(__DIR__) . '/backend/includes/template_duplication.php';

echo "=== Template Duplication Test ===\n\n";

$cleanup = [
    'email_template_ids' => [],
    'contract_template_ids' => [],
    'form_template_ids' => [],
    'appointment_type_ids' => [],
    'reminder_rule_ids' => [],
];

try {
    $db = new Database();
    $conn = $db->getConnection();
    $suffix = bin2hex(random_bytes(4));

    $conn->prepare("
        INSERT INTO email_templates (name, template_type, subject, body_html, body_text, variables, is_active)
        VALUES (?, 'booking_confirmation', ?, ?, ?, ?, 1)
    ")->execute([
        'Email Duplicate Test ' . $suffix,
        'Subject ' . $suffix,
        '<p>Hello ' . $suffix . '</p>',
        'Hello ' . $suffix,
        'client_name',
    ]);
    $email_template_id = (int) $conn->lastInsertId();
    $cleanup['email_template_ids'][] = $email_template_id;

    $duplicated_email_id = duplicateEmailTemplate($conn, $email_template_id);
    $cleanup['email_template_ids'][] = $duplicated_email_id;
    $duplicated_email_id_2 = duplicateEmailTemplate($conn, $email_template_id);
    $cleanup['email_template_ids'][] = $duplicated_email_id_2;

    $email_stmt = $conn->prepare("SELECT name, subject, body_html, body_text, variables, is_active FROM email_templates WHERE id = ?");
    $email_stmt->execute([$duplicated_email_id]);
    $email_copy = $email_stmt->fetch(PDO::FETCH_ASSOC);
    $email_stmt->execute([$duplicated_email_id_2]);
    $email_copy_2 = $email_stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($email_copy)
        || $email_copy['name'] !== 'Email Duplicate Test ' . $suffix . ' (Copy)'
        || $email_copy['subject'] !== 'Subject ' . $suffix
        || $email_copy['body_text'] !== 'Hello ' . $suffix
        || $email_copy['variables'] !== 'client_name'
        || (int) $email_copy['is_active'] !== 1
    ) {
        throw new RuntimeException('Email template duplication did not preserve fields as expected.');
    }

    if (!is_array($email_copy_2) || $email_copy_2['name'] !== 'Email Duplicate Test ' . $suffix . ' (Copy 2)') {
        throw new RuntimeException('Email template duplicate naming did not increment correctly.');
    }

    $conn->prepare("
        INSERT INTO contract_templates (name, description, template_text, service_type, renewal_period_months, is_active)
        VALUES (?, ?, ?, ?, ?, 1)
    ")->execute([
        'Contract Duplicate Test ' . $suffix,
        'Contract description ' . $suffix,
        '<p>Terms ' . $suffix . '</p>',
        'Training',
        18,
    ]);
    $contract_template_id = (int) $conn->lastInsertId();
    $cleanup['contract_template_ids'][] = $contract_template_id;

    $duplicated_contract_id = duplicateContractTemplate($conn, $contract_template_id);
    $cleanup['contract_template_ids'][] = $duplicated_contract_id;

    $contract_stmt = $conn->prepare("SELECT name, description, template_text, service_type, renewal_period_months, is_active FROM contract_templates WHERE id = ?");
    $contract_stmt->execute([$duplicated_contract_id]);
    $contract_copy = $contract_stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($contract_copy)
        || $contract_copy['name'] !== 'Contract Duplicate Test ' . $suffix . ' (Copy)'
        || $contract_copy['description'] !== 'Contract description ' . $suffix
        || $contract_copy['template_text'] !== '<p>Terms ' . $suffix . '</p>'
        || $contract_copy['service_type'] !== 'Training'
        || (int) $contract_copy['renewal_period_months'] !== 18
        || (int) $contract_copy['is_active'] !== 1
    ) {
        throw new RuntimeException('Contract template duplication did not preserve fields as expected.');
    }

    $conn->prepare("
        INSERT INTO appointment_types (
            name, description, duration_minutes, requires_forms, is_active, unique_link,
            public_available, portal_available, available_days, available_start_time, available_end_time, time_slot_interval,
            booking_request_template_id, requires_admin_confirmation
        )
        VALUES (?, ?, 45, 0, 1, ?, 1, 1, ?, '09:00', '17:00', 30, ?, 1)
    ")->execute([
        // Keep these bound values aligned with the placeholders in the INSERT above.
        'Appointment Duplicate Type ' . $suffix,
        'Appointment source ' . $suffix,
        'source-link-' . $suffix,
        json_encode([1, 3, 5]),
        $email_template_id,
    ]);
    $appointment_type_id = (int) $conn->lastInsertId();
    $cleanup['appointment_type_ids'][] = $appointment_type_id;

    $conn->prepare("
        INSERT INTO form_templates (name, description, form_type, fields, required_frequency, appointment_type_id, is_internal, is_active)
        VALUES (?, ?, 'client_form', ?, 'annual', ?, 0, 1)
    ")->execute([
        'Form Duplicate Test ' . $suffix,
        'Form description ' . $suffix,
        json_encode([['label' => 'Pet Name', 'type' => 'text', 'required' => 1]]),
        $appointment_type_id,
    ]);
    $form_template_id = (int) $conn->lastInsertId();
    $cleanup['form_template_ids'][] = $form_template_id;

    $duplicated_form_id = duplicateFormTemplate($conn, $form_template_id);
    $cleanup['form_template_ids'][] = $duplicated_form_id;

    $form_stmt = $conn->prepare("SELECT name, description, form_type, fields, required_frequency, appointment_type_id, is_internal, is_active FROM form_templates WHERE id = ?");
    $form_stmt->execute([$duplicated_form_id]);
    $form_copy = $form_stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($form_copy)
        || $form_copy['name'] !== 'Form Duplicate Test ' . $suffix . ' (Copy)'
        || $form_copy['description'] !== 'Form description ' . $suffix
        || $form_copy['form_type'] !== 'client_form'
        || $form_copy['required_frequency'] !== 'annual'
        || (int) $form_copy['appointment_type_id'] !== $appointment_type_id
        || (int) $form_copy['is_internal'] !== 0
        || (int) $form_copy['is_active'] !== 1
    ) {
        throw new RuntimeException('Form template duplication did not preserve fields as expected.');
    }

    $conn->prepare("INSERT INTO appointment_type_forms (appointment_type_id, form_template_id) VALUES (?, ?)")
        ->execute([$appointment_type_id, $form_template_id]);
    $conn->prepare("
        INSERT INTO booking_reminder_rules (appointment_type_id, name, hours_before, template_id, is_active)
        VALUES (?, ?, 24, ?, 1)
    ")->execute([
        $appointment_type_id,
        'Rule ' . $suffix,
        $email_template_id,
    ]);
    $cleanup['reminder_rule_ids'][] = (int) $conn->lastInsertId();

    $duplicated_appointment_type_id = duplicateAppointmentType($conn, $appointment_type_id);
    $cleanup['appointment_type_ids'][] = $duplicated_appointment_type_id;

    $appointment_stmt = $conn->prepare("
        SELECT name, description, duration_minutes, unique_link, public_available, portal_available, available_days,
               booking_request_template_id, requires_admin_confirmation
        FROM appointment_types
        WHERE id = ?
    ");
    $appointment_stmt->execute([$duplicated_appointment_type_id]);
    $appointment_copy = $appointment_stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($appointment_copy)
        || $appointment_copy['name'] !== 'Appointment Duplicate Type ' . $suffix . ' (Copy)'
        || $appointment_copy['description'] !== 'Appointment source ' . $suffix
        || (int) $appointment_copy['duration_minutes'] !== 45
        || $appointment_copy['unique_link'] === 'source-link-' . $suffix
        || (int) $appointment_copy['public_available'] !== 1
        || (int) $appointment_copy['portal_available'] !== 1
        || $appointment_copy['available_days'] !== json_encode([1, 3, 5])
        || (int) $appointment_copy['booking_request_template_id'] !== $email_template_id
        || (int) $appointment_copy['requires_admin_confirmation'] !== 1
    ) {
        throw new RuntimeException('Appointment type duplication did not preserve fields or regenerate the unique link.');
    }

    $association_stmt = $conn->prepare("SELECT COUNT(*) FROM appointment_type_forms WHERE appointment_type_id = ? AND form_template_id = ?");
    $association_stmt->execute([$duplicated_appointment_type_id, $form_template_id]);
    if (safe_int($association_stmt->fetchColumn()) !== 1) {
        throw new RuntimeException('Appointment type duplicate did not copy form associations.');
    }

    $rule_stmt = $conn->prepare("SELECT name, hours_before, template_id, is_active FROM booking_reminder_rules WHERE appointment_type_id = ?");
    $rule_stmt->execute([$duplicated_appointment_type_id]);
    $rule_copy = $rule_stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($rule_copy)
        || $rule_copy['name'] !== 'Rule ' . $suffix
        || (int) $rule_copy['hours_before'] !== 24
        || (int) $rule_copy['template_id'] !== $email_template_id
        || (int) $rule_copy['is_active'] !== 1
    ) {
        throw new RuntimeException('Appointment type duplicate did not copy reminder rules.');
    }

    echo "✓ Email templates duplicate with preserved content and incremented copy names\n";
    echo "✓ Contract templates duplicate with preserved settings\n";
    echo "✓ Form templates duplicate with preserved structure\n";
    echo "✓ Appointment types duplicate with a fresh booking link, form mappings, and reminder rules\n\n";
    echo "=== Template Duplication Tests Passed! ===\n";
} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    if (isset($conn) && $conn instanceof PDO) {
        if (!empty($cleanup['appointment_type_ids'])) {
            $placeholders = implode(',', array_fill(0, count($cleanup['appointment_type_ids']), '?'));
            $stmt = $conn->prepare("DELETE FROM appointment_types WHERE id IN ($placeholders)");
            $stmt->execute($cleanup['appointment_type_ids']);
        }
        if (!empty($cleanup['form_template_ids'])) {
            $placeholders = implode(',', array_fill(0, count($cleanup['form_template_ids']), '?'));
            $stmt = $conn->prepare("DELETE FROM form_templates WHERE id IN ($placeholders)");
            $stmt->execute($cleanup['form_template_ids']);
        }
        if (!empty($cleanup['contract_template_ids'])) {
            $placeholders = implode(',', array_fill(0, count($cleanup['contract_template_ids']), '?'));
            $stmt = $conn->prepare("DELETE FROM contract_templates WHERE id IN ($placeholders)");
            $stmt->execute($cleanup['contract_template_ids']);
        }
        if (!empty($cleanup['email_template_ids'])) {
            $placeholders = implode(',', array_fill(0, count($cleanup['email_template_ids']), '?'));
            $stmt = $conn->prepare("DELETE FROM email_templates WHERE id IN ($placeholders)");
            $stmt->execute($cleanup['email_template_ids']);
        }
    }
}
