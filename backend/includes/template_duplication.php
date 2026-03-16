<?php

function duplicateEmailTemplate(PDO $conn, int $template_id): int
{
    $duplicate_name = duplicateNamedRecordLabel($conn, 'email_templates', $template_id);

    $stmt = $conn->prepare("
        INSERT INTO email_templates (
            name, template_type, subject, body_html, body_text, variables, is_active
        )
        SELECT
            ?, template_type, subject, body_html, body_text, variables, is_active
        FROM email_templates
        WHERE id = ?
    ");
    $stmt->execute([$duplicate_name, $template_id]);

    return (int) $conn->lastInsertId();
}

function duplicateContractTemplate(PDO $conn, int $template_id): int
{
    $duplicate_name = duplicateNamedRecordLabel($conn, 'contract_templates', $template_id);

    $stmt = $conn->prepare("
        INSERT INTO contract_templates (
            name, description, template_text, service_type, renewal_period_months, is_active
        )
        SELECT
            ?, description, template_text, service_type, renewal_period_months, is_active
        FROM contract_templates
        WHERE id = ?
    ");
    $stmt->execute([$duplicate_name, $template_id]);

    return (int) $conn->lastInsertId();
}

function duplicateFormTemplate(PDO $conn, int $template_id): int
{
    $duplicate_name = duplicateNamedRecordLabel($conn, 'form_templates', $template_id);

    $stmt = $conn->prepare("
        INSERT INTO form_templates (
            name, description, form_type, fields, required_frequency, appointment_type_id, is_internal, is_active
        )
        SELECT
            ?, description, form_type, fields, required_frequency, appointment_type_id, is_internal, is_active
        FROM form_templates
        WHERE id = ?
    ");
    $stmt->execute([$duplicate_name, $template_id]);

    return (int) $conn->lastInsertId();
}

function duplicateAppointmentType(PDO $conn, int $appointment_type_id): int
{
    $duplicate_name = duplicateNamedRecordLabel($conn, 'appointment_types', $appointment_type_id);
    $unique_link = duplicateAppointmentTypeUniqueLink($conn);

    $conn->beginTransaction();

    try {
        $stmt = $conn->prepare("
            INSERT INTO appointment_types (
                name, description, duration_minutes,
                buffer_before_minutes, buffer_after_minutes,
                use_travel_time_buffer, travel_time_minutes,
                advance_booking_min_days, advance_booking_max_days,
                cancellation_notice_hours,
                requires_forms, requires_contract, contract_template_id,
                auto_invoice, invoice_due_days,
                consumes_credits, credit_count,
                is_group_class, max_participants,
                is_active, unique_link,
                portal_available,
                schedule_type, specific_date, specific_dates,
                available_days, available_start_time, available_end_time, time_slot_interval,
                is_mini_session, mini_session_location, mini_session_topic,
                is_field_rental, field_rental_location,
                group_class_location,
                per_day_schedule,
                default_amount,
                location_types,
                confirmation_template_id,
                reminder_template_id,
                cancellation_template_id
            )
            SELECT
                ?, description, duration_minutes,
                buffer_before_minutes, buffer_after_minutes,
                use_travel_time_buffer, travel_time_minutes,
                advance_booking_min_days, advance_booking_max_days,
                cancellation_notice_hours,
                requires_forms, requires_contract, contract_template_id,
                auto_invoice, invoice_due_days,
                consumes_credits, credit_count,
                is_group_class, max_participants,
                is_active, ?,
                portal_available,
                schedule_type, specific_date, specific_dates,
                available_days, available_start_time, available_end_time, time_slot_interval,
                is_mini_session, mini_session_location, mini_session_topic,
                is_field_rental, field_rental_location,
                group_class_location,
                per_day_schedule,
                default_amount,
                location_types,
                confirmation_template_id,
                reminder_template_id,
                cancellation_template_id
            FROM appointment_types
            WHERE id = ?
        ");
        $stmt->execute([$duplicate_name, $unique_link, $appointment_type_id]);

        $new_appointment_type_id = (int) $conn->lastInsertId();

        $copy_forms_stmt = $conn->prepare("
            INSERT INTO appointment_type_forms (appointment_type_id, form_template_id)
            SELECT ?, form_template_id
            FROM appointment_type_forms
            WHERE appointment_type_id = ?
        ");
        $copy_forms_stmt->execute([$new_appointment_type_id, $appointment_type_id]);

        $copy_rules_stmt = $conn->prepare("
            INSERT INTO booking_reminder_rules (appointment_type_id, name, hours_before, template_id, is_active)
            SELECT ?, name, hours_before, template_id, is_active
            FROM booking_reminder_rules
            WHERE appointment_type_id = ?
        ");
        $copy_rules_stmt->execute([$new_appointment_type_id, $appointment_type_id]);

        $conn->commit();

        return $new_appointment_type_id;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        throw $e;
    }
}

function duplicateNamedRecordLabel(PDO $conn, string $table, int $record_id): string
{
    $allowed_tables = ['email_templates', 'contract_templates', 'form_templates', 'appointment_types'];
    if (!in_array($table, $allowed_tables, true)) {
        throw new InvalidArgumentException('Unsupported table for duplication.');
    }

    $stmt = $conn->prepare("SELECT name FROM {$table} WHERE id = ?");
    $stmt->execute([$record_id]);
    $source_name = $stmt->fetchColumn();

    if (!is_string($source_name)) {
        throw new RuntimeException('Source record not found.');
    }

    $source_name = trim($source_name);
    if ($source_name === '') {
        $source_name = 'Untitled';
    }

    $copy_index = 1;
    $name_exists_stmt = $conn->prepare("SELECT COUNT(*) FROM {$table} WHERE name = ?");

    while (true) {
        $candidate = $source_name . ' (Copy' . ($copy_index > 1 ? ' ' . $copy_index : '') . ')';
        $name_exists_stmt->execute([$candidate]);

        if ((int) $name_exists_stmt->fetchColumn() === 0) {
            return $candidate;
        }

        $copy_index++;
    }
}

function duplicateAppointmentTypeUniqueLink(PDO $conn): string
{
    $check_stmt = $conn->prepare("SELECT COUNT(*) FROM appointment_types WHERE unique_link = ?");

    do {
        $unique_link = bin2hex(random_bytes(16));
        $check_stmt->execute([$unique_link]);
        $exists = (int) $check_stmt->fetchColumn();
    } while ($exists > 0);

    return $unique_link;
}
