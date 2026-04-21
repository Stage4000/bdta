<?php
/**
 * Email Signature Helper
 * Handles HTML sanitization and custom field replacement for email signatures
 */

class EmailSignatureHelper {
    private const SIGNATURE_PLACEHOLDER_PATTERN = '/\{\{signature(?::(\d+))?\}\}/';
    private const DEFAULT_SIGNATURE_CACHE_KEY = '__default__';
    
    /**
     * Sanitize HTML content to prevent XSS attacks
     * @param string $html Raw HTML content
     * @return string Sanitized HTML
     */
    public static function sanitizeHTML(string $html): string {
        // List of allowed HTML tags for email signatures
        $allowed_tags = [
            'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'a', 'img', 'span', 'div',
            'table', 'tbody', 'tr', 'td', 'th', 'thead', 'tfoot',
            'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'hr'
        ];
        
        // List of allowed attributes
        $allowed_attrs = [
            'href', 'src', 'alt', 'title', 'width', 'height', 'style', 
            'class', 'align', 'border', 'cellpadding', 'cellspacing',
            'target', 'rel'
        ];
        
        // Use DOMDocument to parse and sanitize HTML
        $dom = new DOMDocument();
        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);
        
        // Load HTML with UTF-8 encoding
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        // Remove disallowed elements and attributes
        self::cleanNode($dom->documentElement, $allowed_tags, $allowed_attrs);
        
        // Get sanitized HTML
        $sanitized = scalar_string($dom->saveHTML());
        
        // Remove the XML encoding declaration that was added
        $sanitized = str_replace('<?xml encoding="UTF-8">', '', $sanitized);
        
        return trim($sanitized);
    }
    
    /**
     * Recursively clean DOM nodes
     */
    /**
     * @param DOMElement|null $node
     * @param list<string> $allowed_tags
     * @param list<string> $allowed_attrs
     */
    private static function cleanNode(?DOMElement $node, array $allowed_tags, array $allowed_attrs): void {
        if (!$node) return;
        
        // Process child nodes first (bottom-up approach)
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        
        foreach ($children as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                // Check if tag is allowed
                if (!in_array(strtolower($child->nodeName), $allowed_tags)) {
                    // Remove disallowed tag but keep its content
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                
                // Clean attributes
                if ($child->hasAttributes()) {
                    $attrs_to_remove = [];
                    foreach ($child->attributes as $attr) {
                        if (!in_array(strtolower($attr->name), $allowed_attrs)) {
                            $attrs_to_remove[] = $attr->name;
                        } else {
                            // Sanitize specific attributes
                            if (strtolower($attr->name) === 'href' || strtolower($attr->name) === 'src') {
                                // Only allow http, https, and mailto URLs
                                $value = $attr->value;
                                if (!preg_match('/^(https?:\/\/|mailto:)/i', $value)) {
                                    $attrs_to_remove[] = $attr->name;
                                }
                            }
                            
                            // For style attribute, remove potentially dangerous CSS
                            if (strtolower($attr->name) === 'style') {
                                $style = $attr->value;
                                // Remove javascript: and expression()
                                $style = preg_replace('/javascript:/i', '', $style) ?? $style;
                                $style = preg_replace('/expression\s*\(/i', '', $style) ?? $style;
                                $child->setAttribute('style', $style);
                            }
                        }
                    }
                    
                    foreach ($attrs_to_remove as $attr_name) {
                        $child->removeAttribute($attr_name);
                    }
                }
                
                // Add rel="noopener noreferrer" to external links for security
                if (strtolower($child->nodeName) === 'a' && $child->hasAttribute('href')) {
                    if ($child->hasAttribute('target') && $child->getAttribute('target') === '_blank') {
                        $existing_rel = $child->hasAttribute('rel') ? $child->getAttribute('rel') : '';
                        $rel_values = array_filter(explode(' ', $existing_rel));
                        if (!in_array('noopener', $rel_values)) {
                            $rel_values[] = 'noopener';
                        }
                        if (!in_array('noreferrer', $rel_values)) {
                            $rel_values[] = 'noreferrer';
                        }
                        $child->setAttribute('rel', implode(' ', $rel_values));
                    }
                }
                
                // Recursively clean child nodes
                self::cleanNode($child, $allowed_tags, $allowed_attrs);
            }
        }
    }
    
    /**
     * Replace custom fields in signature with actual values
     * @param string $html Signature HTML with placeholders
     * @param array $data Custom field data
     * @return string HTML with replaced values
     */
    /**
     * @param array<string, string> $data
     */
    public static function replaceCustomFields(string $html, array $data = []): string {
        // Default values from settings if not provided
        require_once __DIR__ . '/settings.php';
        
        $defaults = [
            'name' => scalar_string(Settings::get('email_from_name', "Brook's Dog Training Academy")),
            'email' => scalar_string(Settings::get('business_email', 'bookings@brooksdogtrainingacademy.com')),
            'phone' => scalar_string(Settings::get('business_phone', '(555) 123-4567')),
            'business_name' => scalar_string(Settings::get('site_name', "Brook's Dog Training Academy")),
            'business_address' => scalar_string(Settings::get('business_address', 'Sebring, Florida'))
        ];
        
        // Merge provided data with defaults
        $data = array_merge($defaults, $data);
        
        // Replace custom field placeholders
        $replacements = [
            '{{name}}' => htmlspecialchars($data['name']),
            '{{email}}' => htmlspecialchars($data['email']),
            '{{phone}}' => htmlspecialchars($data['phone']),
            '{{business_name}}' => htmlspecialchars($data['business_name']),
            '{{business_address}}' => nl2br(htmlspecialchars($data['business_address']))
        ];
        
        foreach ($replacements as $placeholder => $value) {
            $html = str_replace($placeholder, $value, $html);
        }
        
        return $html;
    }
    
    /**
     * Get signature by ID
     * @param int $id Signature ID
     * @return array|null Signature data or null if not found
     */
    /**
     * @return array<string, mixed>|null
     */
    public static function getSignature(int $id): ?array {
        require_once __DIR__ . '/database.php';
        
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT * FROM email_signature_templates WHERE id = ? AND is_active = 1");
        $stmt->execute([$id]);
        $signature = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($signature) ? $signature : null;
    }
    
    /**
     * Get default signature
     * @return array|null Default signature data or null if none set
     */
    /**
     * @return array<string, mixed>|null
     */
    public static function getDefaultSignature(): ?array {
        require_once __DIR__ . '/database.php';
        
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->query("SELECT * FROM email_signature_templates WHERE is_default = 1 AND is_active = 1 LIMIT 1");
        $signature = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($signature) ? $signature : null;
    }
    
    /**
     * Get rendered signature HTML with custom fields replaced
     * @param int|null $signature_id Signature ID (null = use default)
     * @param array $custom_data Custom field data
     * @return string|null Rendered signature HTML or null if no signature
     */
    /**
     * @param array<string, string> $custom_data
     */
    public static function render(?int $signature_id = null, array $custom_data = []): ?string {
        // Get signature
        if ($signature_id) {
            $signature = self::getSignature($signature_id);
        } else {
            $signature = self::getDefaultSignature();
        }
        
        if (!$signature) {
            return null;
        }
        
        // Replace custom fields and return
        return self::replaceCustomFields(scalar_string($signature['html_content'] ?? ''), $custom_data);
    }

    /**
     * @param array<string, string> $custom_data
     */
    public static function renderPlainText(?int $signature_id = null, array $custom_data = []): ?string {
        $signature_html = self::render($signature_id, $custom_data);

        if ($signature_html === null) {
            return null;
        }

        return self::convertHtmlToPlainText($signature_html);
    }

    /**
     * Convert rendered signature HTML into plain text for text email bodies.
     *
     * @param string $html Rendered signature HTML fragment
     * @return string Plain-text signature content
     */
    public static function htmlToPlainText(string $html): string {
        return self::convertHtmlToPlainText($html);
    }
    
    /**
     * Export signature as HTML file
     * @param int $signature_id Signature ID
     * @return string|null HTML content suitable for email client import, or null if the signature does not exist
     */
    public static function exportAsHTML(int $signature_id): ?string {
        $signature = self::getSignature($signature_id);
        
        if (!$signature) {
            return null;
        }
        
        // Create a complete HTML document for email client compatibility
        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title></title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 0; }
        .signature { max-width: 600px; }
    </style>
</head>
<body>
    <div class="signature">
        
    </div>
</body>
</html>
HTML;
        
        // Safely insert the signature name and content
        $safe_name = htmlspecialchars(scalar_string($signature['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $html = str_replace('<title></title>', '<title>' . $safe_name . '</title>', $html);
        // Content is already sanitized when saved via EmailSignatureHelper::sanitizeHTML()
        $html = str_replace('<div class="signature">', '<div class="signature">' . "\n        " . scalar_string($signature['html_content'] ?? ''), $html);
        
        return $html;
    }
    
    /**
     * Replace {{signature}} and {{signature:id}} placeholders in email template
     * @param string $email_content Email template content with signature placeholders
     * @param int|null $signature_id Default signature ID (null = use system default)
     * @param array $custom_data Custom field data for signature
     * @return string Email content with signature(s) replaced
     */
    /**
     * @param array<string, string> $custom_data
     */
    public static function replaceSignaturePlaceholder(string $email_content, ?int $signature_id = null, array $custom_data = []): string {
        // Find all signature placeholders
        preg_match_all(self::SIGNATURE_PLACEHOLDER_PATTERN, $email_content, $matches, PREG_SET_ORDER);
        
        if (empty($matches)) {
            return $email_content;
        }

        /** @var array<string, string> $rendered_signatures */
        $rendered_signatures = [];
        
        // Replace each placeholder
        foreach ($matches as $match) {
            $full_placeholder = $match[0]; // e.g., {{signature}} or {{signature:5}}
            $specified_id = isset($match[1]) ? (int)$match[1] : null;
            
            // Determine which signature to use
            $sig_id = $specified_id ?? $signature_id; // Use specified ID, or fallback to parameter
            $cache_key = self::cacheKeyForSignatureId($sig_id);
            
            if (!array_key_exists($cache_key, $rendered_signatures)) {
                $rendered_signatures[$cache_key] = self::render($sig_id, $custom_data) ?? '';
            }
            
            // Replace the placeholder (remove if no signature found)
            $replacement = $rendered_signatures[$cache_key];
            $email_content = str_replace($full_placeholder, $replacement, $email_content);
        }
        
        return $email_content;
    }

    /**
     * @param array<string, string> $custom_data
     */
    public static function replaceSignaturePlaceholderPlainText(string $email_content, ?int $signature_id = null, array $custom_data = []): string {
        preg_match_all(self::SIGNATURE_PLACEHOLDER_PATTERN, $email_content, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            return $email_content;
        }

        /** @var array<string, string> $rendered_signatures */
        $rendered_signatures = [];

        foreach ($matches as $match) {
            $full_placeholder = $match[0];
            $specified_id = isset($match[1]) ? (int) $match[1] : null;
            $sig_id = $specified_id ?? $signature_id;
            $cache_key = self::cacheKeyForSignatureId($sig_id);

            if (!array_key_exists($cache_key, $rendered_signatures)) {
                $rendered_signatures[$cache_key] = self::renderPlainText($sig_id, $custom_data) ?? '';
            }

            $email_content = str_replace($full_placeholder, $rendered_signatures[$cache_key], $email_content);
        }

        return $email_content;
    }

    private static function convertHtmlToPlainText(string $html): string {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $text = $text ?? $html;
        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function cacheKeyForSignatureId(?int $signature_id): string {
        return $signature_id === null ? self::DEFAULT_SIGNATURE_CACHE_KEY : (string) $signature_id;
    }
}
