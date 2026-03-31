#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';
require_once dirname(__DIR__) . '/backend/includes/form_types.php';
require_once dirname(__DIR__) . '/backend/includes/public_form_context.php';

$original_request_method = $_SERVER['REQUEST_METHOD'] ?? null;
$_SERVER['REQUEST_METHOD'] = 'CLI';
$original_cwd = getcwd();
chdir(dirname(__DIR__) . '/backend/public');
require_once dirname(__DIR__) . '/backend/public/api_bookings.php';
if ($original_cwd !== false) {
    chdir($original_cwd);
}
if ($original_request_method === null) {
    unset($_SERVER['REQUEST_METHOD']);
} else {
    $_SERVER['REQUEST_METHOD'] = $original_request_method;
}

echo "=== Form Funnel Tests ===\n\n";

$cleanup = [
    'client_ids' => [],
    'booking_ids' => [],
    'form_template_ids' => [],
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

    echo "✓ Public form links resolve existing client and appointment context correctly\n";

    $missing_address_email = 'missing-address-' . $suffix . '@example.com';
    $client_count_stmt = $conn->prepare("SELECT COUNT(*) FROM clients WHERE email = ?");
    $client_count_stmt->execute([$missing_address_email]);
    $client_count_before = (int) $client_count_stmt->fetchColumn();

    $booking_count_stmt = $conn->prepare("SELECT COUNT(*) FROM bookings WHERE client_email = ?");
    $booking_count_stmt->execute([$missing_address_email]);
    $booking_count_before = (int) $booking_count_stmt->fetchColumn();

    $result = api_booking_create_booking($conn, [
        'client_name' => 'Missing Address ' . $suffix,
        'client_email' => $missing_address_email,
        'client_phone' => '555-9999',
        'service_type' => 'At Home Consultation',
        'appointment_date' => date('Y-m-d', strtotime('+5 days')),
        'appointment_time' => '11:00',
        'location_type' => 'client_address',
    ]);

    if (($result['error'] ?? '') !== 'An address is required for this booking. Please provide your address in the booking form.') {
        throw new RuntimeException('Missing-address bookings should return the expected validation error.');
    }

    $client_count_stmt->execute([$missing_address_email]);
    $client_count_after = (int) $client_count_stmt->fetchColumn();
    if ($client_count_after !== $client_count_before) {
        throw new RuntimeException('Failed booking attempts must not create a new client record.');
    }

    $booking_count_stmt->execute([$missing_address_email]);
    $booking_count_after = (int) $booking_count_stmt->fetchColumn();
    if ($booking_count_after !== $booking_count_before) {
        throw new RuntimeException('Failed booking attempts must not create a booking record.');
    }

    echo "✓ Failed public bookings do not create phantom clients or bookings\n";

    $mapped_form_email = 'mapped-form-' . $suffix . '@example.com';
    $conn->prepare("
        INSERT INTO form_templates (name, form_type, fields, is_internal, is_active)
        VALUES (?, 'client_form', ?, 0, 1)
    ")->execute([
        'Mapped Booking Fields ' . $suffix,
        json_encode([
            ['label' => 'Home Address', 'type' => 'text', 'required' => true, 'profile_mapping' => 'client.address'],
            ['label' => 'Dog Name', 'type' => 'text', 'required' => true, 'profile_mapping' => 'pet_1.name'],
        ], JSON_THROW_ON_ERROR),
    ]);
    $mapped_form_id = (int) $conn->lastInsertId();
    $cleanup['form_template_ids'][] = $mapped_form_id;

    $mapped_result = api_booking_create_booking($conn, [
        'client_name' => 'Mapped Form ' . $suffix,
        'client_email' => $mapped_form_email,
        'client_phone' => '555-1111',
        'service_type' => 'At Home Consultation',
        'appointment_date' => date('Y-m-d', strtotime('+6 days')),
        'appointment_time' => '14:00',
        'location_type' => 'client_address',
        'form_responses' => [
            $mapped_form_id => [
                0 => '123 Example Street',
                1 => 'Pixel',
            ],
        ],
    ]);

    if (($mapped_result['success'] ?? false) !== true) {
        throw new RuntimeException('Profile-mapped form values should allow public bookings to succeed.');
    }

    $mapped_booking_id = safe_int($mapped_result['booking_id'] ?? 0);
    if ($mapped_booking_id <= 0) {
        throw new RuntimeException('Successful mapped-form bookings should return a booking ID.');
    }
    $cleanup['booking_ids'][] = $mapped_booking_id;

    $mapped_client_stmt = $conn->prepare("SELECT id, address FROM clients WHERE email = ?");
    $mapped_client_stmt->execute([$mapped_form_email]);
    $mapped_client = $mapped_client_stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($mapped_client) || (string) ($mapped_client['address'] ?? '') !== '123 Example Street') {
        throw new RuntimeException('Mapped form address should be saved to the newly created client profile.');
    }
    $cleanup['client_ids'][] = (int) $mapped_client['id'];

    $mapped_booking_stmt = $conn->prepare("SELECT location, location_type FROM bookings WHERE id = ?");
    $mapped_booking_stmt->execute([$mapped_booking_id]);
    $mapped_booking = $mapped_booking_stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($mapped_booking)
        || (string) ($mapped_booking['location_type'] ?? '') !== 'client_address'
        || (string) ($mapped_booking['location'] ?? '') !== '123 Example Street'
    ) {
        throw new RuntimeException('Mapped form address should be used as the booking location.');
    }

    $pet_stmt = $conn->prepare("
        SELECT p.name
        FROM pets p
        JOIN appointment_pets ap ON ap.pet_id = p.id
        WHERE ap.booking_id = ?
        ORDER BY p.id ASC
        LIMIT 1
    ");
    $pet_stmt->execute([$mapped_booking_id]);
    $linked_pet_name = (string) $pet_stmt->fetchColumn();
    if ($linked_pet_name !== 'Pixel') {
        throw new RuntimeException('Mapped pet name should be used when creating the booking pet link.');
    }

    echo "✓ Profile-mapped required form values feed public booking location and pet summary data\n\n";
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

        if ($cleanup['form_template_ids'] !== []) {
            $placeholders = implode(',', array_fill(0, count($cleanup['form_template_ids']), '?'));
            $stmt = $conn->prepare("DELETE FROM form_templates WHERE id IN ($placeholders)");
            $stmt->execute($cleanup['form_template_ids']);
        }
    }
}
