<?php

function bdta_generate_contract_access_token(): string {
    return bin2hex(random_bytes(16));
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
