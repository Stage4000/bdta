#!/usr/bin/env php
<?php
/**
 * Comprehensive database CRUD test
 * Tests Create, Read, Update, Delete operations on both MySQL and SQLite
 */

require_once dirname(__DIR__) . '/backend/includes/database.php';

echo "=== Database CRUD Test ===\n\n";

try {
    $db = new Database();
    $conn = $db->getConnection();
    $db_type = $db->getDatabaseType();
    
    echo "Testing with: " . strtoupper($db_type) . "\n";
    echo str_repeat('-', 50) . "\n\n";
    
    // Test 1: CREATE - Insert a test blog post
    echo "Test 1: CREATE (Insert)\n";
    $stmt = $conn->prepare("
        INSERT INTO blog_posts (title, slug, content, excerpt, author, published)
        VALUES (:title, :slug, :content, :excerpt, :author, :published)
    ");
    
    $test_data = [
        'title' => 'Test Blog Post - ' . date('Y-m-d H:i:s'),
        'slug' => 'test-post-' . time(),
        'content' => 'This is a test blog post content.',
        'excerpt' => 'Test excerpt',
        'author' => 'Test Author',
        'published' => 1
    ];
    
    $stmt->execute($test_data);
    $inserted_id = $conn->lastInsertId();
    echo "  ✓ Blog post created with ID: $inserted_id\n\n";
    
    // Test 2: READ - Fetch the created post
    echo "Test 2: READ (Select)\n";
    $stmt = $conn->prepare("SELECT * FROM blog_posts WHERE id = :id");
    $stmt->execute(['id' => $inserted_id]);
    $post = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($post && $post['title'] === $test_data['title']) {
        echo "  ✓ Blog post retrieved successfully\n";
        echo "    Title: {$post['title']}\n";
        echo "    Author: {$post['author']}\n\n";
    } else {
        throw new Exception("Failed to retrieve blog post");
    }
    
    // Test 3: UPDATE - Modify the post
    echo "Test 3: UPDATE\n";
    $new_title = 'Updated Test Post - ' . date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE blog_posts SET title = :title WHERE id = :id");
    $stmt->execute(['title' => $new_title, 'id' => $inserted_id]);
    
    // Verify update
    $stmt = $conn->prepare("SELECT title FROM blog_posts WHERE id = :id");
    $stmt->execute(['id' => $inserted_id]);
    $updated_post = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($updated_post)) {
        throw new Exception("Failed to load updated blog post");
    }
    
    if ($updated_post['title'] === $new_title) {
        echo "  ✓ Blog post updated successfully\n";
        echo "    New title: {$updated_post['title']}\n\n";
    } else {
        throw new Exception("Failed to update blog post");
    }
    
    // Test 4: DELETE - Remove the test post
    echo "Test 4: DELETE\n";
    $stmt = $conn->prepare("DELETE FROM blog_posts WHERE id = :id");
    $stmt->execute(['id' => $inserted_id]);
    
    // Verify deletion
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM blog_posts WHERE id = :id");
    $stmt->execute(['id' => $inserted_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($result)) {
        throw new Exception("Failed to verify blog post deletion");
    }
    
    if ($result['count'] == 0) {
        echo "  ✓ Blog post deleted successfully\n\n";
    } else {
        throw new Exception("Failed to delete blog post");
    }
    
    // Test 5: Complex JOIN - Test relationships
    echo "Test 5: COMPLEX QUERY (Joins)\n";
    
    // Insert a test client
    $stmt = $conn->prepare("
        INSERT INTO clients (name, email, phone, dog_name, dog_breed)
        VALUES (:name, :email, :phone, :dog_name, :dog_breed)
    ");
    $stmt->execute([
        'name' => 'Test Client',
        'email' => 'test@example.com',
        'phone' => '555-1234',
        'dog_name' => 'Buddy',
        'dog_breed' => 'Golden Retriever'
    ]);
    $client_id = $conn->lastInsertId();
    
    // Insert a test booking
    $stmt = $conn->prepare("
        INSERT INTO bookings (client_name, client_email, service_type, appointment_date, appointment_time, client_id)
        VALUES (:client_name, :client_email, :service_type, :appointment_date, :appointment_time, :client_id)
    ");
    $stmt->execute([
        'client_name' => 'Test Client',
        'client_email' => 'test@example.com',
        'service_type' => 'Training',
        'appointment_date' => date('Y-m-d'),
        'appointment_time' => '10:00',
        'client_id' => $client_id
    ]);
    $booking_id = $conn->lastInsertId();
    
    // Join query
    $stmt = $conn->prepare("
        SELECT b.*, c.dog_name, c.dog_breed
        FROM bookings b
        LEFT JOIN clients c ON b.client_id = c.id
        WHERE b.id = :id
    ");
    $stmt->execute(['id' => $booking_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($booking && $booking['dog_name'] === 'Buddy') {
        echo "  ✓ JOIN query successful\n";
        echo "    Client: {$booking['client_name']}\n";
        echo "    Dog: {$booking['dog_name']} ({$booking['dog_breed']})\n\n";
    } else {
        throw new Exception("JOIN query failed");
    }
    
    // Cleanup - use prepared statements
    $stmt = $conn->prepare("DELETE FROM bookings WHERE id = ?");
    $stmt->execute([$booking_id]);
    
    $stmt = $conn->prepare("DELETE FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    
    // Test 6: Transaction Support
    echo "Test 6: TRANSACTIONS\n";
    
    try {
        $conn->beginTransaction();
        
        // Insert client
        $stmt = $conn->prepare("INSERT INTO clients (name, email) VALUES (:name, :email)");
        $stmt->execute(['name' => 'Transaction Test', 'email' => 'trans@test.com']);
        $trans_client_id = $conn->lastInsertId();
        
        // Intentionally rollback
        $conn->rollBack();
        
        // Verify rollback worked
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM clients WHERE id = :id");
        $stmt->execute(['id' => $trans_client_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($result)) {
            throw new Exception("Failed to verify transaction rollback");
        }
        
        if ($result['count'] == 0) {
            echo "  ✓ Transaction rollback successful\n\n";
        } else {
            throw new Exception("Transaction rollback failed");
        }
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
    
    // Summary
    echo str_repeat('=', 50) . "\n";
    echo "✓ ALL TESTS PASSED!\n";
    echo "Database type: " . strtoupper($db_type) . "\n";
    echo "All CRUD operations working correctly.\n";
    
} catch (Exception $e) {
    echo "\n✗ TEST FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    exit(1);
}
