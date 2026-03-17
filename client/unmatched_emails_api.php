<?php
/**
 * Unmatched Emails API - Manage emails from unknown senders
 */
require_once '../backend/includes/config.php';
requireLogin();

header('Content-Type: application/json');

function sanitizeLogLine(string $message): string {
    return preg_replace('/[\r\n]+/', ' ', $message);
}

function sanitizeLogValue(mixed $value): string {
    if (is_scalar($value) || $value === null) {
        return sanitizeLogLine(scalar_string($value));
    }

    $json_value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    return sanitizeLogLine($json_value === false ? '[unloggable error detail]' : $json_value);
}

$db = new Database();
$conn = $db->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Get all unmatched emails or a specific one
    $email_id = safe_int($_GET['id'] ?? 0);
    
    if ($email_id > 0) {
        // Get specific email
        $stmt = $conn->prepare("
            SELECT 
                ue.*,
                c.name as assigned_client_name,
                au.username as assigned_by_username
            FROM unmatched_emails ue
            LEFT JOIN clients c ON ue.assigned_to_client_id = c.id
            LEFT JOIN admin_users au ON ue.assigned_by = au.id
            WHERE ue.id = ?
        ");
        $stmt->execute([$email_id]);
        $email = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($email) {
            echo json_encode(['success' => true, 'email' => $email]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Email not found']);
        }
    } else {
        // Get all unmatched emails with optional filters
        $show_archived = scalar_string($_GET['archived'] ?? '') === '1';
        $show_assigned = scalar_string($_GET['assigned'] ?? '') === '1';
        
        $where_conditions = [];
        $params = [];
        
        if ($show_assigned) {
            // Assigned tab: only assigned, non-archived emails
            $where_conditions[] = "ue.is_assigned = 1";
            $where_conditions[] = "ue.is_archived = 0";
        } elseif ($show_archived) {
            // Archived tab: only archived emails
            $where_conditions[] = "ue.is_archived = 1";
        } else {
            // Default (unassigned tab): only unassigned, non-archived emails
            $where_conditions[] = "ue.is_assigned = 0";
            $where_conditions[] = "ue.is_archived = 0";
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        
        // where_clause is assembled only from fixed SQL fragments and does not interpolate user values.
        // nosemgrep
        $stmt = $conn->prepare("
            SELECT 
                ue.id, ue.from_email, ue.from_name, ue.to_email, ue.subject,
                ue.received_at, ue.direction, ue.is_assigned, ue.assigned_to_client_id,
                ue.assigned_at, ue.assigned_by, ue.is_archived, ue.archived_at,
                ue.created_at,
                c.name as assigned_client_name
            FROM unmatched_emails ue
            LEFT JOIN clients c ON ue.assigned_to_client_id = c.id
            $where_clause
            ORDER BY ue.received_at DESC
        ");
        $stmt->execute($params);
        $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get count of unassigned emails
        $stmt = $conn->query("SELECT COUNT(*) FROM unmatched_emails WHERE is_assigned = 0 AND is_archived = 0");
        $unassigned_count = $stmt->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'emails' => $emails,
            'unassigned_count' => $unassigned_count
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    
} elseif ($method === 'POST') {
    // Assign email to a client or archive it
    $data = decode_json_assoc(file_get_contents('php://input'));
    $action = array_string_value($data, 'action', 'assign');

    // Compose action does not require an existing email ID
    if ($action === 'compose') {
        $to = trim(array_string_value($data, 'to'));
        $cc_raw = trim(array_string_value($data, 'cc'));
        $bcc_raw = trim(array_string_value($data, 'bcc'));
        $subject = trim(array_string_value($data, 'subject'));
        $body_html = trim(array_string_value($data, 'body_html'));

        if (empty($to)) {
            http_response_code(400);
            echo json_encode(['error' => 'Recipient email (To) is required']);
            exit;
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid recipient email address']);
            exit;
        }
        if (empty($subject)) {
            http_response_code(400);
            echo json_encode(['error' => 'Subject is required']);
            exit;
        }
        if (empty($body_html)) {
            http_response_code(400);
            echo json_encode(['error' => 'Message body is required']);
            exit;
        }

        // Parse and validate CC/BCC addresses
        $cc = array_values(array_filter(array_map('trim', $cc_raw !== '' ? explode(',', $cc_raw) : [])));
        $bcc = array_values(array_filter(array_map('trim', $bcc_raw !== '' ? explode(',', $bcc_raw) : [])));

        foreach ($cc as $cc_email) {
            if (!filter_var($cc_email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid CC email address: ' . $cc_email]);
                exit;
            }
        }
        foreach ($bcc as $bcc_email) {
            if (!filter_var($bcc_email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid BCC email address: ' . $bcc_email]);
                exit;
            }
        }

        require_once '../backend/includes/email_service.php';
        $emailService = new EmailService();
        $result = $emailService->sendComposeEmail($to, $cc, $bcc, $subject, $body_html);

        if ($result['success']) {
            // Check if recipient is not an existing client; if so, record in unmatched_emails
            $stmt = $conn->prepare("SELECT id FROM clients WHERE email = ?");
            $stmt->execute([$to]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $from_email_addr = Settings::get('email_from_address', 'bookings@brooksdogtrainingacademy.com');
                $from_name_val = Settings::get('email_from_name', "Brook's Dog Training Academy");
                $now = currentUtcDateTime();
                $stmt = $conn->prepare("
                    INSERT INTO unmatched_emails
                        (from_email, from_name, to_email, subject, body_html, body_text, received_at, direction, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'outgoing', ?)
                ");
                $stmt->execute([
                    $from_email_addr,
                    $from_name_val,
                    $to,
                    $subject,
                    $body_html,
                    strip_tags($body_html),
                    $now,
                    $now
                ]);
            }
            echo json_encode(['success' => true, 'message' => 'Email sent successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to send email: ' . $result['message']]);
        }
        exit;
    }

    if ($action === 'cleanup_missing_timestamps') {
        $csrf_token = array_string_value($data, 'csrf_token');
        $session_csrf_token = scalar_string($_SESSION['csrf_token'] ?? '');

        if ($csrf_token === '' || $session_csrf_token === '') {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid request'
            ]);
            exit;
        }

        if (!hash_equals($session_csrf_token, $csrf_token)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid request'
            ]);
            exit;
        }

        require_once __DIR__ . '/../backend/cron/tasks/unmatched_email_cleaner.php';

        $task = new UnmatchedEmailCleanerTask();
        $result = $task->execute();

        if (!$result['success']) {
            $logged_errors = [];
            $raw_errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];

            foreach ($raw_errors as $error) {
                $sanitized_error = sanitizeLogValue($error);
                if ($sanitized_error !== '') {
                    $logged_errors[] = $sanitized_error;
                }
            }

            $log_details = implode('; ', $logged_errors);

            if ($log_details === '') {
                if ($raw_errors !== []) {
                    $log_details = 'Cleanup task returned error entries without loggable details';
                } else {
                    $log_details = sanitizeLogLine(array_string_value($result, 'message', 'Unknown cleanup failure'));
                }
            }

            error_log('unmatched_emails_api cleanup_missing_timestamps failed: ' . $log_details);
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to clean unmatched emails. Please try again later.'
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => array_string_value($result, 'message'),
            'items_processed' => array_int_value($result, 'items_processed')
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
     
    $email_id = array_int_value($data, 'id');
    if ($email_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Email ID is required']);
        exit;
    }

    try {
        if ($action === 'assign' && array_int_value($data, 'client_id') > 0) {
            // Assign to client and optionally create client_email record
            $client_id = array_int_value($data, 'client_id');
            $create_client_email = !empty($data['create_client_email']);
            
            // Verify client exists
            $stmt = $conn->prepare("SELECT id, email FROM clients WHERE id = ?");
            $stmt->execute([$client_id]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$client) {
                http_response_code(404);
                echo json_encode(['error' => 'Client not found']);
                exit;
            }
            
            // Get the unmatched email
            $stmt = $conn->prepare("SELECT * FROM unmatched_emails WHERE id = ?");
            $stmt->execute([$email_id]);
            $unmatched_email = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$unmatched_email) {
                http_response_code(404);
                echo json_encode(['error' => 'Email not found']);
                exit;
            }
            
            // Begin transaction
            $conn->beginTransaction();
            
            // Mark as assigned
            $stmt = $conn->prepare("
                UPDATE unmatched_emails
                SET is_assigned = 1,
                    assigned_to_client_id = ?,
                    assigned_at = ?,
                    assigned_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$client_id, currentUtcDateTime(), $_SESSION['user_id'], $email_id]);
            
            // Optionally create client_email record
            if ($create_client_email) {
                $email_direction = array_string_value($unmatched_email, 'direction', 'incoming');
                $email_status = ($email_direction === 'outgoing') ? 'sent' : 'received';
                $stmt = $conn->prepare("
                    INSERT INTO client_emails (
                        client_id, direction, status, message_id, from_email, to_email,
                        subject, body_html, body_text, sent_at, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $client_id,
                    $email_direction,
                    $email_status,
                    array_string_value($unmatched_email, 'message_id') ?: null,
                    array_string_value($unmatched_email, 'from_email'),
                    array_string_value($unmatched_email, 'to_email'),
                    array_string_value($unmatched_email, 'subject'),
                    array_string_value($unmatched_email, 'body_html'),
                    array_string_value($unmatched_email, 'body_text'),
                    array_string_value($unmatched_email, 'received_at'),
                    array_string_value($unmatched_email, 'received_at'),
                    currentUtcDateTime()
                ]);
            }
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'Email assigned to client successfully'
            ]);
            
        } elseif ($action === 'archive') {
            // Archive the email
            $stmt = $conn->prepare("
                UPDATE unmatched_emails
                SET is_archived = 1,
                    archived_at = ?
                WHERE id = ?
            ");
            $stmt->execute([currentUtcDateTime(), $email_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Email archived successfully'
            ]);
            
        } elseif ($action === 'unarchive') {
            // Unarchive the email
            $stmt = $conn->prepare("
                UPDATE unmatched_emails
                SET is_archived = 0,
                    archived_at = NULL
                WHERE id = ?
            ");
            $stmt->execute([$email_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Email unarchived successfully'
            ]);
            
        } elseif ($action === 'reply') {
            // Reply to the sender of an unmatched email
            $subject = trim(array_string_value($data, 'subject'));
            $body_html = trim(array_string_value($data, 'body_html'));
            $body_text = trim(array_string_value($data, 'body_text'));

            if (empty($subject)) {
                http_response_code(400);
                echo json_encode(['error' => 'Subject is required']);
                exit;
            }
            if (empty($body_html)) {
                http_response_code(400);
                echo json_encode(['error' => 'Message body is required']);
                exit;
            }

            // Get the unmatched email to find the sender address
            $stmt = $conn->prepare("SELECT from_email FROM unmatched_emails WHERE id = ?");
            $stmt->execute([$email_id]);
            $unmatched_email = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$unmatched_email) {
                http_response_code(404);
                echo json_encode(['error' => 'Email not found']);
                exit;
            }

            $to_email = array_string_value($unmatched_email, 'from_email');
            if (empty($to_email)) {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot reply: sender address is missing']);
                exit;
            }

            require_once '../backend/includes/email_service.php';
            $emailService = new EmailService();
            $result = $emailService->sendGenericEmail(
                $to_email,
                $subject,
                $body_html,
                $body_text ?: strip_tags($body_html)
            );

            if ($result['success']) {
                // Record the sent reply in unmatched_emails so it appears in the list
                $from_email_addr = Settings::get('email_from_address', 'bookings@brooksdogtrainingacademy.com');
                $from_name_val = Settings::get('email_from_name', "Brook's Dog Training Academy");
                $now = currentUtcDateTime();
                $stmt = $conn->prepare("
                    INSERT INTO unmatched_emails
                        (from_email, from_name, to_email, subject, body_html, body_text, received_at, direction, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'outgoing', ?)
                ");
                $stmt->execute([
                    $from_email_addr,
                    $from_name_val,
                    $to_email,
                    $subject,
                    $body_html,
                    $body_text ?: strip_tags($body_html),
                    $now,
                    $now
                ]);
                echo json_encode(['success' => true, 'message' => 'Reply sent successfully']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to send reply: ' . $result['message']]);
            }
            
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action or missing client_id']);
        }
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    
} elseif ($method === 'DELETE') {
    // Delete an unmatched email
    $email_id = safe_int($_GET['id'] ?? 0);
    
    if ($email_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Email ID is required']);
        exit;
    }
    
    try {
        $stmt = $conn->prepare("DELETE FROM unmatched_emails WHERE id = ?");
        $stmt->execute([$email_id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Email deleted successfully'
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
