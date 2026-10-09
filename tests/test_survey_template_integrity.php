#!/usr/bin/env php
<?php
require_once __DIR__ . '/support/survey_test_bootstrap.php';
$helper_path = dirname(__DIR__) . '/backend/includes/survey_template_integrity.php';
if (!is_file($helper_path)) {
    fwrite(STDERR, "FAIL: Survey template history protection is missing.\n");
    exit(1);
}
require_once $helper_path;

function assertSurveyTemplateIntegrity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: {$message}\n";
}

$fields = [
    ['label' => 'Rating', 'type' => 'radio', 'options' => ['Good', 'Poor']],
    ['label' => 'Comment', 'type' => 'textarea'],
];
$template = ['form_type' => 'survey_form', 'fields' => json_encode($fields)];
$original_template = $template;
assertSurveyTemplateIntegrity(!bdta_survey_template_structure_is_locked($template, false), 'Unused surveys remain fully editable.');
assertSurveyTemplateIntegrity(bdta_survey_template_structure_is_locked($template, true), 'A pending request or completed response locks its survey questions.');
bdta_validate_survey_template_edit($template, 'survey_form', array_reverse($fields), false);
assertSurveyTemplateIntegrity(true, 'Unused surveys may reorder questions.');
bdta_validate_survey_template_edit($template, 'survey_form', $fields, true);
assertSurveyTemplateIntegrity(true, 'Used surveys may save metadata without changing questions.');

$cases = [
    ['reorder questions', 'survey_form', array_reverse($fields)],
    ['delete questions', 'survey_form', [$fields[0]]],
    ['append questions', 'survey_form', array_merge($fields, [['label' => 'New', 'type' => 'text']])],
    ['change form type', 'client_form', $fields],
    ['relabel a question', 'survey_form', [array_merge($fields[0], ['label' => 'Different question']), $fields[1]]],
    ['change choice meanings', 'survey_form', [array_merge($fields[0], ['options' => ['Yes', 'No']]), $fields[1]]],
    ['change answer type', 'survey_form', [array_merge($fields[0], ['type' => 'checkbox']), $fields[1]]],
];
foreach ($cases as [$description, $type, $proposed_fields]) {
    $rejected = false;
    try {
        bdta_validate_survey_template_edit($template, $type, $proposed_fields, true);
    } catch (RuntimeException $e) {
        $rejected = str_contains($e->getMessage(), 'Duplicate');
    }
    assertSurveyTemplateIntegrity($rejected, 'Used survey cannot ' . $description . '; Duplicate guidance is returned.');
}
assertSurveyTemplateIntegrity($template === $original_template, 'Validation preserves original template definitions.');
$ordinary_template = ['form_type' => 'client_form', 'fields' => json_encode($fields)];
bdta_validate_survey_template_edit($ordinary_template, 'client_form', [], true);
assertSurveyTemplateIntegrity(true, 'Unrelated existing client-form editing is unchanged.');
$rejected = false;
try {
    bdta_validate_survey_template_edit($ordinary_template, 'survey_form', $fields, true);
} catch (RuntimeException $e) {
    $rejected = true;
}
assertSurveyTemplateIntegrity($rejected, 'Existing response history cannot be reclassified as a survey.');

$edit = file_get_contents(dirname(__DIR__) . '/client/form_templates_edit.php');
assertSurveyTemplateIntegrity(is_string($edit) && str_contains($edit, 'bdta_validate_survey_template_edit('), 'Editor enforces history protection server-side.');
assertSurveyTemplateIntegrity(is_string($edit) && str_contains($edit, 'FOR UPDATE') && str_contains($edit, 'beginTransaction()'), 'Editor rechecks current template and history inside a transaction.');
$duplicate = file_get_contents(dirname(__DIR__) . '/backend/includes/template_duplication.php');
assertSurveyTemplateIntegrity(is_string($duplicate) && !str_contains($duplicate, 'INSERT INTO form_submissions'), 'Template duplication does not duplicate or reassign individual answers.');
echo "Survey template integrity tests passed.\n";
