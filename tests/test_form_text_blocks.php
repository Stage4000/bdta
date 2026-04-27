#!/usr/bin/env php
<?php

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

require_once dirname(__DIR__) . '/backend/includes/form_types.php';

function assertFormTextBlocks(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function readFormTextBlockFixture(string $relative_path): string
{
    $contents = file_get_contents(dirname(__DIR__) . '/' . $relative_path);
    if (!is_string($contents)) {
        throw new RuntimeException('Expected to read ' . $relative_path . '.');
    }

    return $contents;
}

assertFormTextBlocks(
    bdta_form_field_is_display_only(['type' => 'text_block']) === true,
    'Expected text_block fields to be treated as display-only.'
);
assertFormTextBlocks(
    bdta_form_field_is_display_only(['type' => 'textarea']) === false,
    'Expected normal input fields to remain interactive.'
);
assertFormTextBlocks(
    bdta_form_field_text_block_body(['label' => 'Important', 'description' => "Line 1\nLine 2"]) === "Line 1\nLine 2",
    'Expected text block body helper to prefer the description content.'
);
assertFormTextBlocks(
    bdta_form_field_text_block_body(['label' => 'Important notice', 'description' => '']) === 'Important notice',
    'Expected text block body helper to fall back to the label when no description is stored.'
);

$package_checkout_helper = readFormTextBlockFixture('backend/includes/package_checkout.php');
assertFormTextBlocks(
    str_contains($package_checkout_helper, 'if (bdta_form_field_is_display_only($field)) {'),
    'Expected package checkout validation to skip display-only text blocks.'
);

$form_template_editor = readFormTextBlockFixture('client/form_templates_edit.php');
assertFormTextBlocks(
    str_contains($form_template_editor, '<option value="text_block"'),
    'Expected the form template editor to offer a Text Block field type.'
);
assertFormTextBlocks(
    str_contains($form_template_editor, 'shown to clients in the text block'),
    'Expected the form template editor to explain text block content in the description area.'
);

$public_form_page = readFormTextBlockFixture('backend/public/form.php');
assertFormTextBlocks(
    str_contains($public_form_page, 'bdta_form_field_is_display_only($field)'),
    'Expected the public form renderer to detect display-only text blocks.'
);
assertFormTextBlocks(
    str_contains($public_form_page, 'bdta_form_field_text_block_body($field)'),
    'Expected the public form renderer to output text block body content.'
);

$survey_results_helper = readFormTextBlockFixture('backend/includes/survey_results.php');
assertFormTextBlocks(
    str_contains($survey_results_helper, 'if (bdta_form_field_is_display_only($field)) {'),
    'Expected survey result summaries to skip display-only text blocks.'
);

echo "Form text block regression checks passed.\n";
