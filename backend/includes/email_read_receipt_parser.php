<?php
/**
 * Conservative, no-I/O RFC 8098 receipt recognition.
 * A matching report is unverified sender-supplied evidence, never proof of reading.
 * Unrecognized reports must remain ordinary incoming correspondence.
 */

/**
 * @phpstan-type BdtaMdnReport array{original_message_id: string, final_recipient: string, disposition: string, displayed: bool, fingerprint: string}
 */
final class BdtaReadReceiptParser
{
    private const MAX_WIRE_BYTES = 32768;
    private const MAX_REPORT_BYTES = 16384;

    /**
     * Only the second part of a top-level RFC 8098 multipart/report is examined.
     * No recursion: a forwarded receipt must not upgrade the outer correspondence.
     * Caller fetches with FT_PEEK and must preserve ordinary email storage.
     *
     * @param callable(string): string $fetchPart
     * @return BdtaMdnReport|null
     */
    public static function extract(object $structure, callable $fetchPart): ?array
    {
        if (($structure->type ?? null) !== 1
            || !is_string($structure->subtype ?? null)
            || strtolower($structure->subtype) !== 'report') {
            return null;
        }
        $parameters = $structure->parameters ?? null;
        if (!is_array($parameters)) {
            return null;
        }
        $reportTypes = [];
        foreach ($parameters as $parameter) {
            if (is_object($parameter) && is_string($parameter->attribute ?? null)
                && strtolower($parameter->attribute) === 'report-type') {
                $reportTypes[] = $parameter->value ?? null;
            }
        }
        if (count($reportTypes) !== 1 || !is_string($reportTypes[0])
            || strtolower($reportTypes[0]) !== 'disposition-notification') {
            return null;
        }
        $parts = $structure->parts ?? null;
        if (!is_array($parts) || (count($parts) !== 2 && count($parts) !== 3)) {
            return null;
        }
        $parts = array_values($parts);
        $part = $parts[1];
        if (!is_object($part) || ($part->type ?? null) !== 2
            || !is_string($part->subtype ?? null)
            || strtolower($part->subtype) !== 'disposition-notification'
            || isset($part->parts)) {
            return null;
        }
        $size = $part->bytes ?? null;
        if (!is_int($size) || $size <= 0 || $size > self::MAX_WIRE_BYTES) {
            return null;
        }
        $encoding = $part->encoding ?? 0;
        if (!is_int($encoding) || $encoding < 0 || $encoding > 4) {
            return null;
        }
        $raw = $fetchPart('2');
        if ($raw === '' || strlen($raw) > self::MAX_WIRE_BYTES) {
            return null;
        }
        if ($encoding === 3) {
            $decoded = base64_decode($raw, true);
            if ($decoded === false) {
                return null;
            }
            $raw = $decoded;
        } elseif ($encoding === 4) {
            $raw = quoted_printable_decode($raw);
        }
        return self::parse($raw);
    }

    /** @return BdtaMdnReport|null */
    public static function parse(string $body): ?array
    {
        if ($body === '' || strlen($body) > self::MAX_REPORT_BYTES
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\xFF]/', $body) === 1) {
            return null;
        }
        $body = str_replace("\r\n", "\n", $body);
        if (strpos($body, "\r") !== false) {
            return null;
        }
        $lines = explode("\n", rtrim($body, "\n"));
        if (count($lines) > 256) {
            return null;
        }
        /** @var array<string, string> $fields */
        $fields = [];
        $last = null;
        foreach ($lines as $line) {
            if ($line === '' || strlen($line) > 998) {
                return null;
            }
            if ($line[0] === ' ' || $line[0] === "\t") {
                if ($last === null) {
                    return null;
                }
                $fields[$last] = trim($fields[$last] . ' ' . trim($line, " \t"), " \t");
                continue;
            }
            if (preg_match('/^([A-Za-z0-9-]+):[ \t]*(.*)$/D', $line, $matches) !== 1) {
                return null;
            }
            $last = strtolower($matches[1]);
            if (array_key_exists($last, $fields) || count($fields) >= 64) {
                return null;
            }
            $fields[$last] = trim($matches[2], " \t");
        }
        $id = $fields['original-message-id'] ?? '';
        if (!self::validMessageId($id)) {
            return null;
        }
        $recipient = $fields['final-recipient'] ?? '';
        if (preg_match('/^rfc822[ \t]*;[ \t]*(.+)$/iD', $recipient, $matches) !== 1) {
            return null;
        }
        $recipient = self::mailbox($matches[1]);
        if ($recipient === null) {
            return null;
        }
        $disposition = $fields['disposition'] ?? '';
        if (preg_match('/^(manual-action|automatic-action)[ \t]*\/[ \t]*(MDN-sent-manually|MDN-sent-automatically)[ \t]*;[ \t]*([A-Za-z][A-Za-z0-9-]*)(?:[ \t]*\/[ \t]*([A-Za-z0-9-, \t]+))?$/iD', $disposition, $matches) !== 1) {
            return null;
        }
        $type = strtolower($matches[3]);
        $modifiers = trim($matches[4] ?? '');
        // Do not call "displayed/error" displayed. Unknown extensions stay conservative.
        $displayed = $type === 'displayed' && $modifiers === '';
        return [
            'original_message_id' => $id,
            'final_recipient' => $recipient,
            'disposition' => $type,
            'displayed' => $displayed,
            'fingerprint' => hash('sha256', $id . "\n" . $recipient . "\n" . strtolower($disposition)),
        ];
    }

    /**
     * Exact Message-ID and address correlation only; From is forgeable.
     * Caller must also require an outgoing, opted-in, actually sent record,
     * and exactly one database match (never a LIMIT 1 ambiguous match).
     *
     * @param BdtaMdnReport $report
     */
    public static function matches(array $report, string $outgoingMessageId, string $recipient, string $singleOuterFrom): bool
    {
        $expected = self::mailbox($recipient);
        $from = self::mailbox($singleOuterFrom);
        return self::validMessageId($outgoingMessageId)
            && hash_equals($outgoingMessageId, $report['original_message_id'])
            && $expected !== null && $from !== null
            && hash_equals($expected, $report['final_recipient'])
            && hash_equals($expected, $from);
    }

    private static function validMessageId(string $id): bool
    {
        return strlen($id) <= 255
            && preg_match('/^<[^\s<>@]+@[^\s<>@]+>$/D', $id) === 1
            && preg_match('/[^\x21-\x7E]/', $id) !== 1;
    }

    private static function mailbox(string $email): ?string
    {
        $email = trim($email);
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/[\x00-\x20\x7F-\xFF]/', $email) === 1) {
            return null;
        }
        return strtolower($email);
    }
}
