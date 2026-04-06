<?php

/**
 * @param array<string, mixed> $contract
 * @return string HTML fragment for the Bootstrap-styled public contract page
 */
function bdta_render_contract_client_contact_info(array $contract, bool $show_private_contact_details = true): string {
    $client_name = trim(bdta_normalize_to_string($contract['client_name'] ?? ''));
    $client_email = trim(bdta_normalize_to_string($contract['client_email'] ?? ''));
    $client_phone = trim(bdta_normalize_to_string($contract['client_phone'] ?? ''));
    $client_address = trim(bdta_normalize_to_string($contract['client_address'] ?? ''));

    $html = '<strong>For:</strong> ' . htmlspecialchars($client_name !== '' ? $client_name : 'Client', ENT_QUOTES, 'UTF-8') . '<br>';

    if ($show_private_contact_details && $client_email !== '') {
        $html .= '<strong>Email:</strong> ' . htmlspecialchars($client_email, ENT_QUOTES, 'UTF-8') . '<br>';
    }

    if ($show_private_contact_details && $client_phone !== '') {
        $html .= '<strong>Phone:</strong> ' . htmlspecialchars($client_phone, ENT_QUOTES, 'UTF-8') . '<br>';
    }

    if ($show_private_contact_details && $client_address !== '') {
        $html .= '<strong>Address:</strong><br>';
        $html .= '<span class="d-inline-block ms-3">' . nl2br(htmlspecialchars($client_address, ENT_QUOTES, 'UTF-8')) . '</span><br>';
    }

    return $html;
}
