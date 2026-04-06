<?php

/**
 * @param array<string, mixed> $contract
 * @return string HTML fragment for the Bootstrap-styled public contract page
 */
function bdta_render_contract_client_contact_info(array $contract): string {
    $client_name = trim((string)($contract['client_name'] ?? ''));
    $client_email = trim((string)($contract['client_email'] ?? ''));
    $client_phone = trim((string)($contract['client_phone'] ?? ''));
    $client_address = trim((string)($contract['client_address'] ?? ''));

    $html = '<strong>For:</strong> ' . htmlspecialchars($client_name !== '' ? $client_name : 'Client', ENT_QUOTES, 'UTF-8') . '<br>';

    if ($client_email !== '') {
        $html .= '<strong>Email:</strong> ' . htmlspecialchars($client_email, ENT_QUOTES, 'UTF-8') . '<br>';
    }

    if ($client_phone !== '') {
        $html .= '<strong>Phone:</strong> ' . htmlspecialchars($client_phone, ENT_QUOTES, 'UTF-8') . '<br>';
    }

    if ($client_address !== '') {
        $html .= '<strong>Address:</strong><br>';
        $html .= '<span class="d-inline-block ms-3">' . nl2br(htmlspecialchars($client_address, ENT_QUOTES, 'UTF-8')) . '</span><br>';
    }

    return $html;
}
