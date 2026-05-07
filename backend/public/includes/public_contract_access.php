<?php

require_once dirname(__DIR__, 2) . '/includes/public_access_links.php';

const BDTA_CONTRACT_ACCESS_TOKEN_BYTES = BDTA_PUBLIC_ACCESS_TOKEN_BYTES;
// bin2hex() expands each byte into two hexadecimal characters.
const BDTA_CONTRACT_ACCESS_TOKEN_LENGTH = BDTA_PUBLIC_ACCESS_TOKEN_LENGTH;

/**
 * Normalize contract helper input into a string for trimming/comparison.
 * Only text and numeric scalar values are rendered; booleans and unsupported
 * values (including arrays, objects, resources, and null) fall back to ''.
 *
 * @param mixed $value The value to normalize to a string.
 * @return string The normalized string value.
 */
function bdta_normalize_to_string(mixed $value): string {
    return bdta_public_access_string($value);
}

function bdta_generate_contract_access_token(): string {
    return bdta_generate_public_access_token(BDTA_CONTRACT_ACCESS_TOKEN_BYTES);
}

/**
 * @param array<string, mixed> $contract
 */
function bdta_contract_has_valid_access_token(array $contract, string $provided_token): bool {
    return bdta_has_valid_public_access_token($contract, 'access_token', $provided_token);
}
