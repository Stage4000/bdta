#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';
require_once dirname(__DIR__) . '/backend/includes/form_types.php';
require_once dirname(__DIR__) . '/backend/includes/form_link_requests.php';

echo "=== Form Link Request Tests ===\n\n";

$cleanup = [
    'client_ids' => [],
    'booking_ids' => [],
    'pet_ids' => [],
    'appointment_type_ids' => [],
    'form_template_ids' => [],
    'submission_ids' => [],
];

try {
    $db = new Database();
    $conn = $db->getConnection();
    $suffix = bin2hex(random_bytes(4));

    $conn->prepare("INSERT INTO clients (name, email, phone) VALUES (?, ?, ?)")
        ->execute(['Link Test Client ' . $suffix, 'form-link-' . $suffix . '@example.com', '555-1111']);
    $client_id = (int) $conn->lastInsertId();
    $cleanup['client_ids'][] = $client_id;

    $conn->prepare("INSERT INTO appointment_types (name, duration_minutes, is_active, unique_link) VALUES (?, 60, 1, ?)")
        ->execute(['Link Test Session ' . $suffix, 'link-' . $suffix]);
    $appointment_type_id = (int) $conn->lastInsertId();
    $cleanup['appointment_type_ids'][] = $appointment_type_id;

    $conn->prepare("
        INSERT INTO bookings (
            client_id, appointment_type_id, client_name, client_email, client_phone,
            service_type, appointment_date, appointment_time, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmed')
    ")->execute([
        $client_id,
        $appointment_type_id,
        'Link Test Client ' . $suffix,
        'form-link-' . $suffix . '@example.com',
        '555-1111',
        'Link Test Session',
        date('Y-m-d', strtotime('+5 days')),
        '10:00',
    ]);
    $booking_id = (int) $conn->lastInsertId();
    $cleanup['booking_ids'][] = $booking_id;

    $conn->prepare("INSERT INTO pets (client_id, name, species, breed, is_active) VALUES (?, ?, 'Dog', ?, 1)")
        ->execute([$client_id, 'Scout ' . $suffix, 'Mix']);
    $pet_id = (int) $conn->lastInsertId();
    $cleanup['pet_ids'][] = $pet_id;

    $conn->prepare("INSERT INTO form_templates (name, form_type, fields, is_active, is_internal) VALUES (?, 'pet_form', '[]', 1, 1)")
        ->execute(['Pet Form ' . $suffix]);
    $pet_form_template_id = (int) $conn->lastInsertId();
    $cleanup['form_template_ids'][] = $pet_form_template_id;

    $request = bdta_create_form_request($conn, $pet_form_template_id, $client_id, $booking_id, $pet_id);
    $submission_id = array_int_value($request, 'submission_id');
    $cleanup['submission_ids'][] = $submission_id;

    $stmt = $conn->prepare("SELECT client_id, booking_id, pet_id, access_token, status, responses FROM form_submissions WHERE id = ?");
    $stmt->execute([$submission_id]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($submission)
        || (int) $submission['client_id'] !== $client_id
        || (int) $submission['booking_id'] !== $booking_id
        || (int) $submission['pet_id'] !== $pet_id
        || !preg_match('/^[a-f0-9]{32}$/', (string) ($submission['access_token'] ?? ''))
        || $submission['status'] !== 'pending'
        || $submission['responses'] !== '{}'
    ) {
        throw new RuntimeException('Form request rows should preserve client, booking, pet, and token associations.');
    }

    $expected_public_form_url = getDynamicBaseUrl() . '/backend/public/form.php?token=' . $submission['access_token'];
    if (bdta_get_public_form_request_url($conn, $submission_id) !== $expected_public_form_url) {
        throw new RuntimeException('Form request URLs should use the pending submission access token.');
    }

    if (bdta_get_public_booking_request_url('link-' . $suffix) !== getDynamicBaseUrl() . '/backend/public/book.php?link=' . urlencode('link-' . $suffix)) {
        throw new RuntimeException('Booking request URLs should use the appointment unique link.');
    }

    $db_values = bdta_get_form_type_db_values('client_form');
    if (!in_array('behavior_assessment', $db_values, true) || !in_array('training_plan', $db_values, true)) {
        throw new RuntimeException('Client-form queries should include legacy template types.');
    }

    echo "✓ Form requests keep object associations intact\n";
    echo "✓ Public link helpers generate the expected URLs\n";
    echo "✓ Form type DB filters include legacy synonyms\n\n";
    echo "=== Form Link Request Tests Passed! ===\n";
} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    if (isset($conn) && $conn instanceof PDO) {
        if ($cleanup['submission_ids'] !== []) {
            $placeholders = implode(',', array_fill(0, count($cleanup['submission_ids']), '?'));
            $stmt = $conn->prepare("DELETE FROM form_submissions WHERE id IN ($placeholders)");
            $stmt->execute($cleanup['submission_ids']);
        }
        if ($cleanup['pet_ids'] !== []) {
            $placeholders = implode(',', array_fill(0, count($cleanup['pet_ids']), '?'));
            $stmt = $conn->prepare("DELETE FROM pets WHERE id IN ($placeholders)");
            $stmt->execute($cleanup['pet_ids']);
        }
        if ($cleanup['booking_ids'] !== []) {
            $placeholders = implode(',', array_fill(0, count($cleanup['booking_ids']), '?'));
            $stmt = $conn->prepare("DELETE FROM bookings WHERE id IN ($placeholders)");
            $stmt->execute($cleanup['booking_ids']);
        }
        if ($cleanup['appointment_type_ids'] !== []) {
            $placeholders = implode(',', array_fill(0, count($cleanup['appointment_type_ids']), '?'));
            $stmt = $conn->prepare("DELETE FROM appointment_types WHERE id IN ($placeholders)");
            $stmt->execute($cleanup['appointment_type_ids']);
        }
        if ($cleanup['form_template_ids'] !== []) {
            $placeholders = implode(',', array_fill(0, count($cleanup['form_template_ids']), '?'));
            $stmt = $conn->prepare("DELETE FROM form_templates WHERE id IN ($placeholders)");
            $stmt->execute($cleanup['form_template_ids']);
        }
        if ($cleanup['client_ids'] !== []) {
            $placeholders = implode(',', array_fill(0, count($cleanup['client_ids']), '?'));
            $stmt = $conn->prepare("DELETE FROM clients WHERE id IN ($placeholders)");
            $stmt->execute($cleanup['client_ids']);
        }
    }
}
