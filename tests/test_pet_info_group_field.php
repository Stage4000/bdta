#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/form_types.php';

if (!function_exists('array_string_value')) {
    /**
     * @param array<string, mixed> $array
     */
    function array_string_value(array $array, string $key, string $default = ''): string
    {
        $value = $array[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }
}

if (!function_exists('array_int_value')) {
    /**
     * @param array<string, mixed> $array
     */
    function array_int_value(array $array, string $key, int $default = 0): int
    {
        $value = $array[$key] ?? $default;
        return is_numeric($value) ? (int) $value : $default;
    }
}

if (!function_exists('assoc_row')) {
    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    function assoc_row(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}

if (!function_exists('safe_int')) {
    function safe_int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}

if (!function_exists('scalar_string')) {
    function scalar_string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}

function assertPetInfoGroup(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$field = [
    'type' => bdta_pet_info_group_field_type(),
    'label' => 'How many pets should we prepare for?',
    'pet_info_group_include_species' => 1,
    'pet_info_group_species_dog_only' => 1,
    'pet_info_group_max_pets' => 3,
];

assertPetInfoGroup(bdta_form_field_is_pet_info_group($field), 'Expected pet info group helper to recognize the new field type.');

$config = bdta_form_field_pet_info_group_config($field);
assertPetInfoGroup($config['include_species'] === true, 'Dog-only pet groups should still include a species value.');
assertPetInfoGroup($config['dog_only_species'] === true, 'Expected dog-only configuration to round-trip.');
assertPetInfoGroup($config['default_species'] === 'Dog', 'Dog-only configuration should default the species to Dog.');
assertPetInfoGroup($config['max_pets'] === 3, 'Expected pet info group max pet limits to round-trip.');

$spayNeuterOptions = bdta_form_field_pet_info_group_spay_neuter_options();
assertPetInfoGroup(($spayNeuterOptions['yes'] ?? '') === 'Yes, spayed/neutered', 'Expected the updated yes spay/neuter label.');
assertPetInfoGroup(($spayNeuterOptions['no'] ?? '') === 'No, intact', 'Expected the updated no spay/neuter label.');

$pets = bdta_form_field_pet_info_group_normalize_response($field, [
    [
        'name' => 'Comet',
        'age_or_dob' => '2 years 4 months',
        'breed' => 'Merle mixed breed',
        'vaccines_current' => 'yes',
        'spayed_neutered' => 'no',
        'source' => 'Rescue',
        'ownership_length' => '1 year',
    ],
]);
assertPetInfoGroup(count($pets) === 1, 'Expected pet info group responses to normalize into pet rows.');
assertPetInfoGroup($pets[0]['species'] === 'Dog', 'Dog-only pet info groups should inject Dog as the species.');

$profile_values = bdta_form_field_pet_info_group_profile_values($field, $pets);
assertPetInfoGroup(($profile_values[0]['age_years'] ?? null) === 2, 'Expected age text to map to pet profile age_years.');
assertPetInfoGroup(($profile_values[0]['age_months'] ?? null) === 4, 'Expected age text to map to pet profile age_months.');
assertPetInfoGroup(($profile_values[0]['ownership_length_years'] ?? null) === 1, 'Expected ownership text to map to ownership_length_years.');
assertPetInfoGroup(($profile_values[0]['vaccines_current'] ?? null) === 1, 'Expected vaccine status to map to the boolean pet profile field.');

$species_optional_field = [
    'type' => bdta_pet_info_group_field_type(),
    'label' => 'Tell us about your pets',
];
$species_optional_profile_values = bdta_form_field_pet_info_group_profile_values($species_optional_field, [
    [
        'name' => 'Pixel',
        'age_or_dob' => '1 year',
        'breed' => 'Mixed breed',
        'vaccines_current' => 'yes',
        'spayed_neutered' => 'yes',
        'source' => 'Breeder',
        'ownership_length' => '8 months',
    ],
]);
assertPetInfoGroup(!array_key_exists('species', $species_optional_profile_values[0] ?? []), 'Expected species to remain unset when the field configuration does not collect or default it.');

$limit_errors = bdta_form_field_pet_info_group_validate_response($field, [
    ['name' => 'One', 'age_or_dob' => '1 year', 'breed' => 'Mix', 'vaccines_current' => 'yes', 'spayed_neutered' => 'yes', 'source' => 'Rescue', 'ownership_length' => '1 year'],
    ['name' => 'Two', 'age_or_dob' => '2 years', 'breed' => 'Mix', 'vaccines_current' => 'yes', 'spayed_neutered' => 'yes', 'source' => 'Rescue', 'ownership_length' => '2 years'],
    ['name' => 'Three', 'age_or_dob' => '3 years', 'breed' => 'Mix', 'vaccines_current' => 'yes', 'spayed_neutered' => 'yes', 'source' => 'Rescue', 'ownership_length' => '3 years'],
    ['name' => 'Four', 'age_or_dob' => '4 years', 'breed' => 'Mix', 'vaccines_current' => 'yes', 'spayed_neutered' => 'yes', 'source' => 'Rescue', 'ownership_length' => '4 years'],
]);
assertPetInfoGroup(in_array('You can submit information for a maximum of 3 pets.', $limit_errors, true), 'Expected max pet validation errors when submissions exceed the configured pet limit.');

$edit_page = file_get_contents(dirname(__DIR__) . '/client/form_templates_edit.php');
if (!is_string($edit_page)) {
    throw new RuntimeException('Expected to read the form template editor.');
}
assertPetInfoGroup(str_contains($edit_page, 'Pet Info Group'), 'Expected the form template editor to expose the Pet Info Group field type.');
assertPetInfoGroup(str_contains($edit_page, 'Restrict species to Dog only'), 'Expected the form template editor to expose the species restriction setting.');
assertPetInfoGroup(str_contains($edit_page, 'Max Pets Allowed'), 'Expected the form template editor to expose max pet limits.');

$book_page = file_get_contents(dirname(__DIR__) . '/backend/public/book.php');
if (!is_string($book_page)) {
    throw new RuntimeException('Expected to read the public booking page.');
}
assertPetInfoGroup(str_contains($book_page, 'data-pet-info-config'), 'Expected the public booking flow to render pet info group configuration data.');
assertPetInfoGroup(str_contains($book_page, 'getPetInfoGroupPetNames'), 'Expected the public booking flow to derive legacy dog-name values from pet info group responses.');
assertPetInfoGroup(str_contains($book_page, 'data-existing-pets'), 'Expected the public booking flow to expose existing pet choices to pet info groups.');
assertPetInfoGroup(str_contains($book_page, 'Yes, spayed/neutered'), 'Expected the public booking flow to use the updated spay/neuter labels.');

$public_form_page = file_get_contents(dirname(__DIR__) . '/backend/public/form.php');
if (!is_string($public_form_page)) {
    throw new RuntimeException('Expected to read the public form submission page.');
}
assertPetInfoGroup(str_contains($public_form_page, 'data-pet-info-config'), 'Expected the public form submission page to render pet info group configuration data.');
assertPetInfoGroup(str_contains($public_form_page, 'public_form_sync_pet_info_group_profiles($conn, $client_id, $fields, $responses);'), 'Expected public form submissions to sync pet info group responses into pet profiles.');
assertPetInfoGroup(str_contains($public_form_page, 'Already a client with us?'), 'Expected the public form page to render the client login shortcut for pet info groups.');

$portal_page = file_get_contents(dirname(__DIR__) . '/portal/book_credit.php');
if (!is_string($portal_page)) {
    throw new RuntimeException('Expected to read the portal credit booking page.');
}
assertPetInfoGroup(str_contains($portal_page, 'data-pet-info-config'), 'Expected the portal booking flow to render pet info group configuration data.');
assertPetInfoGroup(str_contains($portal_page, 'Pets already on file'), 'Expected the portal booking flow to expose existing pet choices to pet info groups.');

$portal_api_page = file_get_contents(dirname(__DIR__) . '/portal/api_book_credit.php');
if (!is_string($portal_api_page)) {
    throw new RuntimeException('Expected to read the portal credit booking API.');
}
assertPetInfoGroup(str_contains($portal_api_page, 'bdta_form_field_pet_info_group_profile_values'), 'Expected the portal booking API to map pet info group responses into pet profile values.');

echo "Pet info group helper and UI regression test passed.\n";
