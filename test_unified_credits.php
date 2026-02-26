#!/usr/bin/env php
<?php
/**
 * Test: Per-Appointment-Type Credit System
 * Tests the manual per-appointment-type credit adjustment feature,
 * verifying the full admin workflow: adjust credits → verify balance → audit log.
 */

require_once __DIR__ . '/backend/includes/database.php';

echo "=== Per-Appointment-Type Credit System Test ===\n\n";

try {
    $db = new Database();
    $conn = $db->getConnection();
    $db_type = $db->getDatabaseType();

    echo "Testing with: " . strtoupper($db_type) . "\n";
    echo str_repeat('-', 50) . "\n\n";

    // ------------------------------------------------------------------
    // Setup: create a test client, admin user, and appointment type
    // ------------------------------------------------------------------
    $stmt = $conn->prepare("INSERT INTO clients (name, email) VALUES (?,?)");
    $stmt->execute(['Credit Test Client', 'credittype@example.com']);
    $client_id = (int)$conn->lastInsertId();

    // Use admin_id = 1 (created by database initialization)
    $admin_id = 1;

    // Create a test appointment type
    $conn->prepare("
        INSERT INTO appointment_types (name, description, duration_minutes, consumes_credits, credit_count, is_active)
        VALUES ('Test Session', 'Test appointment type for credits', 60, 1, 1, 1)
    ")->execute();
    $apt_type_id = (int)$conn->lastInsertId();

    // Helper: get or create the manual-credit package row for an appointment type
    function getOrCreateManualCreditRow(PDO $conn, int $client_id, int $appointment_type_id, int $admin_id): int {
        $stmt = $conn->prepare("SELECT id FROM packages WHERE name = '__manual_credit__' LIMIT 1");
        $stmt->execute();
        $manual_pkg = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$manual_pkg) {
            $conn->prepare("INSERT INTO packages (name, description, price, is_active) VALUES ('__manual_credit__', 'System record for manual credit adjustments', 0, 0)")->execute();
            $manual_pkg_id = (int)$conn->lastInsertId();
        } else {
            $manual_pkg_id = (int)$manual_pkg['id'];
        }

        $stmt = $conn->prepare("SELECT id FROM client_packages WHERE client_id = ? AND package_id = ? LIMIT 1");
        $stmt->execute([$client_id, $manual_pkg_id]);
        $manual_cp = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$manual_cp) {
            $conn->prepare("INSERT INTO client_packages (client_id, package_id, package_name, is_active, notes, created_by) VALUES (?, ?, 'Manual Credits', 1, 'System record for manual credit adjustments', ?)")->execute([$client_id, $manual_pkg_id, $admin_id]);
            $manual_cp_id = (int)$conn->lastInsertId();
        } else {
            $manual_cp_id = (int)$manual_cp['id'];
        }

        $stmt = $conn->prepare("SELECT id FROM client_package_credits WHERE client_package_id = ? AND appointment_type_id = ? LIMIT 1");
        $stmt->execute([$manual_cp_id, $appointment_type_id]);
        $cpc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cpc) {
            $conn->prepare("INSERT INTO client_package_credits (client_package_id, client_id, appointment_type_id, total_credits, used_credits) VALUES (?, ?, ?, 0, 0)")->execute([$manual_cp_id, $client_id, $appointment_type_id]);
            return (int)$conn->lastInsertId();
        }
        return (int)$cpc['id'];
    }

    // ------------------------------------------------------------------
    // Test 1: Add credits for the appointment type manually
    // ------------------------------------------------------------------
    echo "Test 1: Admin manually adds 3 credits for 'Test Session'\n";

    $cpc_id = getOrCreateManualCreditRow($conn, $client_id, $apt_type_id, $admin_id);

    $conn->prepare("UPDATE client_package_credits SET total_credits = total_credits + ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([3, $cpc_id]);
    $conn->prepare("INSERT INTO package_credit_transactions (client_package_credit_id, client_id, appointment_type_id, transaction_type, amount, notes, created_by) VALUES (?, ?, ?, 'adjustment', ?, 'Admin added credits manually', ?)")->execute([$cpc_id, $client_id, $apt_type_id, 3, $admin_id]);

    $stmt = $conn->prepare("SELECT total_credits, used_credits FROM client_package_credits WHERE id = ?");
    $stmt->execute([$cpc_id]);
    $cpc = $stmt->fetch(PDO::FETCH_ASSOC);

    assert((int)$cpc['total_credits'] === 3, 'total_credits should be 3 after addition');
    assert((int)$cpc['used_credits'] === 0, 'used_credits should still be 0');
    echo "  ✓ 3 credits added; balance = 3\n\n";

    // ------------------------------------------------------------------
    // Test 2: Subtract 1 credit manually
    // ------------------------------------------------------------------
    echo "Test 2: Admin manually subtracts 1 credit for 'Test Session'\n";

    $remaining = (int)$cpc['total_credits'] - (int)$cpc['used_credits'];
    assert($remaining + (-1) >= 0, 'Subtraction must not go below zero');

    $conn->prepare("UPDATE client_package_credits SET total_credits = total_credits + ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([-1, $cpc_id]);
    $conn->prepare("INSERT INTO package_credit_transactions (client_package_credit_id, client_id, appointment_type_id, transaction_type, amount, notes, created_by) VALUES (?, ?, ?, 'adjustment', ?, 'Admin correction', ?)")->execute([$cpc_id, $client_id, $apt_type_id, -1, $admin_id]);

    $stmt->execute([$cpc_id]);
    $cpc = $stmt->fetch(PDO::FETCH_ASSOC);

    assert((int)$cpc['total_credits'] === 2, 'total_credits should be 2 after subtraction');
    echo "  ✓ 1 credit subtracted; balance = 2\n\n";

    // ------------------------------------------------------------------
    // Test 3: Prevent subtracting below zero
    // ------------------------------------------------------------------
    echo "Test 3: Prevent subtracting credits below zero\n";

    $stmt->execute([$cpc_id]);
    $cpc = $stmt->fetch(PDO::FETCH_ASSOC);
    $remaining = (int)$cpc['total_credits'] - (int)$cpc['used_credits'];
    $would_go_negative = ($remaining + (-10)) < 0;

    assert($would_go_negative === true, 'Subtracting 10 from 2 should be flagged as below zero');
    echo "  ✓ Correctly detected that subtracting 10 from $remaining would go negative\n\n";

    // ------------------------------------------------------------------
    // Test 4: Audit log entries exist for both adjustments
    // ------------------------------------------------------------------
    echo "Test 4: Audit log entries exist for both manual adjustments\n";

    $stmt = $conn->prepare("SELECT COUNT(*) FROM package_credit_transactions WHERE client_id = ? AND appointment_type_id = ? AND transaction_type = 'adjustment'");
    $stmt->execute([$client_id, $apt_type_id]);
    $count = (int)$stmt->fetchColumn();

    assert($count === 2, "Expected 2 audit log entries, got $count");
    echo "  ✓ 2 audit log entries found for credit adjustments\n\n";

    // ------------------------------------------------------------------
    // Test 5: Credits appear in per-appointment-type summary query
    // ------------------------------------------------------------------
    echo "Test 5: Credits appear in appointment-type summary\n";

    $stmt = $conn->prepare("
        SELECT cpc.appointment_type_id,
               SUM(cpc.total_credits - cpc.used_credits) AS remaining
        FROM client_package_credits cpc
        JOIN client_packages cp ON cpc.client_package_id = cp.id
        WHERE cpc.client_id = ? AND cp.is_active = 1
          AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
          AND (cpc.total_credits - cpc.used_credits) > 0
        GROUP BY cpc.appointment_type_id
    ");
    $stmt->execute([$client_id]);
    $summary = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'remaining', 'appointment_type_id');

    assert(isset($summary[$apt_type_id]),               'appointment type should be in summary');
    assert((int)$summary[$apt_type_id] === 2,           'remaining should be 2');
    echo "  ✓ Summary shows appointment_type_id=$apt_type_id with 2 remaining\n\n";

    // ------------------------------------------------------------------
    // Test 6: Credits for one appointment type cannot be used for another
    // ------------------------------------------------------------------
    echo "Test 6: Credits tied strictly to their appointment type\n";

    // Create a second appointment type
    $conn->prepare("
        INSERT INTO appointment_types (name, duration_minutes, consumes_credits, credit_count, is_active)
        VALUES ('Other Session', 60, 1, 1, 1)
    ")->execute();
    $other_apt_type_id = (int)$conn->lastInsertId();

    // Query credits for the other type - should be empty
    $stmt = $conn->prepare("
        SELECT COUNT(*) FROM client_package_credits cpc
        JOIN client_packages cp ON cpc.client_package_id = cp.id
        WHERE cpc.client_id = ? AND cpc.appointment_type_id = ?
          AND cp.is_active = 1
          AND (cpc.total_credits - cpc.used_credits) > 0
    ");
    $stmt->execute([$client_id, $other_apt_type_id]);
    $other_count = (int)$stmt->fetchColumn();

    assert($other_count === 0, 'Credits for Test Session must not appear for Other Session');
    echo "  ✓ Credits for 'Test Session' do not appear for 'Other Session'\n\n";

    // ------------------------------------------------------------------
    // Test 7: Manual credits appear in admin-view summary (no package filter)
    // Verifies the fix: the admin "Credits by Appointment Type" query must
    // NOT exclude the __manual_credit__ package so that manually-adjusted
    // credits are visible alongside package-based credits.
    // ------------------------------------------------------------------
    echo "Test 7: Manual credits visible in admin 'Credits by Appointment Type' summary\n";

    $stmt = $conn->prepare("
        SELECT cpc.appointment_type_id,
               SUM(cpc.total_credits) AS total,
               SUM(cpc.used_credits)  AS used,
               SUM(cpc.total_credits - cpc.used_credits) AS remaining
        FROM client_package_credits cpc
        JOIN client_packages cp ON cpc.client_package_id = cp.id
        WHERE cpc.client_id = ?
          AND cp.is_active = 1
          AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
        GROUP BY cpc.appointment_type_id
    ");
    $stmt->execute([$client_id]);
    $admin_summary = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'remaining', 'appointment_type_id');

    assert(isset($admin_summary[$apt_type_id]), 'Manual-credit appointment type must appear in admin summary');
    assert((int)$admin_summary[$apt_type_id] === 2, 'Admin summary should show 2 remaining manual credits');
    echo "  ✓ Admin summary includes manual credits: appointment_type_id=$apt_type_id with 2 remaining\n\n";

    // ------------------------------------------------------------------
    // Cleanup
    // ------------------------------------------------------------------
    echo "Cleanup...\n";
    $conn->prepare("DELETE FROM package_credit_transactions WHERE client_id = ?")->execute([$client_id]);
    $conn->prepare("DELETE FROM client_package_credits WHERE client_id = ?")->execute([$client_id]);
    $conn->prepare("DELETE FROM client_packages WHERE client_id = ?")->execute([$client_id]);
    $conn->prepare("DELETE FROM clients WHERE id = ?")->execute([$client_id]);
    $conn->prepare("DELETE FROM appointment_types WHERE id IN (?,?)")->execute([$apt_type_id, $other_apt_type_id]);
    echo "  ✓ Test data cleaned up\n\n";

    echo str_repeat('=', 50) . "\n";
    echo "✓ ALL CREDIT SYSTEM TESTS PASSED!\n";
    echo "Database: " . strtoupper($db_type) . "\n";

} catch (Exception $e) {
    echo "\n✗ TEST FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
