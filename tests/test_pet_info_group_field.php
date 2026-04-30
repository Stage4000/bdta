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
];

assertPetInfoGroup(bdta_form_field_is_pet_info_group($field), 'Expected pet info group helper to recognize the new field type.');

$config = bdta_form_field_pet_info_group_config($field);
assertPetInfoGroup($config['include_species'] === true, 'Dog-only pet groups should still include a species value.');
assertPetInfoGroup($config['dog_only_species'] === true, 'Expected dog-only configuration to round-trip.');
assertPetInfoGroup($config['default_species'] === 'Dog', 'Dog-only configuration should default the species to Dog.');

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

$edit_page = file_get_contents(dirname(__DIR__) . '/client/form_templates_edit.php');
if (!is_string($edit_page)) {
    throw new RuntimeException('Expected to read the form template editor.');
}
assertPetInfoGroup(str_contains($edit_page, 'Pet Info Group'), 'Expected the form template editor to expose the Pet Info Group field type.');
assertPetInfoGroup(str_contains($edit_page, 'Restrict species to Dog only'), 'Expected the form template editor to expose the species restriction setting.');

$book_page = file_get_contents(dirname(__DIR__) . '/backend/public/book.php');
if (!is_string($book_page)) {
    throw new RuntimeException('Expected to read the public booking page.');
}
assertPetInfoGroup(str_contains($book_page, 'data-pet-info-config'), 'Expected the public booking flow to render pet info group configuration data.');

$portal_page = file_get_contents(dirname(__DIR__) . '/portal/book_credit.php');
if (!is_string($portal_page)) {
    throw new RuntimeException('Expected to read the portal credit booking page.');
}
assertPetInfoGroup(str_contains($portal_page, 'data-pet-info-config'), 'Expected the portal booking flow to render pet info group configuration data.');

echo "Pet info group helper and UI regression test passed.\n";
