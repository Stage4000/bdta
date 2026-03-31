#!/usr/bin/env php
<?php
/**
 * Integration test - Simulates real application usage
 * Tests that existing application code works with the new database layer
 */

echo "=== Integration Test ===\n\n";

// Simulate what the actual application does
require_once dirname(__DIR__) . '/backend/includes/database.php';
require_once dirname(__DIR__) . '/backend/includes/config.php';

try {
    echo "1. Testing Database Connection...\n";
    $db = new Database();
    $conn = $db->getConnection();
    $db_type = $db->getDatabaseType();
    echo "   ✓ Connected to: " . strtoupper($db_type) . "\n\n";
    
    echo "2. Testing Admin User Login Scenario...\n";
    // Check if default admin exists (created during init)
    $stmt = $conn->prepare("SELECT * FROM admin_users WHERE username = ?");
    $stmt->execute(['admin']);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($admin) {
        echo "   ✓ Admin user found: {$admin['username']}\n";
        echo "   ✓ Email: {$admin['email']}\n\n";
    } else {
        throw new Exception("Default admin user not found");
    }
    
    echo "3. Testing Blog Post Creation (simulating blog_edit.php)...\n";
    // Simulate creating a blog post like the admin would
    $title = "Integration Test Post";
    $slug = "integration-test-" . time();
    $content = "This is a test post created during integration testing.";
    
    $stmt = $conn->prepare("
        INSERT INTO blog_posts (title, slug, content, excerpt, author, published)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $title,
        $slug,
        $content,
        substr($content, 0, 100),
        'admin',
        0  // draft
    ]);
    $post_id = $conn->lastInsertId();
    echo "   ✓ Blog post created with ID: $post_id\n\n";
    
    echo "4. Testing Booking Creation with location (simulating api_bookings.php)...\n";
    // Simulate creating a booking with location_type
    $booking_data = [
        'client_name' => 'John Doe',
        'client_email' => 'john@example.com',
        'client_phone' => '555-1234',
        'service_type' => 'Pet Manners at Home',
        'appointment_date' => date('Y-m-d', strtotime('+7 days')),
        'appointment_time' => '10:00',
        'duration_minutes' => 60,
        'status' => 'pending',
        'notes' => 'First time client',
        'location_type' => 'client_address',
        'location' => null
    ];
    
    $stmt = $conn->prepare("
        INSERT INTO bookings (
            client_name, client_email, client_phone, service_type,
            appointment_date, appointment_time, duration_minutes, status, notes,
            location_type, location
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute(array_values($booking_data));
    $booking_id = $conn->lastInsertId();
    echo "   ✓ Booking created with ID: $booking_id\n";
    echo "   ✓ Appointment: {$booking_data['appointment_date']} at {$booking_data['appointment_time']}\n";
    
    // Verify location_type was saved
    $stmt = $conn->prepare("SELECT location_type FROM bookings WHERE id = ?");
    $stmt->execute([$booking_id]);
    $saved_booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($saved_booking && $saved_booking['location_type'] === 'client_address') {
        echo "   ✓ location_type saved correctly: {$saved_booking['location_type']}\n\n";
    } else {
        throw new Exception("location_type not saved correctly");
    }

    // Test webcall location type
    echo "4b. Testing Booking with webcall location...\n";
    $stmt = $conn->prepare("
        INSERT INTO bookings (
            client_name, client_email, service_type,
            appointment_date, appointment_time, status,
            location_type, location
        ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)
    ");
    $stmt->execute([
        'Jane Zoom', 'janezoom@example.com', 'Virtual Session',
        date('Y-m-d', strtotime('+8 days')), '14:00',
        'webcall', 'https://zoom.us/j/123456'
    ]);
    $webcall_booking_id = $conn->lastInsertId();
    $stmt = $conn->prepare("SELECT location_type, location FROM bookings WHERE id = ?");
    $stmt->execute([$webcall_booking_id]);
    $wb = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($wb && $wb['location_type'] === 'webcall' && $wb['location'] === 'https://zoom.us/j/123456') {
        echo "   ✓ Webcall booking created with correct location_type and URL\n\n";
    } else {
        throw new Exception("Webcall booking location not saved correctly");
    }
    // Cleanup webcall booking
    $stmt = $conn->prepare("DELETE FROM bookings WHERE id = ?");
    $stmt->execute([$webcall_booking_id]);
    
    echo "5. Testing Client Management (simulating clients_edit.php)...\n";
    $stmt = $conn->prepare("
        INSERT INTO clients (name, email, phone, dog_name, dog_breed, notes)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        'Jane Smith',
        'jane@example.com',
        '555-5678',
        'Max',
        'Labrador',
        'Very friendly dog'
    ]);
    $client_id = $conn->lastInsertId();
    echo "   ✓ Client created with ID: $client_id\n";
    echo "   ✓ Dog: Max (Labrador)\n\n";
    
    echo "6. Testing Time Entry (simulating time_tracker.php)...\n";
    $stmt = $conn->prepare("
        INSERT INTO time_entries (
            client_id, date, start_time, end_time, duration_minutes,
            service_type, hourly_rate, total_amount, billable
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $client_id,
        date('Y-m-d'),
        '09:00',
        '10:00',
        60,
        'Training Session',
        75.00,
        75.00,
        1
    ]);
    $time_entry_id = $conn->lastInsertId();
    echo "   ✓ Time entry created with ID: $time_entry_id\n";
    echo "   ✓ Billable: 1 hour @ $75/hr\n\n";
    
    echo "7. Testing List Operations (simulating admin dashboard)...\n";
    // Get counts like the dashboard does
    $queries = [
        ['label' => 'Total Clients',      'sql' => "SELECT COUNT(*) as count FROM clients", 'params' => []],
        ['label' => 'Pending Bookings',   'sql' => "SELECT COUNT(*) as count FROM bookings WHERE status = 'pending'", 'params' => []],
        ['label' => 'Published Posts',    'sql' => "SELECT COUNT(*) as count FROM blog_posts WHERE published = 1 AND publish_date <= ?", 'params' => [date('Y-m-d H:i:s')]],
        ['label' => 'Draft Posts',        'sql' => "SELECT COUNT(*) as count FROM blog_posts WHERE published = 0", 'params' => []],
    ];
    
    foreach ($queries as $q) {
        if (!empty($q['params'])) {
            $stmt = $conn->prepare($q['sql']);
            $stmt->execute($q['params']);
        } else {
            $stmt = $conn->query($q['sql']);
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($result)) {
            throw new Exception("Failed to fetch query result for {$q['label']}");
        }
        echo "   ✓ {$q['label']}: {$result['count']}\n";
    }
    echo "\n";
    
    echo "8. Testing Search/Filter (simulating client search)...\n";
    $stmt = $conn->prepare("
        SELECT * FROM clients 
        WHERE name LIKE ? OR email LIKE ? 
        ORDER BY name
    ");
    $search_term = '%jane%';
    $stmt->execute([$search_term, $search_term]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "   ✓ Found {$results[0]['name']} matching search\n\n";
    
    echo "9. Cleanup Test Data...\n";
    $cleanup_queries = [
        "DELETE FROM time_entries WHERE id = ?",
        "DELETE FROM clients WHERE id = ?",
        "DELETE FROM bookings WHERE id = ?",
        "DELETE FROM blog_posts WHERE id = ?"
    ];
    
    $cleanup_ids = [$time_entry_id, $client_id, $booking_id, $post_id];
    
    foreach ($cleanup_queries as $index => $query) {
        $stmt = $conn->prepare($query);
        $stmt->execute([$cleanup_ids[$index]]);
    }
    echo "   ✓ Test data cleaned up\n\n";
    
    echo str_repeat('=', 50) . "\n";
    echo "✓ ALL INTEGRATION TESTS PASSED!\n";
    echo "Database: " . strtoupper($db_type) . "\n";
    echo "The application is working correctly with the new database layer.\n";
    
} catch (Exception $e) {
    echo "\n✗ INTEGRATION TEST FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
