<?php

const BDTA_CONTRACT_ACCESS_TOKEN_BYTES = 16;
const BDTA_CONTRACT_ACCESS_TOKEN_LENGTH = BDTA_CONTRACT_ACCESS_TOKEN_BYTES * 2;

function bdta_generate_contract_access_token(): string {
    return bin2hex(random_bytes(BDTA_CONTRACT_ACCESS_TOKEN_BYTES));
}

/**
 * @param array<string, mixed> $contract
 */
function bdta_contract_has_valid_access_token(array $contract, string $provided_token): bool {
    $stored_token = trim((string)($contract['access_token'] ?? ''));
    $provided_token = trim($provided_token);

    return $stored_token !== ''
        && $provided_token !== ''
        && hash_equals($stored_token, $provided_token);
}

/**
 * @param array<string, mixed> $contract
 * @return array{contract_id: int, can_view_private_contact_details: bool}
 */
function bdta_get_contract_access_state(array $contract, string $provided_token): array {
    return [
        'contract_id' => is_numeric($contract['id'] ?? null) ? (int)$contract['id'] : 0,
        'can_view_private_contact_details' => bdta_contract_has_valid_access_token($contract, $provided_token),
    ];
}
