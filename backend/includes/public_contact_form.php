<?php
/**
 * Public contact form submission helper.
 */

/**
 * Remove the public homepage contact form markup from rendered HTML.
 */
function bdta_strip_public_contact_form_markup(string $html): string
{
    if ($html === '' || stripos($html, 'id="contactForm"') === false) {
        return $html;
    }

    if (!class_exists('DOMDocument')) {
        return bdta_strip_public_contact_form_markup_fallback($html);
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $wrapper_id = 'bdta-public-contact-form-root';
    $escaped_wrapper_id = htmlspecialchars($wrapper_id, ENT_QUOTES, 'UTF-8');
    $previous_errors = libxml_use_internal_errors(true);
    $encoded_html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
    $dom->loadHTML('<?xml encoding="UTF-8"><div id="' . $escaped_wrapper_id . '">' . $encoded_html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous_errors);

    $xpath = new DOMXPath($dom);
    $root_nodes = $xpath->query('//*[@id="' . $wrapper_id . '"]');
    $root = $root_nodes !== false ? $root_nodes->item(0) : null;

    if (!$root instanceof DOMElement) {
        return bdta_strip_public_contact_form_markup_fallback($html);
    }

    $form_nodes = $xpath->query('//*[@id="contactForm"]');
    if ($form_nodes === false || $form_nodes->length === 0) {
        return $html;
    }

    $forms = iterator_to_array($form_nodes);
    foreach ($forms as $form) {
        if (!$form instanceof DOMElement) {
            continue;
        }

        $container = bdta_find_public_contact_form_container($form);
        $row = bdta_find_public_contact_row($container ?? $form);

        if ($container instanceof DOMElement && $container->parentNode) {
            $container->parentNode->removeChild($container);
        } elseif ($form->parentNode) {
            bdta_remove_public_contact_form_heading($form);
            $form->parentNode->removeChild($form);
        }

        if ($row instanceof DOMElement) {
            bdta_add_class_name($row, 'justify-content-center');
        }
    }

    $sanitized = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $sanitized .= $dom->saveHTML($child);
    }

    return trim(str_replace('<?xml encoding="UTF-8">', '', $sanitized));
}

function bdta_strip_public_contact_form_markup_fallback(string $html): string
{
    $patterns = [
        '/\s*<div\b[^>]*class="[^"]*\bcol-(?:[a-z]+-)?\d+[^"]*"[^>]*>\s*<div\b[^>]*class="[^"]*\bcard\b[^"]*"[^>]*>[\s\S]*?<form\b[^>]*id="contactForm"[^>]*>[\s\S]*?<\/form>[\s\S]*?<\/div>\s*<\/div>/i',
        '/\s*<form\b[^>]*id="contactForm"[^>]*>[\s\S]*?<\/form>/i',
    ];

    foreach ($patterns as $pattern) {
        $updated_html = preg_replace($pattern, '', $html, 1);
        if ($updated_html !== null && $updated_html !== $html) {
            return $updated_html;
        }
    }

    return $html;
}

function bdta_find_public_contact_form_container(DOMElement $form): ?DOMElement
{
    for ($node = $form; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null) {
        $class_name = $node->getAttribute('class');
        if ($class_name === '') {
            continue;
        }

        if (preg_match('/\bcol(?:-[a-z]+)?-\d+\b/i', $class_name) === 1) {
            return $node;
        }
    }

    for ($node = $form; $node instanceof DOMElement; $node = $node->parentNode instanceof DOMElement ? $node->parentNode : null) {
        if (preg_match('/\bcard\b/i', $node->getAttribute('class')) === 1) {
            return $node;
        }
    }

    return null;
}

function bdta_find_public_contact_row(DOMElement $node): ?DOMElement
{
    for ($current = $node; $current instanceof DOMElement; $current = $current->parentNode instanceof DOMElement ? $current->parentNode : null) {
        if (preg_match('/\brow\b/i', $current->getAttribute('class')) === 1) {
            return $current;
        }
    }

    return null;
}

function bdta_remove_public_contact_form_heading(DOMElement $form): void
{
    $sibling = $form->previousSibling;
    while ($sibling !== null && $sibling->nodeType === XML_TEXT_NODE && trim((string) $sibling->textContent) === '') {
        $sibling = $sibling->previousSibling;
    }

    if ($sibling instanceof DOMElement && preg_match('/^h[1-6]$/i', $sibling->tagName) === 1 && $sibling->parentNode) {
        $sibling->parentNode->removeChild($sibling);
    }
}

function bdta_add_class_name(DOMElement $element, string $class_name): void
{
    $existing_class_names = trim($element->getAttribute('class'));
    $classes = $existing_class_names === ''
        ? []
        : preg_split('/\s+/', $existing_class_names);
    if (!in_array($class_name, $classes, true)) {
        $classes[] = $class_name;
        $element->setAttribute('class', trim(implode(' ', array_filter($classes))));
    }
}

/**
 * @param array<string, mixed> $payload
 * @return array{success:bool,error?:string,client_id?:int}
 */
function bdta_handle_public_contact_submission(PDO $conn, array $payload): array {
    $name = trim(array_string_value($payload, 'name'));
    $email = strtolower(trim(array_string_value($payload, 'email')));
    $phone = trim(array_string_value($payload, 'phone'));
    $message = trim(array_string_value($payload, 'message'));
    $service = trim(array_string_value($payload, 'service'));

    if ($name === '' || $email === '' || $message === '') {
        return ['success' => false, 'error' => 'Name, email, and message are required.'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please enter a valid email address.'];
    }

    $notes_parts = [
        'Public contact form message submitted on ' . currentUtcDateTime(),
    ];
    if ($service !== '') {
        $notes_parts[] = 'Service interested in: ' . $service;
    }
    $notes_parts[] = 'Message: ' . $message;
    $contact_note = implode("\n", $notes_parts);

    $stmt = $conn->prepare("
        SELECT id, notes
        FROM clients
        WHERE email = ?
        ORDER BY updated_at DESC, created_at DESC, id DESC
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $existing_row = assoc_row($existing);

    if ($existing_row !== []) {
        $client_id = array_int_value($existing_row, 'id');
        $existing_notes = trim(array_string_value($existing_row, 'notes'));
        $merged_notes = $existing_notes === ''
            ? $contact_note
            : ($existing_notes . "\n\n" . $contact_note);

        $stmt = $conn->prepare("
            UPDATE clients
            SET notes = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$merged_notes, $client_id]);

        return ['success' => true, 'client_id' => $client_id];
    }

    $stmt = $conn->prepare("
        INSERT INTO clients (name, email, phone, notes, created_at, updated_at)
        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$name, $email, $phone, $contact_note]);

    return ['success' => true, 'client_id' => safe_int($conn->lastInsertId())];
}
