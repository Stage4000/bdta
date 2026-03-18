#!/usr/bin/env php
<?php

require_once __DIR__ . '/backend/includes/database.php';
require_once __DIR__ . '/backend/includes/form_types.php';
require_once __DIR__ . '/backend/includes/public_form_context.php';

echo "=== Form Funnel Tests ===\n\n";

$cleanup = [
    'client_ids' => [],
    'booking_ids' => [],
];

try {
    $options = bdta_get_form_type_options();
    $expected_types = ['booking_form', 'follow_up_note', 'client_form', 'pet_form', 'survey_form'];

    if (array_keys($options) !== $expected_types) {
        throw new RuntimeException('Canonical form types do not match the expected funnel categories.');
    }

    if (bdta_normalize_form_type('session_note') !== 'follow_up_note') {
        throw new RuntimeException('Legacy session_note templates should normalize to follow-up notes.');
    }

    if (bdta_normalize_form_type('behavior_assessment') !== 'client_form'
        || bdta_normalize_form_type('training_plan') !== 'client_form'
    ) {
        throw new RuntimeException('Legacy behavior/training templates should normalize to client forms.');
    }

    if (bdta_form_type_allows_direct_link('booking_form')) {
        throw new RuntimeException('Booking forms should only run inside the booking flow.');
    }

    if (!bdta_form_type_allows_public_submission('survey_form')) {
        throw new RuntimeException('Survey forms should remain publicly completable.');
    }

    if (bdta_form_type_forced_internal('pet_form') !== 1) {
        throw new RuntimeException('Pet forms should remain admin-only.');
    }

    echo "✓ Canonical form categories and legacy mappings are correct\n";

    $db = new Database();
    $conn = $db->getConnection();
    $suffix = bin2hex(random_bytes(4));

    $conn->prepare("INSERT INTO clients (name, email, phone) VALUES (?, ?, ?)")
        ->execute([
            'Existing Client ' . $suffix,
            'existing-' . $suffix . '@example.com',
            '555-' . substr($suffix, 0, 4),
        ]);
    $client_id = (int) $conn->lastInsertId();
    $cleanup['client_ids'][] = $client_id;

    $conn->prepare("
        INSERT INTO bookings (
            client_id, client_name, client_email, client_phone, service_type,
            appointment_date, appointment_time, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
    ")->execute([
        $client_id,
        'Existing Client ' . $suffix,
        'existing-' . $suffix . '@example.com',
        '555-' . substr($suffix, 0, 4),
        'Follow-up Session',
        date('Y-m-d', strtotime('+3 days')),
        '09:00',
    ]);
    $booking_id = (int) $conn->lastInsertId();
    $cleanup['booking_ids'][] = $booking_id;

    $client_context = bdta_resolve_public_form_context($conn, $client_id, 0);
    if ($client_context['client_id'] !== $client_id
        || $client_context['contact_email'] !== 'existing-' . $suffix . '@example.com'
        || $client_context['booking_id'] !== 0
    ) {
        throw new RuntimeException('Existing-client form links should preload the linked client.');
    }

    $booking_context = bdta_resolve_public_form_context($conn, 0, $booking_id);
    if ($booking_context['client_id'] !== $client_id
        || $booking_context['booking_id'] !== $booking_id
        || $booking_context['contact_name'] !== 'Existing Client ' . $suffix
    ) {
        throw new RuntimeException('Appointment-linked form links should resolve both client and booking context.');
    }

    $missing_client_id = (int) $conn->query("SELECT COALESCE(MAX(id), 0) FROM clients")->fetchColumn() + 1;
    $exists_stmt = $conn->prepare("SELECT COUNT(*) FROM clients WHERE id = ?");
    while (true) {
        $exists_stmt->execute([$missing_client_id]);
        if ((int) $exists_stmt->fetchColumn() === 0) {
            break;
        }
        $missing_client_id++;
    }
    $mismatch_context = bdta_resolve_public_form_context($conn, $missing_client_id, $booking_id);
    if ($mismatch_context['errors'] === []) {
        throw new RuntimeException('Mismatched client/booking combinations should be rejected.');
    }

    echo "✓ Public form links resolve existing client and appointment context correctly\n\n";
    echo "=== Form Funnel Tests Passed! ===\n";
} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    if (isset($conn) && $conn instanceof PDO) {
        if ($cleanup['booking_ids'] !== []) {
            $placeholders = implode(',', array_fill(0, count($cleanup['booking_ids']), '?'));
            $stmt = $conn->prepare("DELETE FROM bookings WHERE id IN ($placeholders)");
            $stmt->execute($cleanup['booking_ids']);
        }

        if ($cleanup['client_ids'] !== []) {
            $placeholders = implode(',', array_fill(0, count($cleanup['client_ids']), '?'));
            $stmt = $conn->prepare("DELETE FROM clients WHERE id IN ($placeholders)");
            $stmt->execute($cleanup['client_ids']);
        }
    }
}
