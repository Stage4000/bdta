<?php
/**
 * IMAP Email Receiver Service
 * Fetches incoming emails from IMAP server and stores them in the database
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/database.php';

class ImapEmailReceiver {
    private $db;
    private $conn;
    private $imap_connection = null;
    
    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }
    
    /**
     * Connect to IMAP server
     */
    private function connect() {
        // Check if IMAP is enabled
        if (!Settings::get('imap_enabled', false)) {
            return false;
        }
        
        // Get IMAP settings
        $host = Settings::get('imap_host');
        $port = Settings::get('imap_port', 993);
        $encryption = Settings::get('imap_encryption', 'ssl');
        $username = Settings::get('imap_username');
        $password = Settings::get('imap_password');
        $folder = Settings::get('imap_folder', 'INBOX');
        
        if (empty($host) || empty($username) || empty($password)) {
            throw new Exception('IMAP settings are incomplete');
        }
        
        // Build connection string
        $connection_string = '{' . $host . ':' . $port;
        
        if ($encryption === 'ssl') {
            $connection_string .= '/imap/ssl';
        } elseif ($encryption === 'tls') {
            $connection_string .= '/imap/tls';
        } else {
            $connection_string .= '/imap/novalidate-cert';
        }
        
        $connection_string .= '}' . $folder;
        
        // Connect to IMAP server
        $this->imap_connection = @imap_open($connection_string, $username, $password);
        
        if (!$this->imap_connection) {
            throw new Exception('Failed to connect to IMAP server: ' . imap_last_error());
        }
        
        return true;
    }
    
    /**
     * Disconnect from IMAP server
     */
    private function disconnect() {
        if ($this->imap_connection) {
            imap_close($this->imap_connection);
            $this->imap_connection = null;
        }
    }
    
    /**
     * Fetch and process new emails
     */
    public function fetchEmails() {
        try {
            // Connect to IMAP
            if (!$this->connect()) {
                return [
                    'success' => false,
                    'message' => 'IMAP is not enabled',
                    'emails_processed' => 0
                ];
            }
            
            // Get sync days setting
            $sync_days = Settings::get('imap_sync_days', 30);
            $since_date = date('d-M-Y', strtotime("-{$sync_days} days"));
            
            // Search for emails since the sync date
            $emails = imap_search($this->imap_connection, "SINCE \"{$since_date}\"");
            
            if (!$emails) {
                $this->disconnect();
                return [
                    'success' => true,
                    'message' => 'No new emails found',
                    'emails_processed' => 0
                ];
            }
            
            $processed_count = 0;
            $errors = [];
            
            // Process each email
            foreach ($emails as $email_number) {
                try {
                    $this->processEmail($email_number);
                    $processed_count++;
                } catch (Exception $e) {
                    $errors[] = "Email #{$email_number}: " . $e->getMessage();
                }
            }
            
            $this->disconnect();
            
            return [
                'success' => true,
                'message' => "Processed {$processed_count} email(s)",
                'emails_processed' => $processed_count,
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            $this->disconnect();
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'emails_processed' => 0
            ];
        }
    }
    
    /**
     * Process a single email
     */
    private function processEmail($email_number) {
        // Get email header
        $header = imap_headerinfo($this->imap_connection, $email_number);
        
        if (!$header) {
            throw new Exception('Failed to get email header');
        }
        
        // Get message ID to avoid duplicates
        $message_id = isset($header->message_id) ? $header->message_id : null;
        
        if ($message_id) {
            // Check if this email already exists
            $stmt = $this->conn->prepare("
                SELECT id FROM client_emails 
                WHERE direction = 'incoming' AND subject = ? AND created_at = ?
                LIMIT 1
            ");
            $date = isset($header->date) ? date('Y-m-d H:i:s', strtotime($header->date)) : date('Y-m-d H:i:s');
            $subject = $this->decodeHeader($header->subject ?? '(No Subject)');
            $stmt->execute([$subject, $date]);
            
            if ($stmt->fetch()) {
                // Email already processed
                return;
            }
        }
        
        // Get email body
        $structure = imap_fetchstructure($this->imap_connection, $email_number);
        $body_html = $this->getEmailBody($email_number, $structure, 'html');
        $body_text = $this->getEmailBody($email_number, $structure, 'text');
        
        // Get from address
        $from_email = '';
        if (isset($header->from) && count($header->from) > 0) {
            $from = $header->from[0];
            $from_email = isset($from->mailbox) && isset($from->host) 
                ? $from->mailbox . '@' . $from->host 
                : '';
        }
        
        // Get to address
        $to_email = Settings::get('imap_username', '');
        
        // Try to match email to a client
        $client_id = $this->findClientByEmail($from_email);
        
        if (!$client_id) {
            // No matching client found, skip this email
            return;
        }
        
        // Get received date
        $received_date = isset($header->date) ? date('Y-m-d H:i:s', strtotime($header->date)) : date('Y-m-d H:i:s');
        
        // Insert email into database
        $stmt = $this->conn->prepare("
            INSERT INTO client_emails (
                client_id, direction, status, from_email, to_email,
                subject, body_html, body_text,
                sent_at, created_at, updated_at
            ) VALUES (?, 'incoming', 'received', ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $client_id,
            $from_email,
            $to_email,
            $subject,
            $body_html,
            $body_text ?: strip_tags($body_html),
            $received_date,
            $received_date,
            date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Get email body (HTML or plain text)
     */
    private function getEmailBody($email_number, $structure, $type = 'text') {
        $body = '';
        
        if (!$structure) {
            return $body;
        }
        
        // Check if multipart
        if (isset($structure->parts) && count($structure->parts)) {
            foreach ($structure->parts as $part_num => $part) {
                $body_part = $this->getPartBody($email_number, $part, $part_num + 1, $type);
                if ($body_part) {
                    $body = $body_part;
                    break;
                }
            }
        } else {
            // Single part message
            $body = imap_body($this->imap_connection, $email_number);
            
            // Decode if needed
            if (isset($structure->encoding)) {
                $body = $this->decodeBody($body, $structure->encoding);
            }
        }
        
        return $body;
    }
    
    /**
     * Get specific part of multipart email
     */
    private function getPartBody($email_number, $part, $part_num, $type = 'text') {
        $data = '';
        
        // Check if this part matches the desired type
        $is_html = isset($part->subtype) && strtolower($part->subtype) === 'html';
        $is_text = isset($part->subtype) && strtolower($part->subtype) === 'plain';
        
        if (($type === 'html' && $is_html) || ($type === 'text' && $is_text)) {
            $data = imap_fetchbody($this->imap_connection, $email_number, $part_num);
            
            // Decode if needed
            if (isset($part->encoding)) {
                $data = $this->decodeBody($data, $part->encoding);
            }
            
            return $data;
        }
        
        // Check sub-parts
        if (isset($part->parts) && count($part->parts)) {
            foreach ($part->parts as $sub_part_num => $sub_part) {
                $sub_data = $this->getPartBody($email_number, $sub_part, $part_num . '.' . ($sub_part_num + 1), $type);
                if ($sub_data) {
                    return $sub_data;
                }
            }
        }
        
        return '';
    }
    
    /**
     * Decode email body based on encoding
     */
    private function decodeBody($body, $encoding) {
        switch ($encoding) {
            case 0: // 7bit
            case 1: // 8bit
                return $body;
            case 2: // Binary
                return $body;
            case 3: // Base64
                return base64_decode($body);
            case 4: // Quoted-printable
                return quoted_printable_decode($body);
            case 5: // Other
                return $body;
            default:
                return $body;
        }
    }
    
    /**
     * Decode email header (subject, from, etc.)
     */
    private function decodeHeader($header) {
        $decoded = imap_mime_header_decode($header);
        $result = '';
        
        foreach ($decoded as $part) {
            $result .= $part->text;
        }
        
        return $result;
    }
    
    /**
     * Find client by email address
     */
    private function findClientByEmail($email) {
        if (empty($email)) {
            return null;
        }
        
        $stmt = $this->conn->prepare("SELECT id FROM clients WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['id'] : null;
    }
}
