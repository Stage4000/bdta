<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/form_types.php';
require_once __DIR__ . '/email_service.php';
require_once __DIR__ . '/settings.php';

function bdta_get_public_form_request_url(int $submission_id): string
{
    return getDynamicBaseUrl() . '/backend/public/form.php?id=' . $submission_id;
}

function bdta_get_public_booking_request_url(string $unique_link): string
{
    return getDynamicBaseUrl() . '/backend/public/book.php?link=' . urlencode($unique_link);
}

/**
 * @return array<string, mixed>
 */
function bdta_create_form_request(PDO $conn, int $template_id, int $client_id, ?int $booking_id = null, ?int $pet_id = null, ?string $sent_at = null): array
{
    $template_check = $conn->prepare("SELECT id FROM form_templates WHERE id = ? AND is_active = 1 LIMIT 1");
    $template_check->execute([$template_id]);
    if (!$template_check->fetchColumn()) {
        throw new RuntimeException('The selected form template is not available.');
    }

    $stmt = $conn->prepare("
        INSERT INTO form_submissions (
            client_id, template_id, booking_id, pet_id, responses, status, sent_at
        ) VALUES (?, ?, ?, ?, ?, 'pending', ?)
    ");
    $stmt->execute([
        $client_id,
        $template_id,
        $booking_id !== null && $booking_id > 0 ? $booking_id : null,
        $pet_id !== null && $pet_id > 0 ? $pet_id : null,
        '{}',
        $sent_at,
    ]);

    $submission_id = (int) $conn->lastInsertId();

    return [
        'submission_id' => $submission_id,
        'url' => bdta_get_public_form_request_url($submission_id),
    ];
}

/**
 * @return array{success: bool, message: string, email_id?: int}
 */
function bdta_send_client_form_link_email(
    PDO $conn,
    int $client_id,
    string $subject,
    string $html_body,
    string $text_body
): array {
    $stmt = $conn->prepare("SELECT email, name FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($client) || scalar_string($client['email'] ?? '') === '') {
        return [
            'success' => false,
            'message' => 'Client email address is not available.',
        ];
    }

    $from_email = Settings::get('email_from_address', 'bookings@brooksdogtrainingacademy.com');
    $body_text = $text_body !== '' ? $text_body : strip_tags($html_body);

    $stmt = $conn->prepare("
        INSERT INTO client_emails (
            client_id, direction, status, from_email, to_email,
            subject, body_html, body_text, created_by, created_at, updated_at
        ) VALUES (?, 'outgoing', 'pending', ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([
        $client_id,
        $from_email,
        scalar_string($client['email'] ?? ''),
        $subject,
        $html_body,
        $body_text,
        $_SESSION['admin_id'] ?? null,
    ]);

    $email_id = (int) $conn->lastInsertId();
    $email_service = new EmailService();
    $result = $email_service->sendGenericEmail(
        scalar_string($client['email'] ?? ''),
        $subject,
        $html_body,
        $body_text,
        EmailService::MAIL_TYPE_COMPOSE,
        $client_id
    );

    if (!$result['success']) {
        $conn->prepare("
            UPDATE client_emails
            SET status = 'failed',
                failed_at = CURRENT_TIMESTAMP,
                error_message = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([
            scalar_string($result['message']),
            $email_id,
        ]);

        return [
            'success' => false,
            'message' => scalar_string($result['message']),
            'email_id' => $email_id,
        ];
    }

    $conn->prepare("
        UPDATE client_emails
        SET status = 'sent',
            sent_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$email_id]);

    return [
        'success' => true,
        'message' => 'Email sent successfully.',
        'email_id' => $email_id,
    ];
}
