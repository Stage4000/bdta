<?php
/** Pure, bounded MIME attachment traversal; fetch callback is injectable for offline tests. */
require_once __DIR__ . '/email_attachments.php';

function bdta_email_mime_parameter(object $part, string $wanted): string {
    $parameters = [];
    foreach (['parameters', 'dparameters'] as $property) {
        foreach (is_array($part->$property ?? null) ? $part->$property : [] as $parameter) {
            if (is_object($parameter) && is_string($parameter->attribute ?? null) && is_string($parameter->value ?? null)) {
                $parameters[strtolower($parameter->attribute)] = $parameter->value;
            }
        }
    }
    $charset = 'UTF-8';
    $segments = [];
    if (isset($parameters[$wanted . '*'])) {
        $segments[] = [$parameters[$wanted . '*'], true];
    } else {
        for ($i = 0; $i < 20; $i++) {
            $encoded_key = $wanted . '*' . $i . '*';
            $plain_key = $wanted . '*' . $i;
            if (isset($parameters[$encoded_key])) {
                $segments[] = [$parameters[$encoded_key], true];
            } elseif (isset($parameters[$plain_key])) {
                $segments[] = [$parameters[$plain_key], false];
            } else {
                break;
            }
        }
    }
    if ($segments !== []) {
        $decoded = '';
        foreach ($segments as $index => [$segment, $encoded]) {
            if ($index === 0 && $encoded && preg_match("/^([^']*)'[^']*'(.*)$/s", $segment, $matches) === 1) {
                $charset = $matches[1] !== '' ? $matches[1] : 'UTF-8';
                $segment = $matches[2];
            }
            $decoded .= $encoded ? rawurldecode($segment) : $segment;
        }
        $converted = @iconv($charset, 'UTF-8', $decoded);
        return $converted !== false ? $converted : $decoded;
    }
    $value = $parameters[$wanted] ?? '';
    if (str_contains($value, '=?')) {
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($decoded !== false) {
            return $decoded;
        }
    }
    return $value;
}

function bdta_email_mime_is_attachment(object $part): bool {
    return strtolower((string) ($part->disposition ?? '')) === 'attachment'
        || bdta_email_mime_parameter($part, 'filename') !== ''
        || bdta_email_mime_parameter($part, 'name') !== ''
        || ((int) ($part->type ?? 0) !== 0 && (int) ($part->type ?? 0) !== 1);
}

/** @return list<array<string, mixed>> */
function bdta_email_extract_attachments(object $structure, callable $fetch): array {
    $attachments = [];
    $total = 0;
    $visited = 0;
    $walk = function(object $part, string $number, int $depth) use (&$walk, &$attachments, &$total, &$visited, $fetch): void {
        if (++$visited > 500 || $depth > 20) {
            throw new RuntimeException('Email MIME structure exceeds safe limits.');
        }
        if (!bdta_email_mime_is_attachment($part) && is_array($part->parts ?? null)) {
            foreach ($part->parts as $index => $child) {
                if (is_object($child)) {
                    $walk($child, $number === '' ? (string) ($index + 1) : $number . '.' . ($index + 1), $depth + 1);
                }
            }
            return;
        }
        if (!bdta_email_mime_is_attachment($part)) {
            return;
        }
        $name = bdta_email_mime_parameter($part, 'filename') ?: bdta_email_mime_parameter($part, 'name');
        $name = bdta_email_attachment_name($name ?: 'attachment-' . (count($attachments) + 1) . '.bin');
        $unavailable = ['original_name' => $name, 'file_size' => max(0, (int) ($part->bytes ?? 0)),
            'status' => 'unavailable', 'error_message' => 'Attachment exceeds the supported size or count limit.'];
        if (count($attachments) >= BDTA_EMAIL_ATTACHMENT_MAX_COUNT) {
            // One visible limit marker, not unbounded attacker-controlled metadata.
            if (count($attachments) === BDTA_EMAIL_ATTACHMENT_MAX_COUNT) {
                $attachments[] = ['original_name' => 'Additional attachments', 'file_size' => 0,
                    'status' => 'unavailable', 'error_message' => 'Only the first 10 attachments can be imported. Check the original mailbox for additional files.'];
            }
            return;
        }
        $encoded_size = max(0, (int) ($part->bytes ?? 0));
        if ($encoded_size > (int) ceil(BDTA_EMAIL_ATTACHMENT_MAX_BYTES * 1.5) || $total >= BDTA_EMAIL_ATTACHMENT_TOTAL_BYTES) {
            $attachments[] = $unavailable;
            return;
        }
        $raw = $fetch($number === '' ? '1' : $number);
        if (!is_string($raw) || strlen($raw) > (int) ceil(BDTA_EMAIL_ATTACHMENT_MAX_BYTES * 1.5)) {
            throw new RuntimeException('Unable to retrieve attachment within safe limits.');
        }
        $encoding = (int) ($part->encoding ?? 0);
        $bytes = $encoding === 3 ? base64_decode($raw, true) : ($encoding === 4 ? quoted_printable_decode($raw) : $raw);
        if ($bytes === false) {
            $attachments[] = ['original_name' => $name, 'file_size' => 0, 'status' => 'unavailable',
                'error_message' => 'Attachment encoding is invalid. Check the original mailbox.'];
            return;
        }
        if (strlen($bytes) > BDTA_EMAIL_ATTACHMENT_MAX_BYTES || $total + strlen($bytes) > BDTA_EMAIL_ATTACHMENT_TOTAL_BYTES) {
            $attachments[] = $unavailable;
            return;
        }
        $attachments[] = bdta_email_store_attachment($name, $bytes);
        $total += strlen($bytes);
    };
    try {
        $walk($structure, '', 0);
    } catch (Throwable $e) {
        bdta_email_discard_staged_attachments($attachments);
        throw $e;
    }
    return $attachments;
}
