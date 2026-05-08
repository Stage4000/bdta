<?php

require_once __DIR__ . '/public_access_links.php';

function bdta_contract_signing_string(mixed $value, string $default = ''): string
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

function bdta_contract_signing_client_ip(): string
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return scalar_string($_SERVER['HTTP_CF_CONNECTING_IP']);
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded_ip = trim(explode(',', scalar_string($_SERVER['HTTP_X_FORWARDED_FOR']))[0]);
        if (filter_var($forwarded_ip, FILTER_VALIDATE_IP)) {
            return $forwarded_ip;
        }
    }

    return scalar_string($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function bdta_generate_contract_number(PDO $conn): string
{
    $check_stmt = $conn->prepare('SELECT COUNT(*) FROM contracts WHERE contract_number = ?');

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $contract_number = 'CON-' . date('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $check_stmt->execute([$contract_number]);
        if ((int) $check_stmt->fetchColumn() === 0) {
            return $contract_number;
        }
    }

    throw new RuntimeException('Unable to generate a unique contract number.');
}

/**
 * @param array<string, mixed> $client_snapshot
 */
function bdta_render_contract_template_text(string $template_text, array $client_snapshot, string $signed_at): string
{
    $client_name = bdta_contract_signing_string($client_snapshot['name'] ?? '');
    $client_email = bdta_contract_signing_string($client_snapshot['email'] ?? '');
    $display_timestamp = strtotime($signed_at);
    if ($display_timestamp === false) {
        $display_timestamp = time();
    }

    return str_replace(
        ['{{client_name}}', '{{client_email}}', '{{date}}'],
        [$client_name, $client_email, date('F j, Y', $display_timestamp)],
        $template_text
    );
}

/**
 * @param array<string, mixed> $client_snapshot
 */
function bdta_create_signed_contract_from_template(
    PDO $conn,
    int $client_id,
    int $contract_template_id,
    string $typed_name,
    string $signature_font,
    ?string $signed_at = null,
    ?string $ip_address = null,
    ?string $user_agent = null,
    array $client_snapshot = []
): int {
    $client_id = (int) $client_id;
    $contract_template_id = (int) $contract_template_id;
    $typed_name = trim($typed_name);
    $signature_font = trim($signature_font);
    $signed_at = trim((string) $signed_at);
    $ip_address = $ip_address !== null ? trim($ip_address) : '';
    $user_agent = $user_agent !== null ? trim($user_agent) : '';

    if ($client_id <= 0) {
        throw new RuntimeException('Cannot persist a signed contract without a client.');
    }
    if ($contract_template_id <= 0) {
        throw new RuntimeException('Cannot persist a signed contract without a template.');
    }
    if ($typed_name === '') {
        throw new RuntimeException('Cannot persist a signed contract without a typed signature name.');
    }
    if ($signature_font === '') {
        $signature_font = 'font-dancing';
    }
    if ($signed_at === '') {
        $signed_at = date('Y-m-d H:i:s');
    }
    if ($ip_address === '') {
        $ip_address = bdta_contract_signing_client_ip();
    }

    $template_stmt = $conn->prepare('
        SELECT name, description, template_text
        FROM contract_templates
        WHERE id = ? AND is_active = 1
    ');
    $template_stmt->execute([$contract_template_id]);
    $template = assoc_row($template_stmt->fetch(PDO::FETCH_ASSOC));
    if ($template === []) {
        throw new RuntimeException('Required contract template is unavailable.');
    }

    if (
        bdta_contract_signing_string($client_snapshot['name'] ?? '') === ''
        || bdta_contract_signing_string($client_snapshot['email'] ?? '') === ''
    ) {
        $client_stmt = $conn->prepare('SELECT name, email FROM clients WHERE id = ?');
        $client_stmt->execute([$client_id]);
        $db_client = assoc_row($client_stmt->fetch(PDO::FETCH_ASSOC));
        if ($db_client !== []) {
            if (bdta_contract_signing_string($client_snapshot['name'] ?? '') === '') {
                $client_snapshot['name'] = $db_client['name'] ?? '';
            }
            if (bdta_contract_signing_string($client_snapshot['email'] ?? '') === '') {
                $client_snapshot['email'] = $db_client['email'] ?? '';
            }
        }
    }

    $contract_text = bdta_render_contract_template_text(
        bdta_contract_signing_string($template['template_text'] ?? ''),
        $client_snapshot,
        $signed_at
    );
    $contract_number = bdta_generate_contract_number($conn);
    $created_date = substr($signed_at, 0, 10);
    if ($created_date === '') {
        $created_date = date('Y-m-d');
    }

    $insert_stmt = $conn->prepare('
        INSERT INTO contracts (
            contract_number,
            client_id,
            title,
            description,
            contract_text,
            status,
            created_date,
            effective_date,
            signed_date,
            signature_data,
            signature_typed_name,
            signature_font,
            ip_address
        ) VALUES (?, ?, ?, ?, ?, \'signed\', ?, ?, ?, NULL, ?, ?, ?)
    ');
    $insert_stmt->execute([
        $contract_number,
        $client_id,
        bdta_contract_signing_string($template['name'] ?? 'Contract'),
        bdta_contract_signing_string($template['description'] ?? ''),
        $contract_text,
        $created_date,
        $created_date,
        $signed_at,
        $typed_name,
        $signature_font,
        $ip_address,
    ]);

    $contract_id = (int) $conn->lastInsertId();
    if ($contract_id <= 0) {
        throw new RuntimeException('Unable to create signed contract record.');
    }

    bdta_ensure_contract_access_token($conn, $contract_id);

    $log_stmt = $conn->prepare('
        INSERT INTO contract_signature_log
            (contract_id, event_type, details, ip_address, user_agent, created_at)
        VALUES (?, \'signed\', ?, ?, ?, ?)
    ');
    $log_stmt->execute([
        $contract_id,
        'Contract signed electronically by "' . $typed_name . '" using style ' . $signature_font . '.',
        $ip_address,
        $user_agent,
        $signed_at,
    ]);

    return $contract_id;
}
