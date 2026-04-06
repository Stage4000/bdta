<?php

const BDTA_CONTRACT_ACCESS_TOKEN_BYTES = 16;
// bin2hex() expands each byte into two hexadecimal characters.
const BDTA_CONTRACT_ACCESS_TOKEN_LENGTH = BDTA_CONTRACT_ACCESS_TOKEN_BYTES * 2;

function bdta_contract_string_value(mixed $value): string {
    if (is_string($value)) {
        return $value;
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return '';
}

function bdta_generate_contract_access_token(): string {
    return bin2hex(random_bytes(BDTA_CONTRACT_ACCESS_TOKEN_BYTES));
}

/**
 * @param array<string, mixed> $contract
 */
function bdta_contract_has_valid_access_token(array $contract, string $provided_token): bool {
    $stored_token = trim(bdta_contract_string_value($contract['access_token'] ?? ''));
    $provided_token = trim($provided_token);

    return $stored_token !== ''
        && $provided_token !== ''
        && hash_equals($stored_token, $provided_token);
}
