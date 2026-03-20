<?php
/**
 * Public contact form submission helper.
 */

/**
 * @param array<string, mixed> $payload
 * @return array{success:bool,error?:string,client_id?:int}
 */
function bdta_handle_public_contact_submission(PDO $conn, array $payload): array {
    $name = trim(array_string_value($payload, 'name'));
    $email = trim(array_string_value($payload, 'email'));
    $phone = trim(array_string_value($payload, 'phone'));
    $message = trim(array_string_value($payload, 'message'));
    $service = trim(array_string_value($payload, 'service'));

    if ($name === '' || $email === '' || $message === '') {
        return ['success' => false, 'error' => 'Name, email, and message are required.'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please enter a valid email address.'];
    }

    $notes_parts = [
        'Public contact form message submitted on ' . currentUtcDateTime(),
    ];
    if ($service !== '') {
        $notes_parts[] = 'Service interested in: ' . $service;
    }
    $notes_parts[] = 'Message: ' . $message;
    $contact_note = implode("\n", $notes_parts);

    $stmt = $conn->prepare("SELECT id, notes FROM clients WHERE email = ?");
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $existing_row = assoc_row($existing);

    if ($existing_row !== []) {
        $client_id = array_int_value($existing_row, 'id');
        $existing_notes = trim(array_string_value($existing_row, 'notes'));
        $merged_notes = $existing_notes === ''
            ? $contact_note
            : ($existing_notes . "\n\n" . $contact_note);

        $stmt = $conn->prepare("
            UPDATE clients
            SET name = ?, phone = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$name, $phone, $merged_notes, $client_id]);

        return ['success' => true, 'client_id' => $client_id];
    }

    $stmt = $conn->prepare("
        INSERT INTO clients (name, email, phone, notes, created_at, updated_at)
        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$name, $email, $phone, $contact_note]);

    return ['success' => true, 'client_id' => safe_int($conn->lastInsertId())];
}
