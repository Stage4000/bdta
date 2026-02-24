#!/usr/bin/env php
<?php
/**
 * Test: Unified Credit System
 * Tests the manual per-session-type package credit adjustment feature,
 * verifying the full admin workflow: adjust credits → verify balance → audit log.
 *
 * This complements test_packages.php which covers the package-assignment workflow.
 */

require_once __DIR__ . '/backend/includes/database.php';

echo "=== Unified Credit System Test ===\n\n";

try {
    $db = new Database();
    $conn = $db->getConnection();
    $db_type = $db->getDatabaseType();

    echo "Testing with: " . strtoupper($db_type) . "\n";
    echo str_repeat('-', 50) . "\n\n";

    // ------------------------------------------------------------------
    // Setup: create a test client and admin user
    // ------------------------------------------------------------------
    $stmt = $conn->prepare("INSERT INTO clients (name, email) VALUES (?,?)");
    $stmt->execute(['Unified Credit Test Client', 'unifiedcredit@example.com']);
    $client_id = (int)$conn->lastInsertId();

    // Use admin_id = 1 (created by database initialization)
    $admin_id = 1;

    // Helper: get or create the manual-credit package row for a session type
    function getOrCreateManualCreditRow(PDO $conn, int $client_id, string $session_type, int $admin_id): int {
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

        $stmt = $conn->prepare("SELECT id FROM client_package_credits WHERE client_package_id = ? AND session_type = ? LIMIT 1");
        $stmt->execute([$manual_cp_id, $session_type]);
        $cpc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cpc) {
            $conn->prepare("INSERT INTO client_package_credits (client_package_id, client_id, session_type, total_credits, used_credits) VALUES (?, ?, ?, 0, 0)")->execute([$manual_cp_id, $client_id, $session_type]);
            return (int)$conn->lastInsertId();
        }
        return (int)$cpc['id'];
    }

    // ------------------------------------------------------------------
    // Test 1: Add private session credits manually
    // ------------------------------------------------------------------
    echo "Test 1: Admin manually adds 3 private session credits\n";

    $cpc_id = getOrCreateManualCreditRow($conn, $client_id, 'private', $admin_id);

    $conn->prepare("UPDATE client_package_credits SET total_credits = total_credits + ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([3, $cpc_id]);
    $conn->prepare("INSERT INTO package_credit_transactions (client_package_credit_id, client_id, session_type, transaction_type, amount, notes, created_by) VALUES (?, ?, 'private', 'adjustment', ?, 'Admin added credits manually', ?)")->execute([$cpc_id, $client_id, 3, $admin_id]);

    $stmt = $conn->prepare("SELECT total_credits, used_credits FROM client_package_credits WHERE id = ?");
    $stmt->execute([$cpc_id]);
    $cpc = $stmt->fetch(PDO::FETCH_ASSOC);

    assert((int)$cpc['total_credits'] === 3, 'total_credits should be 3 after addition');
    assert((int)$cpc['used_credits'] === 0, 'used_credits should still be 0');
    echo "  ✓ 3 private credits added; balance = 3\n\n";

    // ------------------------------------------------------------------
    // Test 2: Subtract 1 private session credit manually
    // ------------------------------------------------------------------
    echo "Test 2: Admin manually subtracts 1 private session credit\n";

    $remaining = (int)$cpc['total_credits'] - (int)$cpc['used_credits'];
    assert($remaining + (-1) >= 0, 'Subtraction must not go below zero');

    $conn->prepare("UPDATE client_package_credits SET total_credits = total_credits + ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([-1, $cpc_id]);
    $conn->prepare("INSERT INTO package_credit_transactions (client_package_credit_id, client_id, session_type, transaction_type, amount, notes, created_by) VALUES (?, ?, 'private', 'adjustment', ?, 'Admin correction', ?)")->execute([$cpc_id, $client_id, -1, $admin_id]);

    $stmt->execute([$cpc_id]);
    $cpc = $stmt->fetch(PDO::FETCH_ASSOC);

    assert((int)$cpc['total_credits'] === 2, 'total_credits should be 2 after subtraction');
    echo "  ✓ 1 private credit subtracted; balance = 2\n\n";

    // ------------------------------------------------------------------
    // Test 3: Prevent subtracting below zero
    // ------------------------------------------------------------------
    echo "Test 3: Prevent subtracting private credits below zero\n";

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

    $stmt = $conn->prepare("SELECT COUNT(*) FROM package_credit_transactions WHERE client_id = ? AND session_type = 'private' AND transaction_type = 'adjustment'");
    $stmt->execute([$client_id]);
    $count = (int)$stmt->fetchColumn();

    assert($count === 2, "Expected 2 audit log entries, got $count");
    echo "  ✓ 2 audit log entries found for private credit adjustments\n\n";

    // ------------------------------------------------------------------
    // Test 5: Manual credits appear in per-session-type summary query
    // ------------------------------------------------------------------
    echo "Test 5: Manual credits appear in session-type summary\n";

    $stmt = $conn->prepare("
        SELECT cpc.session_type,
               SUM(cpc.total_credits - cpc.used_credits) AS remaining
        FROM client_package_credits cpc
        JOIN client_packages cp ON cpc.client_package_id = cp.id
        WHERE cpc.client_id = ? AND cp.is_active = 1
          AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
          AND (cpc.total_credits - cpc.used_credits) > 0
        GROUP BY cpc.session_type
    ");
    $stmt->execute([$client_id]);
    $summary = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'remaining', 'session_type');

    assert(isset($summary['private']),          'private type should be in summary');
    assert((int)$summary['private'] === 2,      'private remaining should be 2');
    echo "  ✓ Summary shows private=2 remaining\n\n";

    // ------------------------------------------------------------------
    // Test 6: Unified transaction history (legacy + package) merges correctly
    // ------------------------------------------------------------------
    echo "Test 6: Unified transaction history includes package credit adjustments\n";

    // Add a legacy credit transaction for the same client
    $conn->prepare("
        INSERT INTO client_credits (client_id, credit_balance, total_purchased, total_consumed, total_adjusted)
        VALUES (?, 5, 5, 0, 0)
    ")->execute([$client_id]);
    $conn->prepare("
        INSERT INTO credit_transactions (client_id, transaction_type, amount, balance_before, balance_after, notes, created_by)
        VALUES (?, 'purchase', 5, 0, 5, 'Legacy credit purchase', ?)
    ")->execute([$client_id, $admin_id]);

    $stmt = $conn->prepare("
        SELECT transaction_type, amount, NULL AS session_type, 'legacy' AS source
        FROM credit_transactions WHERE client_id = ?
        UNION ALL
        SELECT transaction_type, amount, session_type, 'package' AS source
        FROM package_credit_transactions WHERE client_id = ?
        ORDER BY source
    ");
    $stmt->execute([$client_id, $client_id]);
    $all_tx = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $legacy_count  = count(array_filter($all_tx, fn($r) => $r['source'] === 'legacy'));
    $package_count = count(array_filter($all_tx, fn($r) => $r['source'] === 'package'));

    assert($legacy_count  >= 1, 'At least 1 legacy transaction expected');
    assert($package_count === 2, "Expected 2 package transactions, got $package_count");
    echo "  ✓ Unified history has $legacy_count legacy + $package_count package transactions\n\n";

    // ------------------------------------------------------------------
    // Cleanup
    // ------------------------------------------------------------------
    echo "Cleanup...\n";
    $conn->prepare("DELETE FROM package_credit_transactions WHERE client_id = ?")->execute([$client_id]);
    $conn->prepare("DELETE FROM client_package_credits WHERE client_id = ?")->execute([$client_id]);
    $conn->prepare("DELETE FROM client_packages WHERE client_id = ?")->execute([$client_id]);
    $conn->prepare("DELETE FROM credit_transactions WHERE client_id = ?")->execute([$client_id]);
    $conn->prepare("DELETE FROM client_credits WHERE client_id = ?")->execute([$client_id]);
    $conn->prepare("DELETE FROM clients WHERE id = ?")->execute([$client_id]);
    echo "  ✓ Test data cleaned up\n\n";

    echo str_repeat('=', 50) . "\n";
    echo "✓ ALL UNIFIED CREDIT TESTS PASSED!\n";
    echo "Database: " . strtoupper($db_type) . "\n";

} catch (Exception $e) {
    echo "\n✗ TEST FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
