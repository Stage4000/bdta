#!/usr/bin/env php
<?php
require_once __DIR__ . '/backend/includes/database.php';
require_once __DIR__ . '/backend/includes/moxie.php';

echo "=== Moxie Import Test ===\n\n";

try {
    $db = new Database();
    $conn = $db->getConnection();
    $sync = new MoxieClientSync($conn);
    $test_suffix = bin2hex(random_bytes(4));
    $primary_client_id = 'test-moxie-' . $test_suffix;
    $archived_client_id = 'test-moxie-archived-' . $test_suffix;
    $missing_email_client_id = 'test-moxie-no-email-' . $test_suffix;
    $primary_email = 'jane.doe.' . $test_suffix . '@example.invalid';

    $first_pass = [
        [
            'id' => $primary_client_id,
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
                    'email' => $primary_email,
                    'phone' => '+1-555-2000',
                    'defaultContact' => true,
                ],
            ],
        ],
        [
            'id' => $archived_client_id,
            'name' => 'Archived Client',
            'archive' => true,
            'contacts' => [
                [
                    'email' => 'archived.' . $test_suffix . '@example.invalid',
                    'defaultContact' => true,
                ],
            ],
        ],
        [
            'id' => $missing_email_client_id,
            'name' => 'No Email Client',
        ],
    ];

    $result = $sync->syncClientRows($first_pass);
    if ($result['created'] !== 1 || $result['skipped_archived'] !== 1 || $result['skipped_missing_email'] !== 1) {
        throw new RuntimeException('Unexpected first sync result: ' . json_encode($result));
    }

    $stmt = $conn->prepare("SELECT name, email, phone, address, notes, moxie_client_id FROM clients WHERE moxie_client_id = ?");
    $stmt->execute([$primary_client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client)) {
        throw new RuntimeException('Imported client was not created.');
    }

    if (scalar_string($client['email'] ?? '') !== $primary_email) {
        throw new RuntimeException('Default contact email was not imported.');
    }

    if (scalar_string($client['address'] ?? '') !== '123 Main St, Metropolis, FL, 33870, USA') {
        throw new RuntimeException('Address was not normalized as expected.');
    }

    $normalized_base_url = MoxieClientSync::normalizeBaseUrl('pod00.withmoxie.dev');
    if ($normalized_base_url !== 'https://pod00.withmoxie.dev') {
        throw new RuntimeException('Expected Moxie base URL to normalize to HTTPS workspace origin.');
    }

    $invalid_base_urls = [
        'http://pod00.withmoxie.dev',
        'https://example.com',
        'https://pod00.withmoxie.dev/path',
        'https://user:pass@pod00.withmoxie.dev',
    ];

    foreach ($invalid_base_urls as $invalid_base_url) {
        try {
            MoxieClientSync::normalizeBaseUrl($invalid_base_url);
            throw new RuntimeException('Expected invalid Moxie base URL to be rejected: ' . $invalid_base_url);
        } catch (InvalidArgumentException $e) {
            // Expected path
        }
    }

    $second_pass = [
        [
            'id' => $primary_client_id,
            'name' => 'Acme Corporation Updated',
            'phone' => '+1-555-9999',
            'notes' => 'Updated by Moxie',
            'contacts' => [
                [
                    'firstName' => 'Jane',
                    'lastName' => 'Doe',
                    'email' => $primary_email,
                    'defaultContact' => true,
                ],
            ],
        ],
    ];

    $result = $sync->syncClientRows($second_pass);
    if ($result['updated'] !== 1) {
        throw new RuntimeException('Expected one updated client on second sync: ' . json_encode($result));
    }

    $stmt->execute([$primary_client_id]);
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
    echo "✓ Moxie base URL validation restricts allowed origins\n";
    echo "✓ Repeated syncs are idempotent for unchanged clients\n\n";
    echo "=== All Moxie Import Tests Passed! ===\n";
} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    if (isset($conn) && $conn instanceof PDO) {
        $cleanup_ids = [
            $primary_client_id ?? '',
            $archived_client_id ?? '',
            $missing_email_client_id ?? '',
        ];
        if (!in_array('', $cleanup_ids, true)) {
            $cleanup = $conn->prepare("DELETE FROM clients WHERE moxie_client_id IN (?, ?, ?)");
            $cleanup->execute($cleanup_ids);
        }
    }
}
