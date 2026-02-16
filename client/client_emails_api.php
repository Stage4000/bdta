<?php
/**
 * Client Emails API - CRUD operations for client email correspondence
 */
require_once '../backend/includes/config.php';
requireLogin();

header('Content-Type: application/json');

$db = new Database();
$conn = $db->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

// Verify client exists
if ($client_id > 0) {
    $stmt = $conn->prepare("SELECT id FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Client not found']);
        exit;
    }
}

if ($method === 'GET') {
    // Get emails for a client
    if ($client_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'client_id parameter required']);
        exit;
    }
    
    $stmt = $conn->prepare("
        SELECT 
            ce.*,
            et.name as template_name,
            au.username as created_by_username
        FROM client_emails ce
        LEFT JOIN email_templates et ON ce.template_id = et.id
        LEFT JOIN admin_users au ON ce.created_by = au.id
        WHERE ce.client_id = ?
        ORDER BY 
            CASE 
                WHEN ce.status = 'scheduled' THEN ce.scheduled_at
                WHEN ce.status = 'sent' THEN ce.sent_at
                ELSE ce.created_at
            END DESC
    ");
    $stmt->execute([$client_id]);
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'emails' => $emails]);
    
} elseif ($method === 'POST') {
    // Create/send new email
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['client_id']) || $data['client_id'] <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'client_id is required']);
        exit;
    }
    
    if (!isset($data['subject']) || empty($data['subject'])) {
        http_response_code(400);
        echo json_encode(['error' => 'subject is required']);
        exit;
    }
    
    if (!isset($data['body_html']) || empty($data['body_html'])) {
        http_response_code(400);
        echo json_encode(['error' => 'body_html is required']);
        exit;
    }
    
    try {
        // Get client email
        $stmt = $conn->prepare("SELECT email, name FROM clients WHERE id = ?");
        $stmt->execute([$data['client_id']]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$client) {
            http_response_code(404);
            echo json_encode(['error' => 'Client not found']);
            exit;
        }
        
        // Get from email from settings
        require_once '../backend/includes/settings.php';
        $from_email = Settings::get('email_from_address', 'bookings@brooksdogtraining.com');
        
        // Determine send mode: immediate or scheduled
        $send_immediately = !isset($data['scheduled_at']) || empty($data['scheduled_at']);
        
        // Insert email record
        $stmt = $conn->prepare("
            INSERT INTO client_emails (
                client_id, direction, status, from_email, to_email, 
                subject, body_html, body_text, template_id, 
                scheduled_at, created_by, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
        ");
        
        $status = $send_immediately ? 'pending' : 'scheduled';
        $scheduled_at = $send_immediately ? null : $data['scheduled_at'];
        $template_id = isset($data['template_id']) && $data['template_id'] > 0 ? $data['template_id'] : null;
        
        $stmt->execute([
            $data['client_id'],
            'outgoing',
            $status,
            $from_email,
            $client['email'],
            $data['subject'],
            $data['body_html'],
            $data['body_text'] ?? strip_tags($data['body_html']),
            $template_id,
            $scheduled_at,
            $_SESSION['user_id'] ?? null
        ]);
        
        $email_id = $conn->lastInsertId();
        
        // If sending immediately, send the email now
        if ($send_immediately) {
            require_once '../backend/includes/email_service.php';
            $emailService = new EmailService();
            
            $result = $emailService->sendGenericEmail(
                $client['email'],
                $data['subject'],
                $data['body_html'],
                $data['body_text'] ?? ''
            );
            
            if ($result['success']) {
                // Update email status to sent
                $stmt = $conn->prepare("
                    UPDATE client_emails 
                    SET status = 'sent', sent_at = datetime('now'), updated_at = datetime('now')
                    WHERE id = ?
                ");
                $stmt->execute([$email_id]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Email sent successfully',
                    'email_id' => $email_id
                ]);
            } else {
                // Update email status to failed
                $stmt = $conn->prepare("
                    UPDATE client_emails 
                    SET status = 'failed', failed_at = datetime('now'), 
                        error_message = ?, updated_at = datetime('now')
                    WHERE id = ?
                ");
                $stmt->execute([$result['message'], $email_id]);
                
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to send email: ' . $result['message'],
                    'email_id' => $email_id
                ]);
            }
        } else {
            // Email scheduled for later
            echo json_encode([
                'success' => true,
                'message' => 'Email scheduled successfully',
                'email_id' => $email_id
            ]);
        }
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    
} elseif ($method === 'DELETE') {
    // Delete email (only if not sent)
    $email_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($email_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'id parameter required']);
        exit;
    }
    
    try {
        // Check if email can be deleted (only drafts and scheduled)
        $stmt = $conn->prepare("SELECT status FROM client_emails WHERE id = ?");
        $stmt->execute([$email_id]);
        $email = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$email) {
            http_response_code(404);
            echo json_encode(['error' => 'Email not found']);
            exit;
        }
        
        if (!in_array($email['status'], ['draft', 'scheduled', 'failed'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot delete sent emails']);
            exit;
        }
        
        $stmt = $conn->prepare("DELETE FROM client_emails WHERE id = ?");
        $stmt->execute([$email_id]);
        
        echo json_encode(['success' => true, 'message' => 'Email deleted successfully']);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
