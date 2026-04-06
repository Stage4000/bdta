<?php

const BDTA_CONTRACT_ACCESS_TOKEN_BYTES = 16;
// bin2hex() expands each byte into two hexadecimal characters.
const BDTA_CONTRACT_ACCESS_TOKEN_LENGTH = BDTA_CONTRACT_ACCESS_TOKEN_BYTES * 2;

/**
 * Normalize contract helper input into a string for trimming/comparison.
 * Only text and numeric scalar values are rendered; booleans and unsupported
 * values (including arrays, objects, resources, and null) fall back to ''.
 *
 * @param mixed $value The value to normalize to a string.
 * @return string The normalized string value.
 */
function bdta_normalize_to_string(mixed $value): string {
    if (is_string($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    // Keep unsupported values non-renderable so public contract pages fail closed
    // on unexpected data instead of rendering debug-ish placeholders to clients.
    return '';
}

function bdta_generate_contract_access_token(): string {
    return bin2hex(random_bytes(BDTA_CONTRACT_ACCESS_TOKEN_BYTES));
}

/**
 * @param array<string, mixed> $contract
 */
function bdta_contract_has_valid_access_token(array $contract, string $provided_token): bool {
    $stored_token = trim(bdta_normalize_to_string($contract['access_token'] ?? ''));
    $provided_token = trim($provided_token);

    return $stored_token !== ''
        && $provided_token !== ''
        && hash_equals($stored_token, $provided_token);
}
