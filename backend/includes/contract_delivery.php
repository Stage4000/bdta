<?php

/**
 * @param array<string, mixed> $contract
 * @return array{subject: string, html_body: string, text_body: string}
 */
function bdta_build_contract_delivery_email(array $contract, string $contract_link): array
{
    $client_name = bdta_contract_delivery_string($contract['client_name'] ?? '', 'Client');
    $contract_title = bdta_contract_delivery_string($contract['title'] ?? '', 'Your Contract');
    $contract_number = bdta_contract_delivery_string($contract['contract_number'] ?? '');
    $safe_client_name = htmlspecialchars($client_name, ENT_QUOTES, 'UTF-8');
    $safe_contract_title = htmlspecialchars($contract_title, ENT_QUOTES, 'UTF-8');
    $safe_contract_number = htmlspecialchars($contract_number, ENT_QUOTES, 'UTF-8');
    $safe_contract_link = htmlspecialchars($contract_link, ENT_QUOTES, 'UTF-8');

    $subject = 'Please review and sign: ' . $contract_title;
    $contract_number_line_html = $contract_number !== ''
        ? '<p><strong>Contract #:</strong> ' . $safe_contract_number . '</p>'
        : '';
    $contract_number_line_text = $contract_number !== ''
        ? "Contract #: {$contract_number}\n\n"
        : '';

    $html_body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2563eb; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
        .button { display: inline-block; padding: 12px 24px; margin: 20px 0; background: #2563eb; color: white !important; text-decoration: none; border-radius: 6px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Contract Ready for Review</h1>
        </div>
        <div class="content">
            <p>Dear {$safe_client_name},</p>
            <p>Please review and sign the following contract:</p>
            <p><strong>{$safe_contract_title}</strong></p>
            {$contract_number_line_html}
            <div style="text-align: center;">
                <a href="{$safe_contract_link}" class="button">View &amp; Sign Contract</a>
            </div>
            <p>If you have any questions, please reply to this email before signing.</p>
            <p>Best regards,<br>Brook's Dog Training Academy</p>
        </div>
    </div>
</body>
</html>
HTML;

    $text_body = <<<TEXT
CONTRACT READY FOR REVIEW - Brook's Dog Training Academy

Dear {$client_name},

Please review and sign the following contract:

{$contract_title}
{$contract_number_line_text}View & Sign Contract: {$contract_link}

If you have any questions, please reply to this email before signing.

Best regards,
Brook's Dog Training Academy
TEXT;

    return [
        'subject' => $subject,
        'html_body' => $html_body,
        'text_body' => $text_body,
    ];
}

/**
 * @return array{success: bool, message: string}
 */
function bdta_send_contract_to_client(PDO $conn, int $contract_id): array
{
    if ($contract_id <= 0) {
        return ['success' => false, 'message' => 'Invalid contract.'];
    }

    require_once __DIR__ . '/email_service.php';
    require_once __DIR__ . '/public_access_links.php';

    $stmt = $conn->prepare("
        SELECT co.*, c.name AS client_name, c.email AS client_email
        FROM contracts co
        INNER JOIN clients c ON co.client_id = c.id
        WHERE co.id = ?
    ");
    $stmt->execute([$contract_id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($contract)) {
        return ['success' => false, 'message' => 'Contract not found.'];
    }

    $client_email = bdta_contract_delivery_string($contract['client_email'] ?? '');
    if ($client_email === '') {
        return ['success' => false, 'message' => 'Client email is missing.'];
    }

    $contract_link = bdta_get_public_contract_url($conn, $contract_id, $contract['access_token'] ?? null);
    $email = bdta_build_contract_delivery_email($contract, $contract_link);
    $client_id = bdta_contract_delivery_int($contract['client_id'] ?? 0);

    $email_service = new EmailService(null, $conn);
    $result = $email_service->sendGenericEmail(
        $client_email,
        $email['subject'],
        $email['html_body'],
        $email['text_body'],
        EmailService::MAIL_TYPE_GENERIC,
        $client_id > 0 ? $client_id : null
    );

    if ($result['success']) {
        $update = $conn->prepare("
            UPDATE contracts
            SET status = 'sent',
                sent_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $update->execute([$contract_id]);
    }

    return $result;
}

function bdta_contract_delivery_string(mixed $value, string $default = ''): string
{
    if (is_string($value)) {
        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : $default;
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return $default;
}

function bdta_contract_delivery_int(mixed $value): int
{
    return is_numeric($value) ? (int) $value : 0;
}
