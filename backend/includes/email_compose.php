<?php
require_once __DIR__ . '/email_service.php';

/** Record composed messages before transport, including sends to known clients from the inbox.
 * @param list<string> $cc
 * @param list<string> $bcc
 * @param array<string, mixed> $uploads
 * @return array<string, mixed>
 */
function bdta_email_send_composed_history(PDO $conn, string $to, array $cc, array $bcc, string $subject, string $html, string $text, array $uploads, bool $keep_unmatched = false, ?int $expected_count = null): array {
    $attachments = bdta_email_collect_uploads($uploads, $expected_count);
    $committed = false;
    try {
        $conn->beginTransaction();
        $client_id = 0;
        if (!$keep_unmatched) {
            $stmt = $conn->prepare('SELECT id FROM clients WHERE LOWER(email) = ? LIMIT 1');
            $stmt->execute([strtolower($to)]);
            $client_id = (int) $stmt->fetchColumn();
        }
        $now = currentUtcDateTime();
        $from = bdta_email_string(Settings::get('email_from_address', 'bookings@brooksdogtrainingacademy.com'));
        if ($client_id > 0) {
            $stmt = $conn->prepare("INSERT INTO client_emails (client_id, direction, status, from_email, to_email, subject, body_html, body_text, created_by, created_at, updated_at, attachments_processed)
                VALUES (?, 'outgoing', 'pending', ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([$client_id, $from, $to, $subject, $html, $text, $_SESSION['user_id'] ?? null, $now, $now]);
            $kind = 'client';
        } else {
            $stmt = $conn->prepare("INSERT INTO unmatched_emails (from_email, from_name, to_email, subject, body_html, body_text, received_at, direction, created_at, delivery_status, attachments_processed)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'outgoing', ?, 'pending', 1)");
            $stmt->execute([$from, bdta_email_string(Settings::get('email_from_name', "Brook's Dog Training Academy")), $to, $subject, $html, $text, $now, $now]);
            $kind = 'unmatched';
        }
        $email_id = (int) $conn->lastInsertId();
        bdta_email_attach_records($conn, $kind, $email_id, $attachments);
        $conn->commit();
        $committed = true;
    } finally {
        if (!$committed) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            bdta_email_discard_staged_attachments($attachments);
        }
    }
    $service = new EmailService(null, $conn);
    $result = $service->sendComposeEmail($to, $cc, $bcc, $subject, $html, $text, $attachments);
    $status = $result['success'] ? 'sent' : 'failed';
    try {
        if ($kind === 'client') {
            $stmt = $conn->prepare('UPDATE client_emails SET status = ?, sent_at = ?, failed_at = ?, error_message = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([$status, $result['success'] ? $now : null, $result['success'] ? null : $now,
                $result['success'] ? null : $result['message'], $now, $email_id]);
        } else {
            $conn->prepare('UPDATE unmatched_emails SET delivery_status = ? WHERE id = ?')->execute([$status, $email_id]);
        }
    } catch (Throwable $e) {
        error_log('Unable to update composed email delivery history.');
        if ($result['success']) {
            $result['message'] = 'Email sent, but its history status could not be updated. Do not resend.';
        }
    }
    return $result + ['email_id' => $email_id, 'email_kind' => $kind];
}
