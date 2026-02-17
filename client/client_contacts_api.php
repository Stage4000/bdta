<?php
/**
 * Client Contacts API - Manage multiple contacts per client
 * Handles CRUD operations for client contacts
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';

requireLogin();

header('Content-Type: application/json');

$db = new Database();
$conn = $db->getConnection();

$action = $_GET['action'] ?? '';
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$contact_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    switch ($action) {
        case 'list':
            // Get all contacts for a client
            if (!$client_id) {
                throw new Exception('Client ID is required');
            }
            
            $stmt = $conn->prepare("
                SELECT id, name, email, phone, is_primary, created_at, updated_at
                FROM client_contacts
                WHERE client_id = ?
                ORDER BY is_primary DESC, name ASC
            ");
            $stmt->execute([$client_id]);
            $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'contacts' => $contacts]);
            break;
            
        case 'add':
            // Add a new contact
            if (!$client_id) {
                throw new Exception('Client ID is required');
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $name = trim($data['name'] ?? '');
            $email = trim($data['email'] ?? '');
            $phone = trim($data['phone'] ?? '');
            $is_primary = isset($data['is_primary']) ? (int)$data['is_primary'] : 0;
            
            // Validate required fields
            if (empty($name)) {
                throw new Exception('Contact name is required');
            }
            if (empty($email)) {
                throw new Exception('Email address is required');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email address format');
            }
            if (empty($phone)) {
                throw new Exception('Phone number is required');
            }
            
            // If setting as primary, unset other primary contacts
            if ($is_primary) {
                $stmt = $conn->prepare("UPDATE client_contacts SET is_primary = 0 WHERE client_id = ?");
                $stmt->execute([$client_id]);
            }
            
            $stmt = $conn->prepare("
                INSERT INTO client_contacts (client_id, name, email, phone, is_primary, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([$client_id, $name, $email, $phone, $is_primary]);
            
            $new_id = $conn->lastInsertId();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Contact added successfully',
                'id' => $new_id
            ]);
            break;
            
        case 'update':
            // Update an existing contact
            if (!$contact_id) {
                throw new Exception('Contact ID is required');
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $name = trim($data['name'] ?? '');
            $email = trim($data['email'] ?? '');
            $phone = trim($data['phone'] ?? '');
            $is_primary = isset($data['is_primary']) ? (int)$data['is_primary'] : 0;
            
            // Validate required fields
            if (empty($name)) {
                throw new Exception('Contact name is required');
            }
            if (empty($email)) {
                throw new Exception('Email address is required');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email address format');
            }
            if (empty($phone)) {
                throw new Exception('Phone number is required');
            }
            
            // Get client_id for this contact
            $stmt = $conn->prepare("SELECT client_id FROM client_contacts WHERE id = ?");
            $stmt->execute([$contact_id]);
            $contact = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$contact) {
                throw new Exception('Contact not found');
            }
            
            // If setting as primary, unset other primary contacts
            if ($is_primary) {
                $stmt = $conn->prepare("UPDATE client_contacts SET is_primary = 0 WHERE client_id = ? AND id != ?");
                $stmt->execute([$contact['client_id'], $contact_id]);
            }
            
            $stmt = $conn->prepare("
                UPDATE client_contacts 
                SET name = ?, email = ?, phone = ?, is_primary = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$name, $email, $phone, $is_primary, $contact_id]);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Contact updated successfully'
            ]);
            break;
            
        case 'delete':
            // Delete a contact
            if (!$contact_id) {
                throw new Exception('Contact ID is required');
            }
            
            $stmt = $conn->prepare("DELETE FROM client_contacts WHERE id = ?");
            $stmt->execute([$contact_id]);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Contact deleted successfully'
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}
