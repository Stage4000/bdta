#!/usr/bin/env php
<?php
require_once __DIR__ . '/support/survey_test_bootstrap.php';
require_once dirname(__DIR__) . '/backend/includes/survey_results.php';

/** @var list<string> $failures */
$failures = [];
function checkSurveyIntegrity(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
        echo "FAIL: {$message}\n";
    } else {
        echo "PASS: {$message}\n";
    }
}

$fields = [
    ['label' => 'Rating', 'type' => 'radio', 'options' => ['Good', 'Poor']],
    ['label' => 'Topics', 'type' => 'checkbox', 'options' => ['Recall', 'Leash']],
    ['label' => 'Comments', 'type' => 'textarea'],
];
$submissions = [
    ['id' => 1, 'client_id' => 21, 'status' => 'submitted', 'responses' => '{"0":"Good","1":["Recall","Recall",""," "],"2":"  Original answer\\n  "}'],
    ['id' => 2, 'client_id' => 22, 'status' => 'reviewed', 'responses' => '{"0":"Poor","1":["Leash"]}'],
    ['id' => 3, 'client_id' => 23, 'status' => 'pending', 'responses' => '{}'],
    ['id' => 4, 'client_id' => 24, 'status' => 'draft', 'responses' => '{"0":"Poor"}'],
];
$original = $submissions;
$results = bdta_build_survey_results($fields, $submissions);
checkSurveyIntegrity($results['total_submissions'] === 2, 'Only submitted and reviewed responses count as completed surveys.');
$rating = assoc_row($results['fields'][0] ?? []);
$rating_options = assoc_rows($rating['options'] ?? []);
checkSurveyIntegrity(array_int_value($rating_options[0] ?? [], 'percentage') === 50, 'Pending requests do not dilute answer percentages.');
$topics = assoc_row($results['fields'][1] ?? []);
$topic_options = assoc_rows($topics['options'] ?? []);
checkSurveyIntegrity(count($topic_options) === 2, 'Blank checkbox values do not become chart categories.');
checkSurveyIntegrity(array_int_value($topic_options[0] ?? [], 'count') === 1, 'Each checkbox choice counts at most once per response.');
checkSurveyIntegrity(json_encode($submissions) === json_encode($original), 'Analytics leaves original answers and client associations untouched.');
$all_pending = bdta_build_survey_results($fields, [$submissions[2]]);
checkSurveyIntegrity($all_pending['total_submissions'] === 0, 'Pending-only surveys correctly show zero completed responses.');

$page = file_get_contents(dirname(__DIR__) . '/client/form_survey_results.php');
checkSurveyIntegrity(is_string($page) && str_contains($page, "fs.status IN ('submitted', 'reviewed')"), 'Dashboard filters incomplete requests before calculating latest response and totals.');

if ($failures !== []) {
    exit(1);
}
echo "Survey response integrity tests passed.\n";
