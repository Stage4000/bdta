#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/public_access_links.php';
require_once dirname(__DIR__) . '/backend/public/includes/public_contract_access.php';

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function assertSameValue(string $label, mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $label . " failed.\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

function fetchStringColumn(PDO $conn, string $sql): string
{
    $stmt = $conn->query($sql);
    if ($stmt === false) {
        fwrite(STDERR, 'Query failed: ' . $sql . PHP_EOL);
        exit(1);
    }

    return (string) $stmt->fetchColumn();
}

$conn = new PDO('sqlite::memory:');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->exec("
    CREATE TABLE contracts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        access_token TEXT NULL
    );
    CREATE TABLE quotes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        access_token TEXT NULL
    );
    CREATE TABLE form_submissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        access_token TEXT NULL
    );
    CREATE TABLE bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ical_token TEXT NULL
    );
");
$conn->exec("
    INSERT INTO contracts (access_token) VALUES (NULL);
    INSERT INTO quotes (access_token) VALUES (NULL);
    INSERT INTO form_submissions (access_token) VALUES (NULL);
    INSERT INTO bookings (ical_token) VALUES (NULL);
");

$contract_path = bdta_get_public_contract_path($conn, 1);
$quote_path = bdta_get_public_quote_path($conn, 1);
$form_path = bdta_get_public_form_submission_path($conn, 1);
$booking_path = bdta_get_public_booking_ical_path($conn, 1);

assertTrueValue(str_starts_with($contract_path, '/backend/public/contract.php?token='), 'Contracts should use tokenized public paths.');
assertTrueValue(str_starts_with($quote_path, '/backend/public/quote.php?token='), 'Quotes should use tokenized public paths.');
assertTrueValue(str_starts_with($form_path, '/backend/public/form.php?token='), 'Form submissions should use tokenized public paths.');
assertTrueValue(str_starts_with($booking_path, '/backend/public/download_ical.php?token='), 'Booking iCal downloads should use tokenized public paths.');

$contract_token = fetchStringColumn($conn, 'SELECT access_token FROM contracts WHERE id = 1');
$quote_token = fetchStringColumn($conn, 'SELECT access_token FROM quotes WHERE id = 1');
$form_token = fetchStringColumn($conn, 'SELECT access_token FROM form_submissions WHERE id = 1');
$booking_token = fetchStringColumn($conn, 'SELECT ical_token FROM bookings WHERE id = 1');

assertTrueValue(bdta_public_access_token_has_expected_format($contract_token), 'Contract token should use the expected hex format.');
assertTrueValue(bdta_public_access_token_has_expected_format($quote_token), 'Quote token should use the expected hex format.');
assertTrueValue(bdta_public_access_token_has_expected_format($form_token), 'Form token should use the expected hex format.');
assertTrueValue(bdta_public_access_token_has_expected_format($booking_token), 'Booking token should use the expected hex format.');

assertTrueValue(
    bdta_contract_has_valid_access_token(['access_token' => $contract_token], $contract_token),
    'Contract token helper should accept matching tokens.'
);
assertTrueValue(
    bdta_quote_has_valid_access_token(['access_token' => $quote_token], $quote_token),
    'Quote token helper should accept matching tokens.'
);
assertTrueValue(
    bdta_form_submission_has_valid_access_token(['access_token' => $form_token], $form_token),
    'Form token helper should accept matching tokens.'
);
assertTrueValue(
    bdta_booking_has_valid_ical_token(['ical_token' => $booking_token], $booking_token),
    'Booking iCal token helper should accept matching tokens.'
);

assertSameValue(
    'reusing an existing quote token',
    $quote_token,
    bdta_ensure_quote_access_token($conn, 1, $quote_token)
);

echo "Public access link tests passed.\n";
