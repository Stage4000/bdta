<?php

const BDTA_PUBLIC_ACCESS_TOKEN_BYTES = 16;
const BDTA_PUBLIC_ACCESS_TOKEN_LENGTH = BDTA_PUBLIC_ACCESS_TOKEN_BYTES * 2;

function bdta_public_access_string(mixed $value): string
{
    if (is_string($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return '';
}

function bdta_public_access_int(mixed $value): int
{
    return is_numeric($value) ? (int) $value : 0;
}

function bdta_generate_public_access_token(int $bytes = BDTA_PUBLIC_ACCESS_TOKEN_BYTES): string
{
    if ($bytes < 1) {
        $bytes = BDTA_PUBLIC_ACCESS_TOKEN_BYTES;
    }

    return bin2hex(random_bytes($bytes));
}

function bdta_public_access_token_has_expected_format(
    string $token,
    int $expected_length = BDTA_PUBLIC_ACCESS_TOKEN_LENGTH
): bool {
    return $token !== ''
        && $expected_length > 0
        && preg_match('/^[a-f0-9]{' . $expected_length . '}$/', $token) === 1;
}

/**
 * @param array<string, mixed> $row
 */
function bdta_has_valid_public_access_token(array $row, string $column, string $provided_token): bool
{
    $stored_token = trim(bdta_public_access_string($row[$column] ?? ''));
    $provided_token = trim($provided_token);

    return $stored_token !== ''
        && $provided_token !== ''
        && hash_equals($stored_token, $provided_token);
}

/**
 * @return array{has_valid_token: bool, is_portal_owner: bool, is_admin: bool, can_view: bool}
 * @param array<string, mixed> $row
 */
function bdta_public_record_access_context(
    array $row,
    string $token_column,
    string $provided_token,
    string $client_column = 'client_id'
): array {
    $record_client_id = bdta_public_access_int($row[$client_column] ?? 0);
    $is_portal_owner = false;
    if (function_exists('isPortalLoggedIn') && function_exists('portalClientId') && isPortalLoggedIn()) {
        $portal_client_id = bdta_public_access_int(portalClientId());
        $is_portal_owner = $record_client_id > 0 && $portal_client_id > 0 && $record_client_id === $portal_client_id;
    }

    $is_admin = function_exists('isLoggedIn') && isLoggedIn();
    $has_valid_token = bdta_has_valid_public_access_token($row, $token_column, $provided_token);

    return [
        'has_valid_token' => $has_valid_token,
        'is_portal_owner' => $is_portal_owner,
        'is_admin' => $is_admin,
        'can_view' => $has_valid_token || $is_portal_owner || $is_admin,
    ];
}

function bdta_public_access_base_url(): string
{
    if (!function_exists('getDynamicBaseUrl')) {
        return '';
    }

    return rtrim(bdta_public_access_string(getDynamicBaseUrl()), '/');
}

function bdta_public_access_absolute_url(string $path): string
{
    $base_url = bdta_public_access_base_url();
    if ($base_url === '') {
        return $path;
    }

    return $base_url . $path;
}

function bdta_ensure_contract_access_token(PDO $conn, int $contract_id, mixed $existing_token = null): string
{
    $normalized_existing_token = trim(bdta_public_access_string($existing_token));
    if ($normalized_existing_token !== '') {
        return $normalized_existing_token;
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $token = bdta_generate_public_access_token();

        $check_stmt = $conn->prepare('SELECT COUNT(*) FROM contracts WHERE access_token = ?');
        $check_stmt->execute([$token]);
        if ((int) $check_stmt->fetchColumn() > 0) {
            continue;
        }

        $update_stmt = $conn->prepare("
            UPDATE contracts
            SET access_token = ?
            WHERE id = ?
              AND COALESCE(NULLIF(access_token, ''), '') = ''
        ");
        $update_stmt->execute([$token, $contract_id]);
        if ($update_stmt->rowCount() > 0) {
            return $token;
        }

        $existing_stmt = $conn->prepare('SELECT access_token FROM contracts WHERE id = ?');
        $existing_stmt->execute([$contract_id]);
        $current_token = trim(bdta_public_access_string($existing_stmt->fetchColumn()));
        if ($current_token !== '') {
            return $current_token;
        }
    }

    throw new RuntimeException('Unable to ensure a contract access token.');
}

function bdta_ensure_quote_access_token(PDO $conn, int $quote_id, mixed $existing_token = null): string
{
    $normalized_existing_token = trim(bdta_public_access_string($existing_token));
    if ($normalized_existing_token !== '') {
        return $normalized_existing_token;
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $token = bdta_generate_public_access_token();

        $check_stmt = $conn->prepare('SELECT COUNT(*) FROM quotes WHERE access_token = ?');
        $check_stmt->execute([$token]);
        if ((int) $check_stmt->fetchColumn() > 0) {
            continue;
        }

        $update_stmt = $conn->prepare("
            UPDATE quotes
            SET access_token = ?
            WHERE id = ?
              AND COALESCE(NULLIF(access_token, ''), '') = ''
        ");
        $update_stmt->execute([$token, $quote_id]);
        if ($update_stmt->rowCount() > 0) {
            return $token;
        }

        $existing_stmt = $conn->prepare('SELECT access_token FROM quotes WHERE id = ?');
        $existing_stmt->execute([$quote_id]);
        $current_token = trim(bdta_public_access_string($existing_stmt->fetchColumn()));
        if ($current_token !== '') {
            return $current_token;
        }
    }

    throw new RuntimeException('Unable to ensure a quote access token.');
}

function bdta_ensure_form_submission_access_token(PDO $conn, int $submission_id, mixed $existing_token = null): string
{
    $normalized_existing_token = trim(bdta_public_access_string($existing_token));
    if ($normalized_existing_token !== '') {
        return $normalized_existing_token;
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $token = bdta_generate_public_access_token();

        $check_stmt = $conn->prepare('SELECT COUNT(*) FROM form_submissions WHERE access_token = ?');
        $check_stmt->execute([$token]);
        if ((int) $check_stmt->fetchColumn() > 0) {
            continue;
        }

        $update_stmt = $conn->prepare("
            UPDATE form_submissions
            SET access_token = ?
            WHERE id = ?
              AND COALESCE(NULLIF(access_token, ''), '') = ''
        ");
        $update_stmt->execute([$token, $submission_id]);
        if ($update_stmt->rowCount() > 0) {
            return $token;
        }

        $existing_stmt = $conn->prepare('SELECT access_token FROM form_submissions WHERE id = ?');
        $existing_stmt->execute([$submission_id]);
        $current_token = trim(bdta_public_access_string($existing_stmt->fetchColumn()));
        if ($current_token !== '') {
            return $current_token;
        }
    }

    throw new RuntimeException('Unable to ensure a form submission access token.');
}

function bdta_ensure_booking_ical_token(PDO $conn, int $booking_id, mixed $existing_token = null): string
{
    $normalized_existing_token = trim(bdta_public_access_string($existing_token));
    if ($normalized_existing_token !== '') {
        return $normalized_existing_token;
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $token = bdta_generate_public_access_token();

        $check_stmt = $conn->prepare('SELECT COUNT(*) FROM bookings WHERE ical_token = ?');
        $check_stmt->execute([$token]);
        if ((int) $check_stmt->fetchColumn() > 0) {
            continue;
        }

        $update_stmt = $conn->prepare("
            UPDATE bookings
            SET ical_token = ?
            WHERE id = ?
              AND COALESCE(NULLIF(ical_token, ''), '') = ''
        ");
        $update_stmt->execute([$token, $booking_id]);
        if ($update_stmt->rowCount() > 0) {
            return $token;
        }

        $existing_stmt = $conn->prepare('SELECT ical_token FROM bookings WHERE id = ?');
        $existing_stmt->execute([$booking_id]);
        $current_token = trim(bdta_public_access_string($existing_stmt->fetchColumn()));
        if ($current_token !== '') {
            return $current_token;
        }
    }

    throw new RuntimeException('Unable to ensure a booking iCal token.');
}

function bdta_get_public_contract_path(PDO $conn, int $contract_id, mixed $existing_token = null): string
{
    $token = bdta_ensure_contract_access_token($conn, $contract_id, $existing_token);
    return '/backend/public/contract.php?token=' . rawurlencode($token);
}

function bdta_get_public_contract_url(PDO $conn, int $contract_id, mixed $existing_token = null): string
{
    return bdta_public_access_absolute_url(
        bdta_get_public_contract_path($conn, $contract_id, $existing_token)
    );
}

function bdta_get_public_quote_path(PDO $conn, int $quote_id, mixed $existing_token = null): string
{
    $token = bdta_ensure_quote_access_token($conn, $quote_id, $existing_token);
    return '/backend/public/quote.php?token=' . rawurlencode($token);
}

function bdta_get_public_quote_url(PDO $conn, int $quote_id, mixed $existing_token = null): string
{
    return bdta_public_access_absolute_url(
        bdta_get_public_quote_path($conn, $quote_id, $existing_token)
    );
}

function bdta_get_public_form_submission_path(PDO $conn, int $submission_id, mixed $existing_token = null): string
{
    $token = bdta_ensure_form_submission_access_token($conn, $submission_id, $existing_token);
    return '/backend/public/form.php?token=' . rawurlencode($token);
}

function bdta_get_public_form_submission_url(PDO $conn, int $submission_id, mixed $existing_token = null): string
{
    return bdta_public_access_absolute_url(
        bdta_get_public_form_submission_path($conn, $submission_id, $existing_token)
    );
}

function bdta_get_public_booking_ical_path(PDO $conn, int $booking_id, mixed $existing_token = null): string
{
    $token = bdta_ensure_booking_ical_token($conn, $booking_id, $existing_token);
    return '/backend/public/download_ical.php?token=' . rawurlencode($token);
}

function bdta_get_public_booking_ical_url(PDO $conn, int $booking_id, mixed $existing_token = null): string
{
    return bdta_public_access_absolute_url(
        bdta_get_public_booking_ical_path($conn, $booking_id, $existing_token)
    );
}

/**
 * @param array<string, mixed> $quote
 */
function bdta_quote_has_valid_access_token(array $quote, string $provided_token): bool
{
    return bdta_has_valid_public_access_token($quote, 'access_token', $provided_token);
}

/**
 * @param array<string, mixed> $submission
 */
function bdta_form_submission_has_valid_access_token(array $submission, string $provided_token): bool
{
    return bdta_has_valid_public_access_token($submission, 'access_token', $provided_token);
}

/**
 * @param array<string, mixed> $booking
 */
function bdta_booking_has_valid_ical_token(array $booking, string $provided_token): bool
{
    return bdta_has_valid_public_access_token($booking, 'ical_token', $provided_token);
}
