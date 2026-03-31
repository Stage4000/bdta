#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/config.php';
require_once dirname(__DIR__) . '/backend/includes/public_contact_form.php';

echo "=== Public Contact Form Test ===\n\n";

$db = new Database();
$conn = $db->getConnection();

$created_client_id = 0;
$existing_client_id = 0;
$duplicate_client_id = 0;

try {
    $suffix = bin2hex(random_bytes(4));

    $new_payload = [
        'name' => 'Contact New ' . $suffix,
        'email' => 'Contact-New-' . $suffix . '@Example.com',
        'phone' => '555-1100',
        'service' => 'pet-sitting',
        'message' => 'Need help with training basics.',
    ];

    $new_result = bdta_handle_public_contact_submission($conn, $new_payload);
    if ($new_result['success'] !== true) {
        throw new RuntimeException('Expected new contact submission to succeed.');
    }

    $created_client_id = safe_int($new_result['client_id'] ?? 0);
    if ($created_client_id <= 0) {
        throw new RuntimeException('Expected new contact submission to create a client.');
    }

    $stmt = $conn->prepare("SELECT name, email, phone, notes FROM clients WHERE id = ?");
    $stmt->execute([$created_client_id]);
    $created_client = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));

    if (
        array_string_value($created_client, 'name') !== $new_payload['name']
        || array_string_value($created_client, 'email') !== strtolower($new_payload['email'])
        || array_string_value($created_client, 'phone') !== $new_payload['phone']
        || strpos(array_string_value($created_client, 'notes'), 'Message: ' . $new_payload['message']) === false
    ) {
        throw new RuntimeException('Created client record did not contain expected contact form values.');
    }

    echo "✓ New contact form submission creates a client with message in notes\n";

    $existing_email = 'contact-existing-' . $suffix . '@example.com';
    $duplicate_stmt = $conn->prepare("
        INSERT INTO clients (name, email, phone, notes, created_at, updated_at)
        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $duplicate_stmt->execute(['Older Duplicate', $existing_email, '555-9999', 'Old duplicate note']);
    $duplicate_client_id = safe_int($conn->lastInsertId());

    $seed_stmt = $conn->prepare("
        INSERT INTO clients (name, email, phone, notes, created_at, updated_at)
        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $seed_stmt->execute(['Old Name', $existing_email, '555-0000', 'Existing note']);
    $existing_client_id = safe_int($conn->lastInsertId());

    $existing_payload = [
        'name' => 'Attempted Update Name ' . $suffix,
        'email' => strtoupper($existing_email),
        'phone' => '555-2200',
        'service' => 'walking',
        'message' => 'Second message from existing contact.',
    ];
    $existing_result = bdta_handle_public_contact_submission($conn, $existing_payload);
    if ($existing_result['success'] !== true) {
        throw new RuntimeException('Expected existing contact submission to succeed.');
    }

    if (safe_int($existing_result['client_id'] ?? 0) !== $existing_client_id) {
        throw new RuntimeException('Existing contact submission should reuse the existing client id.');
    }

    $stmt->execute([$existing_client_id]);
    $updated_client = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));

    $updated_notes = array_string_value($updated_client, 'notes');
    if (
        array_string_value($updated_client, 'name') !== 'Old Name'
        || array_string_value($updated_client, 'phone') !== '555-0000'
        || strpos($updated_notes, 'Existing note') === false
        || strpos($updated_notes, 'Message: ' . $existing_payload['message']) === false
    ) {
        throw new RuntimeException('Existing client update did not preserve/append notes correctly.');
    }

    $stmt->execute([$duplicate_client_id]);
    $duplicate_client = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
    if (strpos(array_string_value($duplicate_client, 'notes'), 'Second message from existing contact.') !== false) {
        throw new RuntimeException('Only the most recent duplicate email record should be updated.');
    }

    echo "✓ Existing client lookup is case-insensitive, deterministic, and only appends notes\n";

    $invalid_result = bdta_handle_public_contact_submission($conn, [
        'name' => '',
        'email' => 'not-an-email',
        'message' => '',
    ]);
    if ($invalid_result['success'] !== false) {
        throw new RuntimeException('Invalid contact payload should fail validation.');
    }

    echo "✓ Invalid contact payload is rejected\n";
    echo "\n=== All Tests Passed! ===\n";
} catch (Throwable $e) {
    echo "\n✗ Test failed: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    foreach ([$created_client_id, $existing_client_id, $duplicate_client_id] as $client_id) {
        if ($client_id > 0) {
            $cleanup_stmt = $conn->prepare("DELETE FROM clients WHERE id = ?");
            $cleanup_stmt->execute([$client_id]);
        }
    }
}
