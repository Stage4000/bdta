#!/usr/bin/env php
<?php
/**
 * Test: Bundled Package System
 * Tests the full lifecycle: define package → assign to client → book → consume credit → verify breakdown
 * Also validates Field Rental appointment type support.
 */

require_once __DIR__ . '/backend/includes/database.php';

echo "=== Bundled Package System Test ===\n\n";

try {
    $db = new Database();
    $conn = $db->getConnection();
    $db_type = $db->getDatabaseType();

    echo "Testing with: " . strtoupper($db_type) . "\n";
    echo str_repeat('-', 50) . "\n\n";

    // ------------------------------------------------------------------
    // Test 1: Field Rental appointment type
    // ------------------------------------------------------------------
    echo "Test 1: Create Field Rental appointment type\n";

    do {
        $unique_link = bin2hex(random_bytes(16));
        $ck = $conn->prepare("SELECT COUNT(*) FROM appointment_types WHERE unique_link = ?");
        $ck->execute([$unique_link]);
    } while ($ck->fetchColumn() > 0);

    $stmt = $conn->prepare("
        INSERT INTO appointment_types
            (name, description, duration_minutes, buffer_before_minutes, buffer_after_minutes,
             advance_booking_min_days, advance_booking_max_days,
             requires_forms, requires_contract, auto_invoice, invoice_due_days,
             consumes_credits, credit_count, is_group_class, max_participants,
             is_active, unique_link, is_field_rental, field_rental_location)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        'Test Field Rental', 'A rental field for testing', 60,
        0, 0, 1, 90, 0, 0, 0, 7,
        1, 1, 0, 1, 1, $unique_link, 1, '123 Test Field Lane'
    ]);
    $field_rental_type_id = $conn->lastInsertId();

    $stmt = $conn->prepare("SELECT * FROM appointment_types WHERE id = ?");
    $stmt->execute([$field_rental_type_id]);
    $apt = $stmt->fetch(PDO::FETCH_ASSOC);

    assert($apt['is_field_rental'] == 1,       'is_field_rental should be 1');
    assert($apt['field_rental_location'] === '123 Test Field Lane', 'field_rental_location mismatch');
    assert($apt['consumes_credits'] == 1,       'should consume credits');
    echo "  ✓ Field Rental appointment type created (ID: $field_rental_type_id)\n\n";

    // ------------------------------------------------------------------
    // Test 2: Create a bundled package
    // ------------------------------------------------------------------
    echo "Test 2: Create bundled package (1 group + 2 mini + 3 field rentals)\n";

    $stmt = $conn->prepare("INSERT INTO packages (name, description, price, expiration_days, is_active) VALUES (?,?,?,?,?)");
    $stmt->execute(['Test Bundle', 'Mixed package for testing', 150.00, 90, 1]);
    $package_id = $conn->lastInsertId();

    $item_stmt = $conn->prepare("INSERT INTO package_items (package_id, session_type, quantity) VALUES (?,?,?)");
    $item_stmt->execute([$package_id, 'group',        1]);
    $item_stmt->execute([$package_id, 'mini',         2]);
    $item_stmt->execute([$package_id, 'field_rental', 3]);

    $stmt = $conn->prepare("SELECT * FROM package_items WHERE package_id = ? ORDER BY session_type");
    $stmt->execute([$package_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    assert(count($items) === 3, 'Expected 3 package items');
    $qtys = array_column($items, 'quantity', 'session_type');
    assert($qtys['group']        == 1, 'group qty mismatch');
    assert($qtys['mini']         == 2, 'mini qty mismatch');
    assert($qtys['field_rental'] == 3, 'field_rental qty mismatch');
    echo "  ✓ Package created with 1 group, 2 mini, 3 field_rental credits\n\n";

    // ------------------------------------------------------------------
    // Test 3: Create test client + assign package
    // ------------------------------------------------------------------
    echo "Test 3: Assign package to client\n";

    $stmt = $conn->prepare("INSERT INTO clients (name, email) VALUES (?,?)");
    $stmt->execute(['Package Test Client', 'pkgtest@example.com']);
    $client_id = $conn->lastInsertId();

    $expires_at = date('Y-m-d H:i:s', strtotime('+90 days'));
    $stmt = $conn->prepare("
        INSERT INTO client_packages (client_id, package_id, package_name, expires_at, is_active, notes, created_by)
        VALUES (?,?,?,?,1,?,1)
    ");
    $stmt->execute([$client_id, $package_id, 'Test Bundle', $expires_at, 'Test purchase']);
    $cp_id = $conn->lastInsertId();

    $credit_stmt = $conn->prepare("INSERT INTO client_package_credits (client_package_id, client_id, session_type, total_credits, used_credits) VALUES (?,?,?,?,0)");
    foreach ($items as $item) {
        $credit_stmt->execute([$cp_id, $client_id, $item['session_type'], $item['quantity']]);
    }

    // Log purchase transactions
    $stmt = $conn->prepare("SELECT * FROM client_package_credits WHERE client_package_id = ?");
    $stmt->execute([$cp_id]);
    $cred_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tx_stmt = $conn->prepare("INSERT INTO package_credit_transactions (client_package_credit_id, client_id, session_type, transaction_type, amount, notes, created_by) VALUES (?,?,?,'purchase',?,?,1)");
    foreach ($cred_rows as $cred) {
        $tx_stmt->execute([$cred['id'], $client_id, $cred['session_type'], $cred['total_credits'], 'Package purchase']);
    }

    assert(count($cred_rows) === 3, 'Expected 3 credit rows');
    echo "  ✓ Package assigned; client has 1 group, 2 mini, 3 field_rental credits\n\n";

    // ------------------------------------------------------------------
    // Test 4: Eligibility check – only field_rental credits for field rental booking
    // ------------------------------------------------------------------
    echo "Test 4: Eligibility check - field_rental credit must match appointment session_type\n";

    // Simulate the eligibility query from bookings_create.php
    $session_type = 'field_rental'; // from getSessionType() for is_field_rental=1
    $stmt = $conn->prepare("
        SELECT cpc.id, cpc.session_type, (cpc.total_credits - cpc.used_credits) AS remaining, cp.package_name
        FROM client_package_credits cpc
        JOIN client_packages cp ON cpc.client_package_id = cp.id
        WHERE cpc.client_id = ?
          AND cpc.session_type = ?
          AND cp.is_active = 1
          AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
          AND (cpc.total_credits - cpc.used_credits) > 0
        ORDER BY cp.expires_at ASC
    ");
    $stmt->execute([$client_id, $session_type]);
    $eligible = $stmt->fetchAll(PDO::FETCH_ASSOC);

    assert(count($eligible) === 1,                  'Should find 1 eligible field_rental credit row');
    assert($eligible[0]['remaining'] == 3,          'Should show 3 remaining field_rental credits');
    assert($eligible[0]['session_type'] === 'field_rental', 'session_type must be field_rental');

    // Verify group credits are NOT returned for field_rental booking
    $stmt2 = $conn->prepare("
        SELECT COUNT(*) FROM client_package_credits cpc
        JOIN client_packages cp ON cpc.client_package_id = cp.id
        WHERE cpc.client_id = ? AND cpc.session_type = 'group'
          AND cp.is_active = 1 AND (cpc.total_credits - cpc.used_credits) > 0
    ");
    $stmt2->execute([$client_id]);
    $group_count = $stmt2->fetchColumn();
    assert($group_count == 1, 'Group credits should exist but not be returned for field_rental booking');

    echo "  ✓ Only field_rental credits eligible for field rental booking\n";
    echo "  ✓ Group credits correctly excluded from field rental eligibility\n\n";

    // ------------------------------------------------------------------
    // Test 5: Consume a field_rental credit on booking
    // ------------------------------------------------------------------
    echo "Test 5: Consume a field_rental credit\n";

    $cpc_id = $eligible[0]['id'];

    // Simulate booking creation
    $stmt = $conn->prepare("
        INSERT INTO bookings (client_id, appointment_type_id, client_name, client_email,
            appointment_date, appointment_time, service_type, status, package_credit_id, created_at)
        VALUES (?,?,?,?,?,?,?,'confirmed',?,datetime('now'))
    ");
    $stmt->execute([$client_id, $field_rental_type_id, 'Package Test Client', 'pkgtest@example.com',
        date('Y-m-d', strtotime('+7 days')), '10:00', 'Test Field Rental', $cpc_id]);
    $booking_id = $conn->lastInsertId();

    // Consume credit
    $conn->prepare("UPDATE client_package_credits SET used_credits = used_credits + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$cpc_id]);

    // Log transaction
    $conn->prepare("INSERT INTO package_credit_transactions (client_package_credit_id, client_id, session_type, transaction_type, amount, booking_id, notes, created_by) VALUES (?,?,?,'consume',-1,?,'Consumed by booking',1)")->execute([$cpc_id, $client_id, 'field_rental', $booking_id]);

    // Verify remaining balance
    $stmt = $conn->prepare("SELECT total_credits, used_credits FROM client_package_credits WHERE id = ?");
    $stmt->execute([$cpc_id]);
    $cred = $stmt->fetch(PDO::FETCH_ASSOC);

    assert($cred['used_credits']  == 1, 'used_credits should be 1');
    assert(($cred['total_credits'] - $cred['used_credits']) == 2, 'remaining should be 2');
    echo "  ✓ Credit consumed: 1 used, 2 remaining for field_rental\n\n";

    // ------------------------------------------------------------------
    // Test 6: Credit breakdown summary
    // ------------------------------------------------------------------
    echo "Test 6: Summary shows correct breakdown across all types\n";

    $stmt = $conn->prepare("
        SELECT cpc.session_type,
               SUM(cpc.total_credits) AS total,
               SUM(cpc.used_credits)  AS used,
               SUM(cpc.total_credits - cpc.used_credits) AS remaining
        FROM client_package_credits cpc
        JOIN client_packages cp ON cpc.client_package_id = cp.id
        WHERE cpc.client_id = ? AND cp.is_active = 1
          AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
        GROUP BY cpc.session_type
        ORDER BY cpc.session_type
    ");
    $stmt->execute([$client_id]);
    $summary = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'session_type');

    assert($summary['group']['remaining']        == 1, 'group remaining mismatch');
    assert($summary['mini']['remaining']         == 2, 'mini remaining mismatch');
    assert($summary['field_rental']['remaining'] == 2, 'field_rental remaining mismatch (after 1 consumed)');
    echo "  ✓ Summary: group=1, mini=2, field_rental=2 remaining\n\n";

    // ------------------------------------------------------------------
    // Test 7: Misuse prevention – group credit cannot be used for field_rental
    // ------------------------------------------------------------------
    echo "Test 7: Prevent misuse - group credit rejected for field_rental booking\n";

    $stmt = $conn->prepare("SELECT id FROM client_package_credits WHERE client_package_id = ? AND session_type = 'group'");
    $stmt->execute([$cp_id]);
    $group_cred = $stmt->fetch(PDO::FETCH_ASSOC);

    // Simulate validation from bookings_create.php
    $appointment_session_type = 'field_rental';
    $stmt = $conn->prepare("
        SELECT cpc.id FROM client_package_credits cpc
        JOIN client_packages cp ON cpc.client_package_id = cp.id
        WHERE cpc.id = ? AND cpc.client_id = ?
          AND cpc.session_type = ?
          AND cp.is_active = 1
          AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$group_cred['id'], $client_id, $appointment_session_type]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    assert($result === false, 'Group credit must NOT validate for field_rental appointment');
    echo "  ✓ Group credit correctly rejected for field_rental booking\n\n";

    // ------------------------------------------------------------------
    // Test 8: Expired package credits are excluded
    // ------------------------------------------------------------------
    echo "Test 8: Expired package credits excluded from eligibility\n";

    // Expire the client package
    $conn->prepare("UPDATE client_packages SET expires_at = ? WHERE id = ?")->execute([
        date('Y-m-d H:i:s', strtotime('-1 day')), $cp_id
    ]);

    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM client_package_credits cpc
        JOIN client_packages cp ON cpc.client_package_id = cp.id
        WHERE cpc.client_id = ? AND cp.is_active = 1
          AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
          AND (cpc.total_credits - cpc.used_credits) > 0
    ");
    $stmt->execute([$client_id]);
    $count = $stmt->fetchColumn();

    assert($count == 0, 'Expired package should return 0 eligible credits');
    echo "  ✓ Expired package credits correctly excluded\n\n";

    // ------------------------------------------------------------------
    // Cleanup
    // ------------------------------------------------------------------
    echo "Cleanup...\n";
    $conn->prepare("DELETE FROM package_credit_transactions WHERE client_id = ?")->execute([$client_id]);
    $conn->prepare("DELETE FROM client_package_credits WHERE client_id = ?")->execute([$client_id]);
    $conn->prepare("DELETE FROM bookings WHERE client_id = ?")->execute([$client_id]);
    $conn->prepare("DELETE FROM client_packages WHERE client_id = ?")->execute([$client_id]);
    $conn->prepare("DELETE FROM clients WHERE id = ?")->execute([$client_id]);
    $conn->prepare("DELETE FROM package_items WHERE package_id = ?")->execute([$package_id]);
    $conn->prepare("DELETE FROM packages WHERE id = ?")->execute([$package_id]);
    $conn->prepare("DELETE FROM appointment_types WHERE id = ?")->execute([$field_rental_type_id]);
    echo "  ✓ Test data cleaned up\n\n";

    echo str_repeat('=', 50) . "\n";
    echo "✓ ALL PACKAGE TESTS PASSED!\n";
    echo "Database: " . strtoupper($db_type) . "\n";

} catch (Exception $e) {
    echo "\n✗ TEST FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
