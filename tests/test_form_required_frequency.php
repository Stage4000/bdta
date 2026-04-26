#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

if (!function_exists('array_int_value')) {
    /**
     * @param array<string|int, mixed> $array
     */
    function array_int_value(array $array, string|int $key, int $default = 0): int
    {
        return array_key_exists($key, $array) ? safe_int($array[$key]) : $default;
    }
}

if (!function_exists('array_string_value')) {
    /**
     * @param array<string|int, mixed> $array
     */
    function array_string_value(array $array, string|int $key, string $default = ''): string
    {
        if (!array_key_exists($key, $array)) {
            return $default;
        }

        $value = $array[$key];
        if ($value === null) {
            return $default;
        }

        return is_scalar($value) ? (string) $value : $default;
    }
}

require_once dirname(__DIR__) . '/backend/includes/form_types.php';

function assertFormFrequency(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$conn = new SafePDO('sqlite::memory:');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);

$conn->exec('CREATE TABLE form_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, appointment_type_id INTEGER, required_frequency TEXT)');
$conn->exec('CREATE TABLE bookings (id INTEGER PRIMARY KEY AUTOINCREMENT, appointment_type_id INTEGER)');
$conn->exec('CREATE TABLE form_submissions (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, template_id INTEGER, booking_id INTEGER, pet_id INTEGER, status TEXT, submitted_at TEXT)');

$conn->prepare('INSERT INTO form_templates (appointment_type_id, required_frequency) VALUES (?, ?)')
    ->execute([10, 'once_per_pet']);
$once_per_pet_template_id = (int) $conn->lastInsertId();

$conn->prepare('INSERT INTO form_templates (appointment_type_id, required_frequency) VALUES (?, ?)')
    ->execute([10, 'per_appointment']);
$per_appointment_template_id = (int) $conn->lastInsertId();

$conn->prepare('INSERT INTO bookings (appointment_type_id) VALUES (?)')->execute([10]);
$matching_booking_id = (int) $conn->lastInsertId();
$conn->prepare('INSERT INTO bookings (appointment_type_id) VALUES (?)')->execute([20]);
$other_booking_id = (int) $conn->lastInsertId();

$submitted_at = date('Y-m-d H:i:s');
$conn->prepare('INSERT INTO form_submissions (client_id, template_id, booking_id, pet_id, status, submitted_at) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([1, $once_per_pet_template_id, $matching_booking_id, 101, 'submitted', $submitted_at]);
$conn->prepare('INSERT INTO form_submissions (client_id, template_id, booking_id, pet_id, status, submitted_at) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([1, $per_appointment_template_id, $matching_booking_id, null, 'submitted', $submitted_at]);
$conn->prepare('INSERT INTO form_submissions (client_id, template_id, booking_id, pet_id, status, submitted_at) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([1, $per_appointment_template_id, $other_booking_id, null, 'submitted', $submitted_at]);

assertFormFrequency(
    bdta_get_form_required_frequency_label('once_per_pet') === 'Once per pet',
    'Expected once-per-pet labels to render clearly.'
);
assertFormFrequency(
    bdta_normalize_form_required_frequency('annual') === 'yearly',
    'Expected annual aliases to normalize to yearly.'
);

assertFormFrequency(
    bdta_form_template_needs_completion(
        $conn,
        ['id' => $per_appointment_template_id, 'required_frequency' => 'per_appointment'],
        1,
        10
    ) === false,
    'Expected per-appointment forms to be skipped when the same appointment type is already on file.'
);
assertFormFrequency(
    bdta_form_template_needs_completion(
        $conn,
        ['id' => $per_appointment_template_id, 'required_frequency' => 'per_appointment'],
        1,
        30
    ) === true,
    'Expected per-appointment forms to be required for a different appointment type.'
);

assertFormFrequency(
    bdta_form_template_needs_completion(
        $conn,
        ['id' => $once_per_pet_template_id, 'required_frequency' => 'once_per_pet'],
        1,
        10,
        [101]
    ) === false,
    'Expected once-per-pet forms to be skipped for the same pet and appointment type.'
);
assertFormFrequency(
    bdta_form_template_needs_completion(
        $conn,
        ['id' => $once_per_pet_template_id, 'required_frequency' => 'once_per_pet'],
        1,
        10,
        [202]
    ) === true,
    'Expected once-per-pet forms to be required for a different pet.'
);
assertFormFrequency(
    bdta_form_template_needs_completion(
        $conn,
        ['id' => $once_per_pet_template_id, 'required_frequency' => 'once_per_pet'],
        1,
        10,
        [101, 202]
    ) === true,
    'Expected once-per-pet forms to stay required until every selected pet has a submission.'
);
assertFormFrequency(
    bdta_form_template_needs_completion(
        $conn,
        ['id' => $once_per_pet_template_id, 'required_frequency' => 'once_per_pet'],
        1,
        10,
        []
    ) === true,
    'Expected once-per-pet forms to remain required until a pet is selected.'
);

$completed_pet_ids = bdta_get_form_template_completed_pet_ids($conn, 1, $once_per_pet_template_id, 10);
assertFormFrequency(
    $completed_pet_ids === [101],
    'Expected once-per-pet helper queries to return only the completed pets for the current appointment type.'
);

$legacy_conn = new SafePDO('sqlite::memory:');
$legacy_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacy_conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);

$legacy_conn->exec('CREATE TABLE form_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, appointment_type_id INTEGER, required_frequency TEXT)');
$legacy_conn->exec('CREATE TABLE bookings (id INTEGER PRIMARY KEY AUTOINCREMENT, appointment_type_id INTEGER)');
$legacy_conn->exec('CREATE TABLE form_submissions (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, template_id INTEGER, booking_id INTEGER, status TEXT, submitted_at TEXT)');

$legacy_conn->prepare('INSERT INTO form_templates (appointment_type_id, required_frequency) VALUES (?, ?)')
    ->execute([10, 'once_per_pet']);
$legacy_template_id = (int) $legacy_conn->lastInsertId();
$legacy_conn->prepare('INSERT INTO bookings (appointment_type_id) VALUES (?)')->execute([10]);
$legacy_booking_id = (int) $legacy_conn->lastInsertId();
$legacy_conn->prepare('INSERT INTO form_submissions (client_id, template_id, booking_id, status, submitted_at) VALUES (?, ?, ?, ?, ?)')
    ->execute([1, $legacy_template_id, $legacy_booking_id, 'submitted', $submitted_at]);

assertFormFrequency(
    bdta_form_submissions_support_pet_id($legacy_conn) === false,
    'Expected legacy schemas without pet_id support to be detected explicitly.'
);
assertFormFrequency(
    bdta_form_template_needs_completion(
        $legacy_conn,
        ['id' => $legacy_template_id, 'required_frequency' => 'once_per_pet'],
        1,
        10,
        [101]
    ) === true,
    'Expected once-per-pet forms to stay required when the legacy schema cannot track pet-specific submissions.'
);
assertFormFrequency(
    bdta_get_form_template_completed_pet_ids($legacy_conn, 1, $legacy_template_id, 10) === [],
    'Expected legacy schemas without pet_id support to report no completed once-per-pet submissions.'
);

echo "Form required frequency helper test passed.\n";
