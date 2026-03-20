#!/usr/bin/env php
<?php

/**
 * @return list<string>
 */
function bdta_path_segments(string $path): array
{
    $normalized_path = str_replace('\\', '/', $path);
    $trimmed_path = trim($normalized_path, '/');
    $segments = explode('/', $trimmed_path);
    $non_empty_segments = array_filter($segments, static fn (string $segment): bool => $segment !== '');

    return array_values($non_empty_segments);
}

function bdta_relative_path(string $from_directory, string $to_directory): string
{
    $from_segments = bdta_path_segments($from_directory);
    $to_segments = bdta_path_segments($to_directory);

    while ($from_segments !== [] && $to_segments !== [] && $from_segments[0] === $to_segments[0]) {
        array_shift($from_segments);
        array_shift($to_segments);
    }

    return str_repeat('../', count($from_segments)) . implode('/', $to_segments);
}

$backend_directory = realpath(__DIR__ . '/backend');
$temporary_directory = realpath(sys_get_temp_dir());

if ($backend_directory === false || $temporary_directory === false) {
    throw new RuntimeException('Unable to resolve filesystem paths for survey test database.');
}

$sqlite_db_path = bdta_relative_path($backend_directory, $temporary_directory)
    . '/test_survey_results_' . bin2hex(random_bytes(4)) . '.sqlite';

// Keep the temporary SQLite file under the system temp directory and let the
// environment clean it up instead of deleting a computed path in the test.
putenv('DB_TYPE=sqlite');
putenv('SQLITE_DB_PATH=' . $sqlite_db_path);

require_once __DIR__ . '/backend/includes/config.php';
require_once __DIR__ . '/backend/includes/survey_results.php';

echo "=== Survey Results Tests ===\n\n";

try {
    $fields = [
        [
            'label' => 'Overall satisfaction',
            'type' => 'radio',
            'options' => ['Great', 'Okay', 'Poor'],
        ],
        [
            'label' => 'What did you need help with?',
            'type' => 'checkbox',
            'options' => ['Recall', 'Leash Walking', 'Greetings'],
        ],
        [
            'label' => 'Additional comments',
            'type' => 'textarea',
        ],
    ];

    $submissions = [
        [
            'client_name' => 'Alex',
            'submitted_at' => '2026-03-20 10:00:00',
            'responses' => json_encode([
                '0' => 'Great',
                '1' => ['Recall', 'Greetings'],
                '2' => "Loved the session.\nVery helpful.",
            ]),
        ],
        [
            'client_name' => 'Jamie',
            'submitted_at' => '2026-03-19 09:30:00',
            'responses' => json_encode([
                '0' => 'Okay',
                '1' => ['Leash Walking'],
                '2' => '',
            ]),
        ],
        [
            'client_name' => '',
            'submitted_at' => '2026-03-18 15:45:00',
            'responses' => json_encode([
                '0' => 'Great',
                '1' => ['Recall'],
                '2' => 'Would book again.',
            ]),
        ],
    ];

    $results = bdta_build_survey_results($fields, $submissions);

    if ($results['total_submissions'] !== 3) {
        throw new RuntimeException('Expected total submissions to equal 3.');
    }

    if ($results['visualized_field_count'] !== 2) {
        throw new RuntimeException('Expected two visualized survey fields.');
    }

    $rating_field = $results['fields'][0] ?? [];
    $rating_options = is_array($rating_field['options'] ?? null) ? $rating_field['options'] : [];
    if (array_int_value($rating_options[0] ?? [], 'count') !== 2 || array_int_value($rating_options[0] ?? [], 'percentage') !== 67) {
        throw new RuntimeException('Expected "Great" answers to be counted and rounded to 67%.');
    }
    if (array_int_value($rating_options[1] ?? [], 'count') !== 1) {
        throw new RuntimeException('Expected "Okay" answers to be counted.');
    }

    $topics_field = $results['fields'][1] ?? [];
    $topic_options = is_array($topics_field['options'] ?? null) ? $topics_field['options'] : [];
    if (array_int_value($topic_options[0] ?? [], 'count') !== 2) {
        throw new RuntimeException('Expected Recall checkbox totals to aggregate across submissions.');
    }
    if (array_int_value($topic_options[1] ?? [], 'count') !== 1 || array_int_value($topic_options[2] ?? [], 'count') !== 1) {
        throw new RuntimeException('Expected each checkbox choice to keep its own aggregated count.');
    }

    $comments_field = $results['fields'][2] ?? [];
    if (!empty($comments_field['supports_visualization'])) {
        throw new RuntimeException('Textarea fields should not be treated as chartable survey questions.');
    }
    if (array_int_value($comments_field, 'response_count') !== 2) {
        throw new RuntimeException('Expected non-empty text responses to be counted.');
    }

    $recent_responses = is_array($comments_field['recent_responses'] ?? null) ? $comments_field['recent_responses'] : [];
    if (count($recent_responses) !== 2) {
        throw new RuntimeException('Expected recent open-ended responses to be retained.');
    }
    if (array_string_value($recent_responses[0] ?? [], 'client_name') !== 'Alex') {
        throw new RuntimeException('Expected recent responses to preserve submission order.');
    }
    if (array_string_value($recent_responses[1] ?? [], 'client_name', 'Unknown client') !== 'Unknown client') {
        throw new RuntimeException('Expected missing client names to fall back to "Unknown client".');
    }

    echo "✓ Survey answer choices aggregate into visualization data\n";
    echo "✓ Open-ended survey answers remain visible in recent responses\n\n";
    echo "=== Survey Results Tests Passed! ===\n";
} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
