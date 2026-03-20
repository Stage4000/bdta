<?php
require_once '../backend/includes/config.php';

header('Content-Type: application/json');

if (!isPortalLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$client_id = portalClientId();
$db = new Database();
$conn = $db->getConnection();

$action = scalar_string($_GET['action'] ?? '');
$contact_id = safe_int($_GET['id'] ?? 0);

try {
    switch ($action) {
        case 'list':
            $stmt = $conn->prepare("
                SELECT id, name, email, phone, is_primary
                FROM client_contacts
                WHERE client_id = ?
                ORDER BY is_primary DESC, name ASC
            ");
            $stmt->execute([$client_id]);
            $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'contacts' => $contacts]);
            break;

        case 'add':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }

            $data = decode_json_assoc(file_get_contents('php://input'));
            $name = trim(array_string_value($data, 'name'));
            $email = trim(array_string_value($data, 'email'));
            $phone = trim(array_string_value($data, 'phone'));
            $is_primary = array_key_exists('is_primary', $data) ? array_int_value($data, 'is_primary') : 0;

            if ($name === '') {
                throw new Exception('Contact name is required');
            }
            if ($email === '') {
                throw new Exception('Email address is required');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email address format');
            }
            if ($phone === '') {
                throw new Exception('Phone number is required');
            }

            $conn->beginTransaction();
            try {
                if ($is_primary) {
                    $stmt = $conn->prepare("UPDATE client_contacts SET is_primary = 0 WHERE client_id = ?");
                    $stmt->execute([$client_id]);
                }

                $stmt = $conn->prepare("
                    INSERT INTO client_contacts (client_id, name, email, phone, is_primary, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([$client_id, $name, $email, $phone, $is_primary ? 1 : 0]);
                $new_id = (int)$conn->lastInsertId();

                $conn->commit();
                logClientActivity($client_id, 'contact_add', 'Added additional contact: ' . $name, $conn);

                echo json_encode(['success' => true, 'id' => $new_id, 'message' => 'Contact added successfully']);
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;

        case 'update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }
            if ($contact_id <= 0) {
                throw new Exception('Contact ID is required');
            }

            $stmt = $conn->prepare("SELECT id FROM client_contacts WHERE id = ? AND client_id = ?");
            $stmt->execute([$contact_id, $client_id]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception('Contact not found');
            }

            $data = decode_json_assoc(file_get_contents('php://input'));
            $name = trim(array_string_value($data, 'name'));
            $email = trim(array_string_value($data, 'email'));
            $phone = trim(array_string_value($data, 'phone'));
            $is_primary = array_key_exists('is_primary', $data) ? array_int_value($data, 'is_primary') : 0;

            if ($name === '') {
                throw new Exception('Contact name is required');
            }
            if ($email === '') {
                throw new Exception('Email address is required');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email address format');
            }
            if ($phone === '') {
                throw new Exception('Phone number is required');
            }

            $conn->beginTransaction();
            try {
                if ($is_primary) {
                    $stmt = $conn->prepare("UPDATE client_contacts SET is_primary = 0 WHERE client_id = ? AND id != ?");
                    $stmt->execute([$client_id, $contact_id]);
                }

                $stmt = $conn->prepare("
                    UPDATE client_contacts
                    SET name = ?, email = ?, phone = ?, is_primary = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND client_id = ?
                ");
                $stmt->execute([$name, $email, $phone, $is_primary ? 1 : 0, $contact_id, $client_id]);

                $conn->commit();
                logClientActivity($client_id, 'contact_update', 'Updated additional contact: ' . $name, $conn);

                echo json_encode(['success' => true, 'message' => 'Contact updated successfully']);
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;

        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST required');
            }
            if ($contact_id <= 0) {
                throw new Exception('Contact ID is required');
            }

            $stmt = $conn->prepare("SELECT name FROM client_contacts WHERE id = ? AND client_id = ?");
            $stmt->execute([$contact_id, $client_id]);
            $contact = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$contact) {
                throw new Exception('Contact not found');
            }

            $stmt = $conn->prepare("DELETE FROM client_contacts WHERE id = ? AND client_id = ?");
            $stmt->execute([$contact_id, $client_id]);

            logClientActivity($client_id, 'contact_delete', 'Deleted additional contact: ' . array_string_value($contact, 'name'), $conn);
            echo json_encode(['success' => true, 'message' => 'Contact deleted successfully']);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
