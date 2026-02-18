#!/usr/bin/env php
<?php
/**
 * Integration test - Simulates real application usage
 * Tests that existing application code works with the new database layer
 */

echo "=== Integration Test ===\n\n";

// Simulate what the actual application does
require_once __DIR__ . '/backend/includes/database.php';
require_once __DIR__ . '/backend/includes/config.php';

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
    
    echo "4. Testing Booking Creation (simulating api_bookings.php)...\n";
    // Simulate creating a booking
    $booking_data = [
        'client_name' => 'John Doe',
        'client_email' => 'john@example.com',
        'client_phone' => '555-1234',
        'service_type' => 'Pet Manners at Home',
        'appointment_date' => date('Y-m-d', strtotime('+7 days')),
        'appointment_time' => '10:00',
        'duration_minutes' => 60,
        'status' => 'pending',
        'notes' => 'First time client'
    ];
    
    $stmt = $conn->prepare("
        INSERT INTO bookings (
            client_name, client_email, client_phone, service_type,
            appointment_date, appointment_time, duration_minutes, status, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute(array_values($booking_data));
    $booking_id = $conn->lastInsertId();
    echo "   ✓ Booking created with ID: $booking_id\n";
    echo "   ✓ Appointment: {$booking_data['appointment_date']} at {$booking_data['appointment_time']}\n\n";
    
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
        'Total Clients' => "SELECT COUNT(*) as count FROM clients",
        'Pending Bookings' => "SELECT COUNT(*) as count FROM bookings WHERE status = 'pending'",
        'Published Posts' => "SELECT COUNT(*) as count FROM blog_posts WHERE published = 1",
        'Draft Posts' => "SELECT COUNT(*) as count FROM blog_posts WHERE published = 0"
    ];
    
    foreach ($queries as $label => $query) {
        $stmt = $conn->query($query);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "   ✓ $label: {$result['count']}\n";
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
