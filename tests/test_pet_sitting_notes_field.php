#!/usr/bin/env php
<?php

function assertPetSittingNotesField(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function readPetSittingNotesFixture(string $relative_path): string
{
    $contents = file_get_contents(dirname(__DIR__) . '/' . $relative_path);
    if (!is_string($contents)) {
        throw new RuntimeException('Expected to read ' . $relative_path . '.');
    }

    return $contents;
}

$pets_edit = readPetSittingNotesFixture('client/pets_edit.php');
assertPetSittingNotesField(
    str_contains($pets_edit, '$pet_sitting_notes = trim(scalar_string($_POST[\'pet_sitting_notes\'] ?? \'\'));'),
    'Expected pet edit submissions to read the pet sitting notes field.'
);
assertPetSittingNotesField(
    str_contains($pets_edit, 'pet_sitting_notes = ?,'),
    'Expected pet updates to persist pet sitting notes.'
);
assertPetSittingNotesField(
    str_contains($pets_edit, 'pet_sitting_notes'),
    'Expected the pet edit page to reference the pet sitting notes column.'
);
assertPetSittingNotesField(
    str_contains($pets_edit, '<label for="pet_sitting_notes" class="form-label">Pet Sitting Notes</label>'),
    'Expected the pet edit form to render a Pet Sitting Notes textarea.'
);

$database = readPetSittingNotesFixture('backend/includes/database.php');
assertPetSittingNotesField(
    str_contains($database, 'pet_sitting_notes TEXT,'),
    'Expected the pets table schema to define the pet_sitting_notes column.'
);
assertPetSittingNotesField(
    str_contains($database, 'ALTER TABLE pets ADD COLUMN pet_sitting_notes TEXT'),
    'Expected existing pets tables to gain the pet_sitting_notes column through migrations.'
);

$form_templates_edit = readPetSittingNotesFixture('client/form_templates_edit.php');
assertPetSittingNotesField(
    str_contains($form_templates_edit, 'pet_1.pet_sitting_notes'),
    'Expected form template profile mappings to allow pet sitting notes.'
);

$api_bookings = readPetSittingNotesFixture('backend/public/api_bookings.php');
assertPetSittingNotesField(
    str_contains($api_bookings, "'pet_sitting_notes' => 'pet_sitting_notes'"),
    'Expected booking profile mapping imports to support pet sitting notes.'
);

echo "Pet sitting notes field regression checks passed.\n";
