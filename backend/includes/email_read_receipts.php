<?php
/** Optional, recipient-consented receipt requests. Never an open-tracking pixel. */

/** Strictly accept the checkbox JSON/form representations, never truthy arbitrary input. */
function bdta_email_read_receipt_requested(mixed $value): bool {
    if (in_array($value, [null, false, 0, '0', ''], true)) {
        return false;
    }
    if (in_array($value, [true, 1, '1'], true)) {
        return true;
    }
    throw new InvalidArgumentException('read_receipt_requested must be 0 or 1.');
}

function bdta_email_receipt_message_id(string $from): string {
    if (filter_var($from, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $from)) {
        throw new InvalidArgumentException('The configured sender address is invalid.');
    }
    $domain = substr($from, (int) strrpos($from, '@') + 1);
    return '<bdta-' . bin2hex(random_bytes(24)) . '@' . strtolower($domain) . '>';
}

/** @param array<string, mixed> $email
 * @return array{read_receipt_requested: bool, message_id: string}
 */
function bdta_email_receipt_options(array $email): array {
    $requested = bdta_email_read_receipt_requested($email['read_receipt_requested'] ?? null);
    $messageId = is_string($email['message_id'] ?? null) ? $email['message_id'] : '';
    if ($requested && !preg_match('/^<bdta-[a-f0-9]{48}@[a-z0-9.-]+>$/D', $messageId)) {
        throw new InvalidArgumentException('The saved receipt message identity is invalid. No email was sent.');
    }
    return ['read_receipt_requested' => $requested, 'message_id' => $requested ? $messageId : ''];
}

/** @param array<string, mixed> $options */
function bdta_email_apply_receipt_options(\PHPMailer\PHPMailer\PHPMailer $mail, array $options, string $from): void {
    $receipt = bdta_email_receipt_options($options);
    if (!$receipt['read_receipt_requested']) {
        return;
    }
    if (filter_var($from, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $from)) {
        throw new InvalidArgumentException('The configured receipt address is invalid.');
    }
    $mail->MessageID = $receipt['message_id'];
    // Only the server-configured sender can receive the requested response.
    $mail->ConfirmReadingTo = $from;
}

/** Store only a first, unverified displayed report for one actually-sent opted-in message.
 * @param array{original_message_id:string, final_recipient:string, disposition:string, displayed:bool, fingerprint:string} $report
 */
function bdta_email_record_read_receipt(PDO $conn, array $report, string $outerFrom, string $sourceMessageId, string $observedAt): bool {
    if (!$report['displayed'] || $sourceMessageId === '' || strlen($sourceMessageId) > 998 || preg_match('/[\x00-\x20\x7f]/', $sourceMessageId)) {
        return false;
    }
    $query = $conn->prepare("SELECT id, message_id, to_email FROM client_emails WHERE direction = 'outgoing' AND read_receipt_requested = 1 AND message_id = ? AND status IN ('sent', 'delivered') AND sent_at IS NOT NULL LIMIT 2");
    $query->execute([$report['original_message_id']]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    // Fail closed on a duplicate identity or a case-insensitive database collision.
    if (count($rows) !== 1 || !is_array($rows[0])) {
        return false;
    }
    $row = $rows[0];
    if (!is_string($row['message_id'] ?? null) || !is_string($row['to_email'] ?? null)
        || !BdtaReadReceiptParser::matches($report, $row['message_id'], $row['to_email'], $outerFrom)) {
        return false;
    }
    $update = $conn->prepare('UPDATE client_emails SET read_receipt_reported_at = ?, read_receipt_source_message_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND read_receipt_reported_at IS NULL');
    $update->execute([$observedAt, $sourceMessageId, $row['id']]);
    return $update->rowCount() === 1;
}
