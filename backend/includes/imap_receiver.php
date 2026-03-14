<?php
/**
 * IMAP Email Receiver Service
 * Fetches incoming emails from IMAP server and stores them in the database
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/database.php';

class ImapEmailReceiver {
    private Database $db;
    private SafePDO $conn;
    private ?\IMAP\Connection $imap_connection = null;
    
    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getConnection();
    }
    
    /**
     * Connect to IMAP server
     */
    private function connect(): bool {
        // Check if IMAP is enabled
        if (!Settings::get('imap_enabled', false)) {
            return false;
        }
        
        // Get IMAP settings
        $host = scalar_string(Settings::get('imap_host', ''));
        $port = safe_int(Settings::get('imap_port', 993));
        $encryption = scalar_string(Settings::get('imap_encryption', 'ssl'));
        $username = scalar_string(Settings::get('imap_username', ''));
        $password = scalar_string(Settings::get('imap_password', ''));
        $folder = scalar_string(Settings::get('imap_folder', 'INBOX'));
        
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
        $imap_connection = @imap_open($connection_string, $username, $password);

        if ($imap_connection === false) {
            throw new Exception('Failed to connect to IMAP server: ' . imap_last_error());
        }

        $this->imap_connection = $imap_connection;
        
        return true;
    }
    
    /**
     * Disconnect from IMAP server
     */
    private function disconnect(): void {
        if ($this->imap_connection) {
            imap_close($this->imap_connection);
            $this->imap_connection = null;
        }
    }

    private function getImapConnection(): \IMAP\Connection {
        if (!$this->imap_connection instanceof \IMAP\Connection) {
            throw new RuntimeException('IMAP connection is not established or invalid');
        }

        return $this->imap_connection;
    }
    
    /**
     * Fetch and process new emails
     *
     * @return array{success: bool, message: string, emails_processed: int, items_processed: int, errors: list<string>}
     */
    public function fetchEmails(): array {
        try {
            // Connect to IMAP
            if (!$this->connect()) {
                return [
                    'success' => false,
                    'message' => 'IMAP is not enabled',
                    'emails_processed' => 0,
                    'items_processed' => 0,
                    'errors' => []
                ];
            }
            
            // Get sync days setting
            $sync_days = safe_int(Settings::get('imap_sync_days', 30));
            $since_date = date('d-M-Y', safe_timestamp(strtotime("-{$sync_days} days")));
            
            $errors = [];
            
            // Search for unread emails since the sync date
            // Note: imap_search returns false on *errors* as well as "no results".
            // We disambiguate by checking the IMAP error stack immediately after the call.
            // Clear any prior IMAP errors before searching; errors from the search itself are handled below
            imap_errors();
            $emails = imap_search($this->getImapConnection(), "UNSEEN SINCE \"{$since_date}\"");
            $search_errors = array_values(array_map('scalar_string', imap_errors() ?: []));
            
            // If there are no unseen results and no errors, fall back to searching all mail since the sync window.
            // This covers cases where another client has already marked the messages as seen before the cron task runs.
            if (($emails === false || empty($emails)) && empty($search_errors)) {
                imap_errors();
                $emails = imap_search($this->getImapConnection(), "SINCE \"{$since_date}\"");
                $fallback_errors = array_values(array_map('scalar_string', imap_errors() ?: []));
                if ($emails === false && !empty($fallback_errors)) {
                    $this->disconnect();
                    return [
                        'success' => false,
                        'message' => 'IMAP search failed (fallback): ' . implode('; ', $fallback_errors),
                        'emails_processed' => 0,
                        'items_processed' => 0,
                        'errors' => $fallback_errors
                    ];
                }
            }
            
            if ($emails === false) {
                $this->disconnect();
                if (!empty($search_errors)) {
                    return [
                        'success' => false,
                        'message' => 'IMAP search failed: ' . implode('; ', $search_errors),
                        'emails_processed' => 0,
                        'items_processed' => 0,
                        'errors' => $search_errors
                    ];
                }
                
                // No results and no errors -> treat as no new mail
                return [
                    'success' => true,
                    'message' => 'No new emails found',
                    'emails_processed' => 0,
                    'items_processed' => 0,
                    'errors' => []
                ];
            }
            
            if (empty($emails)) {
                $this->disconnect();
                return [
                    'success' => true,
                    'message' => 'No new emails found',
                    'emails_processed' => 0,
                    'items_processed' => 0,
                    'errors' => []
                ];
            }
            
            $processed_count = 0;
            $flag_queue = [];
            
            // Process each email
            foreach ($emails as $email_number_raw) {
                $email_number = 0;
                try {
                    $email_number = safe_int($email_number_raw);
                    if ($email_number <= 0) {
                        continue;
                    }
                    $this->processEmail($email_number);
                    // Queue message to be marked as seen after processing loop
                    $flag_queue[] = (string) $email_number;
                    $processed_count++;
                } catch (Exception $e) {
                    $errors[] = "Email #{$email_number}: " . $e->getMessage();
                }
            }
            
            // Mark all processed messages as seen in batches to avoid oversized IMAP commands
            if (!empty($flag_queue)) {
                $flag_errors = [];
                foreach (array_chunk($flag_queue, 100) as $flag_batch) {
                    // Clear the IMAP error buffer so any issues setting flags are captured below
                    imap_errors();
                    $set_success = imap_setflag_full($this->getImapConnection(), implode(',', $flag_batch), "\\Seen");
                    $batch_errors = array_map('scalar_string', imap_errors() ?: []);
                    // @phpstan-ignore-next-line imap_setflag_full returns true in stubs, but may return false at runtime
                    if (!$set_success) {
                        $last_error = imap_last_error();
                        if ($last_error !== false) {
                            $batch_errors[] = scalar_string($last_error);
                        }
                        if (empty($batch_errors)) {
                            $batch_errors[] = 'Failed to mark messages as seen for batch: ' . implode(',', $flag_batch);
                        }
                    }
                    if (!empty($batch_errors)) {
                        $flag_errors[] = implode('; ', $batch_errors);
                    }
                }
                if (!empty($flag_errors)) {
                    $errors[] = 'Failed to mark one or more emails as seen: ' . implode(' | ', $flag_errors);
                }
            }
            
            $this->disconnect();
            
            return [
                'success' => true,
                'message' => "Processed {$processed_count} email(s)",
                'emails_processed' => $processed_count,
                'items_processed' => $processed_count,
                'errors' => $errors
            ];
            
        } catch (Exception $e) {
            $this->disconnect();
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'emails_processed' => 0,
                'items_processed' => 0,
                'errors' => [$e->getMessage()]
            ];
        }
    }
    
    /**
     * Process a single email
     */
    private function processEmail(int $email_number): void {
        // Get email header
        $header = imap_headerinfo($this->getImapConnection(), $email_number);
        
        if (!$header) {
            throw new Exception('Failed to get email header');
        }
        
        // Decode subject and received date (needed for both duplicate check and storage)
        $subject = $this->decodeHeader(scalar_string($header->subject ?? '(No Subject)'));
        $header_date = $header->date ?? null;
        $parsed_date = is_string($header_date) ? strtotime($header_date) : false;
        $received_date = $parsed_date !== false ? date('Y-m-d H:i:s', $parsed_date) : date('Y-m-d H:i:s');

        // Get from address (needed for duplicate check and storage)
        $from_email = '';
        if (isset($header->from) && is_array($header->from) && count($header->from) > 0) {
            $from = $header->from[0];
            if (is_object($from)) {
                $mailbox = scalar_string($from->mailbox ?? '');
                $host = scalar_string($from->host ?? '');
                $from_email = $mailbox !== '' && $host !== ''
                    ? $mailbox . '@' . $host
                    : '';
            }
        }

        // Get message ID to avoid duplicates
        $message_id = isset($header->message_id) ? $header->message_id : null;
        
        if ($message_id) {
            // Check if this email already exists in client_emails
            $stmt = $this->conn->prepare("
                SELECT id FROM client_emails 
                WHERE direction = 'incoming' AND subject = ? AND created_at = ?
                LIMIT 1
            ");
            $stmt->execute([$subject, $received_date]);
            
            if ($stmt->fetch()) {
                // Email already processed
                return;
            }

            // Check if this email already exists in unmatched_emails
            $stmt = $this->conn->prepare("
                SELECT id FROM unmatched_emails 
                WHERE from_email = ? AND subject = ? AND received_at = ?
                LIMIT 1
            ");
            $stmt->execute([$from_email, $subject, $received_date]);

            if ($stmt->fetch()) {
                // Already stored as unmatched
                return;
            }
        }
        
        // Get email body
        $structure = imap_fetchstructure($this->getImapConnection(), $email_number);
        if ($structure === false) {
            throw new Exception('Failed to get email structure');
        }
        $body_html = $this->getEmailBody($email_number, $structure, 'html');
        $body_text = $this->getEmailBody($email_number, $structure, 'text');
        
        // Get to address
        $to_email = scalar_string(Settings::get('imap_username', ''));
        
        // Try to match email to a client
        $client_id = $this->findClientByEmail($from_email);
        
        if (!$client_id) {
            // No matching client found, store as unmatched email
            $this->storeUnmatchedEmail($header, $from_email, $to_email, $subject, $body_html, $body_text, $received_date);
            return;
        }
        
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
    private function getEmailBody(int $email_number, ?object $structure, string $type = 'text'): string {
        $body = '';
        
        if (!$structure) {
            return $body;
        }
        
        // Check if multipart
        if (isset($structure->parts) && is_array($structure->parts) && count($structure->parts) > 0) {
            foreach ($structure->parts as $part_num => $part) {
                if (!is_object($part)) {
                    continue;
                }
                $body_part = $this->getPartBody($email_number, $part, $part_num + 1, $type);
                if ($body_part) {
                    $body = $body_part;
                    break;
                }
            }
        } else {
            // Single part message
            $body = scalar_string(imap_body($this->getImapConnection(), $email_number));
            
            // Decode if needed
            $body = $this->decodeBody($body, safe_int($structure->encoding ?? 0));
        }
        
        return $body;
    }
    
    /**
     * Get specific part of multipart email
     */
    private function getPartBody(int $email_number, object $part, int|string $part_num, string $type = 'text'): string {
        $data = '';
        
        // Check if this part matches the desired type
        $subtype = strtolower(scalar_string($part->subtype ?? ''));
        $is_html = $subtype === 'html';
        $is_text = $subtype === 'plain';
        
        if (($type === 'html' && $is_html) || ($type === 'text' && $is_text)) {
            $data = scalar_string(imap_fetchbody($this->getImapConnection(), $email_number, (string) $part_num));
            
            // Decode if needed
            $data = $this->decodeBody($data, safe_int($part->encoding ?? 0));
            
            return $data;
        }
        
        // Check sub-parts
        if (isset($part->parts) && is_array($part->parts) && count($part->parts) > 0) {
            foreach ($part->parts as $sub_part_num => $sub_part) {
                if (!is_object($sub_part)) {
                    continue;
                }
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
    private function decodeBody(string $body, int $encoding): string {
        switch ($encoding) {
            case 0: // 7bit
            case 1: // 8bit
                return $body;
            case 2: // Binary
                return $body;
            case 3: // Base64
                return base64_decode($body) ?: '';
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
    private function decodeHeader(string $header): string {
        $decoded = imap_mime_header_decode($header) ?: [];
        $result = '';
        
        foreach ($decoded as $part) {
            if (is_object($part)) {
                $result .= scalar_string($part->text ?? '');
            }
        }
        
        return $result;
    }
    
    /**
     * Find client by email address
     */
    private function findClientByEmail(string $email): ?string {
        if (empty($email)) {
            return null;
        }
        
        $stmt = $this->conn->prepare("SELECT id FROM clients WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!is_array($result)) {
            return null;
        }
        $client_id = $result['id'] ?? null;
        return is_string($client_id) && $client_id !== '' ? $client_id : null;
    }
    
    /**
     * Store unmatched email (from unknown sender)
     */
    private function storeUnmatchedEmail(object $header, string $from_email, string $to_email, string $subject, string $body_html, string $body_text, string $received_date): void {
        // Get from name
        $from_name = '';
        if (isset($header->from) && is_array($header->from) && count($header->from) > 0) {
            $from = $header->from[0];
            if (is_object($from)) {
                $from_name = isset($from->personal) ? $this->decodeHeader(scalar_string($from->personal)) : '';
            }
        }
        
        // Check if this unmatched email already exists
        $stmt = $this->conn->prepare("
            SELECT id FROM unmatched_emails 
            WHERE from_email = ? AND subject = ? AND received_at = ?
            LIMIT 1
        ");
        $stmt->execute([$from_email, $subject, $received_date]);
        
        if ($stmt->fetch()) {
            // Already exists, skip
            return;
        }
        
        // Insert unmatched email
        $stmt = $this->conn->prepare("
            INSERT INTO unmatched_emails (
                from_email, from_name, to_email, subject, 
                body_html, body_text, received_at, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $from_email,
            $from_name,
            $to_email,
            $subject,
            $body_html,
            $body_text,
            $received_date,
            date('Y-m-d H:i:s')
        ]);
    }
}
