#!/usr/bin/env php
<?php
require_once __DIR__ . '/backend/includes/database.php';
require_once __DIR__ . '/backend/includes/moxie.php';

echo "=== Moxie Import Test ===\n\n";

try {
    $db = new Database();
    $conn = $db->getConnection();
    $sync = new MoxieClientSync($conn);

    $conn->exec("DELETE FROM clients");

    $first_pass = [
        [
            'id' => 'moxie-100',
            'name' => 'Acme Corporation',
            'phone' => '+1-555-1000',
            'address1' => '123 Main St',
            'city' => 'Metropolis',
            'locality' => 'FL',
            'postal' => '33870',
            'country' => 'USA',
            'notes' => 'Priority client',
            'contacts' => [
                [
                    'firstName' => 'Jane',
                    'lastName' => 'Doe',
                    'email' => 'jane.doe@acme.test',
                    'phone' => '+1-555-2000',
                    'defaultContact' => true,
                ],
            ],
        ],
        [
            'id' => 'moxie-archived',
            'name' => 'Archived Client',
            'archive' => true,
            'contacts' => [
                [
                    'email' => 'archived@example.test',
                    'defaultContact' => true,
                ],
            ],
        ],
        [
            'id' => 'moxie-no-email',
            'name' => 'No Email Client',
        ],
    ];

    $result = $sync->syncClientRows($first_pass);
    if ($result['created'] !== 1 || $result['skipped_archived'] !== 1 || $result['skipped_missing_email'] !== 1) {
        throw new RuntimeException('Unexpected first sync result: ' . json_encode($result));
    }

    $stmt = $conn->prepare("SELECT name, email, phone, address, notes, moxie_client_id FROM clients WHERE moxie_client_id = ?");
    $stmt->execute(['moxie-100']);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client)) {
        throw new RuntimeException('Imported client was not created.');
    }

    if (scalar_string($client['email'] ?? '') !== 'jane.doe@acme.test') {
        throw new RuntimeException('Default contact email was not imported.');
    }

    if (scalar_string($client['address'] ?? '') !== '123 Main St, Metropolis, FL, 33870, USA') {
        throw new RuntimeException('Address was not normalized as expected.');
    }

    $second_pass = [
        [
            'id' => 'moxie-100',
            'name' => 'Acme Corporation Updated',
            'phone' => '+1-555-9999',
            'notes' => 'Updated by Moxie',
            'contacts' => [
                [
                    'firstName' => 'Jane',
                    'lastName' => 'Doe',
                    'email' => 'jane.doe@acme.test',
                    'defaultContact' => true,
                ],
            ],
        ],
    ];

    $result = $sync->syncClientRows($second_pass);
    if ($result['updated'] !== 1) {
        throw new RuntimeException('Expected one updated client on second sync: ' . json_encode($result));
    }

    $stmt->execute(['moxie-100']);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client) || scalar_string($client['name'] ?? '') !== 'Acme Corporation Updated') {
        throw new RuntimeException('Existing client was not updated.');
    }

    $result = $sync->syncClientRows($second_pass);
    if ($result['unchanged'] !== 1) {
        throw new RuntimeException('Expected unchanged client on identical sync: ' . json_encode($result));
    }

    echo "✓ Moxie import client creation works\n";
    echo "✓ Archived and missing-email clients are skipped\n";
    echo "✓ Existing clients update by Moxie client ID\n";
    echo "✓ Repeated syncs are idempotent for unchanged clients\n\n";
    echo "=== All Moxie Import Tests Passed! ===\n";
} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    if (isset($conn) && $conn instanceof PDO) {
        $conn->exec("DELETE FROM clients");
    }
}
