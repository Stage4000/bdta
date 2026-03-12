<?php
/**
 * Email Templates API - Get templates for composing emails
 */
require_once '../backend/includes/config.php';
require_once '../backend/includes/email_service.php';
requireLogin();

header('Content-Type: application/json');

$db = new Database();
$conn = $db->getConnection();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    
    if ($action === 'list') {
        // Get all active templates
        $stmt = $conn->prepare("
            SELECT id, name, template_type, subject, variables
            FROM email_templates
            WHERE is_active = 1
            ORDER BY name
        ");
        $stmt->execute();
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'templates' => $templates]);
        
    } elseif ($action === 'get') {
        // Get a specific template
        $template_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if ($template_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'id parameter required']);
            exit;
        }
        
        $stmt = $conn->prepare("
            SELECT *
            FROM email_templates
            WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$template_id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$template) {
            http_response_code(404);
            echo json_encode(['error' => 'Template not found']);
            exit;
        }
        
        echo json_encode(['success' => true, 'template' => $template]);
        
    } elseif ($action === 'preview') {
        // Preview a template with client data
        $template_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
        
        if ($template_id <= 0 || $client_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'id and client_id parameters required']);
            exit;
        }
        
        // Get template
        $stmt = $conn->prepare("SELECT * FROM email_templates WHERE id = ?");
        $stmt->execute([$template_id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$template) {
            http_response_code(404);
            echo json_encode(['error' => 'Template not found']);
            exit;
        }
        
        // Get client data
        $stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$client) {
            http_response_code(404);
            echo json_encode(['error' => 'Client not found']);
            exit;
        }
        
        // Replace variables in template
        $subject = $template['subject'];
        $body_html = $template['body_html'];
        $body_text = $template['body_text'];
        
        // Common replacements
        $replacements = [
            '{{client_name}}' => $client['name'],
            '{{client_email}}' => $client['email'],
            '{{client_phone}}' => $client['phone'] ?? '',
            '{{client_address}}' => $client['address'] ?? '',
            '{{dog_name}}' => $client['dog_name'] ?? '',
            '{{dog_breed}}' => $client['dog_breed'] ?? '',
            '{{today_date}}' => date('F j, Y'),
        ];
        
        foreach ($replacements as $var => $value) {
            $subject = str_replace($var, $value, $subject);
            $body_html = str_replace($var, $value, $body_html);
            if ($body_text) {
                $body_text = str_replace($var, $value, $body_text);
            }
        }

        // Apply the standard styled wrapper so the preview matches what
        // recipients actually see when the email is sent.
        $body_html = EmailService::wrapEmailHtml($body_html);
        
        echo json_encode([
            'success' => true,
            'preview' => [
                'subject' => $subject,
                'body_html' => $body_html,
                'body_text' => $body_text
            ]
        ]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
    
} elseif ($method === 'POST') {
    $action = $_GET['action'] ?? '';

    if ($action === 'preview_styled') {
        // Return the supplied HTML fragment wrapped in the standard email
        // container — used by the template editor for live preview.
        $input     = json_decode(scalar_string(file_get_contents('php://input')), true) ?? [];
        $body_html = $input['body_html'] ?? '';

        $wrapped = EmailService::wrapEmailHtml($body_html);

        echo json_encode(['success' => true, 'html' => $wrapped]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
