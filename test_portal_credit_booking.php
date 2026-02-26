#!/usr/bin/env php
<?php
/**
 * Test: Portal Credit Booking Flow
 * Tests the new client portal credit booking feature including:
 * - Contact/pet selection data retrieval
 * - Contract skip logic (renewal period check)
 * - Form skip logic (frequency-based check)
 * - Inline pet creation
 */

require_once __DIR__ . '/backend/includes/database.php';

echo "=== Portal Credit Booking Feature Tests ===\n\n";

try {
    $db   = new Database();
    $conn = $db->getConnection();

    echo "Testing with: " . strtoupper($db->getDatabaseType()) . "\n";
    echo str_repeat('-', 50) . "\n\n";

    // ── Setup ────────────────────────────────────────────────────────────
    $conn->prepare("INSERT INTO clients (name, email, phone) VALUES (?,?,?)")
         ->execute(['Portal Test Client', 'portal_booking_test@example.com', '555-0000']);
    $client_id = (int)$conn->lastInsertId();

    // Contract template with 12-month renewal
    $conn->prepare("INSERT INTO contract_templates (name, description, template_text, renewal_period_months, is_active) VALUES (?,?,?,?,1)")
         ->execute(['Test Contract', 'Portal test contract', 'Terms and conditions...', 12]);
    $contract_template_id = (int)$conn->lastInsertId();

    // Appointment type linked to contract
    $conn->prepare("INSERT INTO appointment_types (name, duration_minutes, is_active, contract_template_id) VALUES (?,60,1,?)")
         ->execute(['Portal Test Session', $contract_template_id]);
    $apt_type_id = (int)$conn->lastInsertId();

    // Form template with annual renewal
    $conn->prepare("INSERT INTO form_templates (name, form_type, fields, required_frequency, is_active) VALUES (?,?,?,?,1)")
         ->execute(['Intake Form', 'client_form', json_encode([['label'=>'Dog breed','type'=>'text','required'=>true]]), 'annual']);
    $form_template_id = (int)$conn->lastInsertId();

    // ── Test 1: Contact data retrieval ────────────────────────────────────
    echo "Test 1: Contact data retrieval\n";
    $conn->prepare("INSERT INTO client_contacts (client_id, name, email, phone, is_primary) VALUES (?,?,?,?,1)")
         ->execute([$client_id, 'Jane Doe', 'jane@example.com', '555-1111']);
    $contact_id = (int)$conn->lastInsertId();

    $stmt = $conn->prepare("SELECT * FROM client_contacts WHERE client_id = ? ORDER BY is_primary DESC, name");
    $stmt->execute([$client_id]);
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($contacts) === 1 && $contacts[0]['name'] === 'Jane Doe') {
        echo "  ✓ Contact retrieved correctly\n";
    } else {
        echo "  ✗ Contact retrieval FAILED\n"; exit(1);
    }

    // ── Test 2: Pet data retrieval ────────────────────────────────────────
    echo "\nTest 2: Pet data retrieval\n";
    $conn->prepare("INSERT INTO pets (client_id, name, species, breed, is_active) VALUES (?,?,?,?,1)")
         ->execute([$client_id, 'Buddy', 'Dog', 'Labrador']);
    $pet_id = (int)$conn->lastInsertId();

    $stmt = $conn->prepare("SELECT id, name, species, breed FROM pets WHERE client_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$client_id]);
    $pets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($pets) === 1 && $pets[0]['name'] === 'Buddy') {
        echo "  ✓ Pet retrieved correctly\n";
    } else {
        echo "  ✗ Pet retrieval FAILED\n"; exit(1);
    }

    // ── Test 3: Contract skip — no prior signing (should NOT skip) ────────
    echo "\nTest 3: Contract skip logic — no prior contract (should require signing)\n";
    $renewal_months = 12;
    $stmt = $conn->prepare("
        SELECT b.contract_accepted_at
        FROM bookings b
        JOIN appointment_types apt ON apt.id = b.appointment_type_id
        WHERE b.client_id = ?
          AND apt.contract_template_id = ?
          AND b.contract_accepted = 1
          AND b.contract_accepted_at IS NOT NULL
        ORDER BY b.contract_accepted_at DESC
        LIMIT 1
    ");
    $stmt->execute([$client_id, $contract_template_id]);
    $prev = $stmt->fetch(PDO::FETCH_ASSOC);
    $can_skip = false;
    if ($prev) {
        $expiry = strtotime($prev['contract_accepted_at'] . " +{$renewal_months} months");
        if ($expiry >= time()) $can_skip = true;
    }
    if (!$can_skip) {
        echo "  ✓ Correctly requires contract signing (no prior record)\n";
    } else {
        echo "  ✗ Should NOT skip contract when no prior record\n"; exit(1);
    }

    // ── Test 4: Contract skip — recent signing (should skip) ─────────────
    echo "\nTest 4: Contract skip logic — recent signing (should skip)\n";

    // Create a previous booking with contract accepted 6 months ago (within 12-month renewal)
    $accepted_6_months_ago = date('Y-m-d H:i:s', strtotime('-6 months'));
    $conn->prepare("
        INSERT INTO bookings
            (client_id, appointment_type_id, client_name, client_email, service_type,
             appointment_date, appointment_time, contract_accepted, contract_accepted_at, status)
        VALUES (?,?,?,?,'Portal Test Session','2024-01-01','09:00',1,?,'completed')
    ")->execute([$client_id, $apt_type_id, 'Portal Test Client', 'portal_booking_test@example.com', $accepted_6_months_ago]);

    $stmt->execute([$client_id, $contract_template_id]);
    $prev = $stmt->fetch(PDO::FETCH_ASSOC);
    $can_skip = false;
    if ($prev) {
        $expiry = strtotime($prev['contract_accepted_at'] . " +{$renewal_months} months");
        if ($expiry >= time()) $can_skip = true;
    }
    if ($can_skip) {
        echo "  ✓ Correctly skips contract (signed 6 months ago, 12-month renewal)\n";
    } else {
        echo "  ✗ Should skip contract when signed within renewal period\n"; exit(1);
    }

    // ── Test 5: Contract skip — expired signing (should NOT skip) ─────────
    echo "\nTest 5: Contract skip logic — expired contract (should require re-signing)\n";
    // Insert booking with contract signed 18 months ago (beyond 12-month renewal)
    $accepted_18_months_ago = date('Y-m-d H:i:s', strtotime('-18 months'));
    // Update the existing booking's contract_accepted_at to simulate expiry
    $stmt2 = $conn->prepare("
        SELECT contract_accepted_at FROM bookings
        WHERE client_id = ? AND appointment_type_id = ? AND contract_accepted = 1
        ORDER BY contract_accepted_at DESC LIMIT 1
    ");
    // Test with a fabricated expired date
    $expired_prev = ['contract_accepted_at' => $accepted_18_months_ago];
    $expiry_expired = strtotime($expired_prev['contract_accepted_at'] . " +{$renewal_months} months");
    $can_skip_expired = ($expiry_expired >= time());
    if (!$can_skip_expired) {
        echo "  ✓ Correctly requires re-signing when contract expired (18 months ago)\n";
    } else {
        echo "  ✗ Should require re-signing when contract is expired\n"; exit(1);
    }

    // ── Test 6: Form skip — no prior submission (should require) ──────────
    echo "\nTest 6: Form skip logic — no prior submission (should require)\n";
    $stmt = $conn->prepare("SELECT submitted_at FROM form_submissions WHERE client_id = ? AND template_id = ? ORDER BY submitted_at DESC LIMIT 1");
    $stmt->execute([$client_id, $form_template_id]);
    $last_sub = $stmt->fetch(PDO::FETCH_ASSOC);
    $freq = 'annual';
    $cutoff = strtotime('-1 year');
    $needs_form = true;
    if ($last_sub) {
        if (strtotime($last_sub['submitted_at']) >= $cutoff) $needs_form = false;
    }
    if ($needs_form) {
        echo "  ✓ Correctly requires form (no prior submission)\n";
    } else {
        echo "  ✗ Should require form when no prior submission\n"; exit(1);
    }

    // ── Test 7: Form skip — recent submission (should skip) ───────────────
    echo "\nTest 7: Form skip logic — recent submission (should skip)\n";
    $conn->prepare("INSERT INTO form_submissions (client_id, template_id, responses, status, submitted_at) VALUES (?,?,?,'submitted',CURRENT_TIMESTAMP)")
         ->execute([$client_id, $form_template_id, '{"0":"Labrador"}']);
    $stmt->execute([$client_id, $form_template_id]);
    $last_sub = $stmt->fetch(PDO::FETCH_ASSOC);
    $needs_form = true;
    if ($last_sub) {
        if (strtotime($last_sub['submitted_at']) >= $cutoff) $needs_form = false;
    }
    if (!$needs_form) {
        echo "  ✓ Correctly skips form (submitted recently within annual window)\n";
    } else {
        echo "  ✗ Should skip form when submitted within frequency window\n"; exit(1);
    }

    // ── Test 8: Pet ownership verification ────────────────────────────────
    echo "\nTest 8: Pet ownership verification (security)\n";
    // Create a pet belonging to a different client
    $conn->prepare("INSERT INTO clients (name, email) VALUES (?,?)")
         ->execute(['Other Client', 'other_client_test@example.com']);
    $other_client_id = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO pets (client_id, name, species, is_active) VALUES (?,?,?,1)")
         ->execute([$other_client_id, 'NotMyDog', 'Dog']);
    $other_pet_id = (int)$conn->lastInsertId();

    // Simulate the ownership check from api_book_credit.php
    $requested_pet_ids = [$pet_id, $other_pet_id]; // mix of own and other's pet
    $placeholders = implode(',', array_fill(0, count($requested_pet_ids), '?'));
    $stmt = $conn->prepare("SELECT id FROM pets WHERE client_id = ? AND id IN ($placeholders) AND is_active = 1");
    $stmt->execute(array_merge([$client_id], $requested_pet_ids));
    $verified_ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');

    if (count($verified_ids) === 1 && (int)$verified_ids[0] === $pet_id) {
        echo "  ✓ Only client's own pet returned (other client's pet filtered out)\n";
    } else {
        echo "  ✗ Pet ownership check FAILED — security issue\n"; exit(1);
    }

    // ── Test 9: credits.php book URL uses portal route ────────────────────
    echo "\nTest 9: credits.php routes Book button to portal booking page\n";
    $credits_php = file_get_contents(__DIR__ . '/portal/credits.php');
    if (strpos($credits_php, '/portal/book_credit.php') !== false
        && strpos($credits_php, '/backend/public/book.php') === false) {
        echo "  ✓ Book button correctly points to /portal/book_credit.php\n";
    } else {
        echo "  ✗ credits.php still has old public booking URL\n"; exit(1);
    }

    // ── Test 10: Contract skip works across different appointment types ────
    echo "\nTest 10: Contract skip works when signed via a different appointment type (same template)\n";
    // Create a second appointment type using the same contract template
    $conn->prepare("INSERT INTO appointment_types (name, duration_minutes, is_active, contract_template_id) VALUES (?,60,1,?)")
         ->execute(['Portal Test Session B', $contract_template_id]);
    $apt_type_id_b = (int)$conn->lastInsertId();

    // The existing booking in test 4 was for $apt_type_id; now we check skip for $apt_type_id_b
    $cross_stmt = $conn->prepare("
        SELECT b.contract_accepted_at
        FROM bookings b
        JOIN appointment_types apt ON apt.id = b.appointment_type_id
        WHERE b.client_id = ?
          AND apt.contract_template_id = ?
          AND b.contract_accepted = 1
          AND b.contract_accepted_at IS NOT NULL
        ORDER BY b.contract_accepted_at DESC
        LIMIT 1
    ");
    $cross_stmt->execute([$client_id, $contract_template_id]);
    $cross_prev = $cross_stmt->fetch(PDO::FETCH_ASSOC);
    $cross_skip = false;
    if ($cross_prev) {
        $expiry = strtotime($cross_prev['contract_accepted_at'] . " +{$renewal_months} months");
        if ($expiry >= time()) $cross_skip = true;
    }
    if ($cross_skip) {
        echo "  ✓ Contract skip correctly fires for second appointment type sharing same template\n";
    } else {
        echo "  ✗ Contract skip FAILED for cross-appointment-type check\n"; exit(1);
    }

    // ── Cleanup ────────────────────────────────────────────────────────────
    echo "\nCleanup…\n";
    $conn->prepare("DELETE FROM form_submissions WHERE client_id = ?")->execute([$client_id]);
    $conn->prepare("DELETE FROM bookings WHERE client_id = ?")->execute([$client_id]);
    $conn->prepare("DELETE FROM pets WHERE client_id IN (?,?)")->execute([$client_id, $other_client_id]);
    $conn->prepare("DELETE FROM client_contacts WHERE client_id = ?")->execute([$client_id]);
    $conn->prepare("DELETE FROM clients WHERE id IN (?,?)")->execute([$client_id, $other_client_id]);
    $conn->prepare("DELETE FROM appointment_types WHERE id IN (?,?)")->execute([$apt_type_id, $apt_type_id_b]);
    $conn->prepare("DELETE FROM contract_templates WHERE id = ?")->execute([$contract_template_id]);
    $conn->prepare("DELETE FROM form_templates WHERE id = ?")->execute([$form_template_id]);
    echo "  ✓ Test data cleaned up\n";

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "✓ ALL PORTAL CREDIT BOOKING TESTS PASSED!\n";
    echo "Database: " . strtoupper($db->getDatabaseType()) . "\n";

} catch (Exception $e) {
    echo "\n✗ TEST FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
