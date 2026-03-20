<?php

/**
 * Survey reporting helpers for aggregating stored form submissions into
 * visualization-friendly summaries.
 */

/**
 * @param array<string, mixed> $field
 */
function bdta_survey_field_supports_visualization(array $field): bool
{
    return in_array(array_string_value($field, 'type'), ['select', 'radio', 'checkbox'], true);
}

/**
 * @param array<string, mixed> $field
 * @return list<string>
 */
function bdta_survey_field_options(array $field): array
{
    return string_list($field['options'] ?? []);
}

/**
 * @param array<string, mixed> $submission
 * @return array<string, mixed>
 */
function bdta_survey_submission_responses(array $submission): array
{
    $responses = $submission['responses'] ?? [];
    if (is_array($responses)) {
        return assoc_row($responses);
    }

    return decode_json_assoc($responses);
}

/**
 * @param array<string, mixed> $submission
 */
function bdta_survey_submission_client_name(array $submission): string
{
    return trim(array_string_value($submission, 'client_name'));
}

/**
 * @param list<array<string, mixed>> $submissions
 * @return list<array<string, mixed>>
 */
function bdta_prepare_survey_submissions(array $submissions): array
{
    $prepared_submissions = [];

    foreach ($submissions as $submission) {
        $prepared_submission = $submission;
        $prepared_submission['decoded_responses'] = bdta_survey_submission_responses($submission);

        $client_name = bdta_survey_submission_client_name($submission);
        if ($client_name !== '') {
            $prepared_submission['client_name'] = $client_name;
        } else {
            unset($prepared_submission['client_name']);
        }

        $prepared_submissions[] = $prepared_submission;
    }

    return $prepared_submissions;
}

/**
 * @param list<array<string, mixed>> $fields
 * @param list<array<string, mixed>> $submissions
 * @return array{
 *   total_submissions: int,
 *   visualized_field_count: int,
 *   fields: list<array<string, mixed>>
 * }
 */
function bdta_build_survey_results(array $fields, array $submissions): array
{
    $field_summaries = [];
    $visualized_field_count = 0;
    $total_submissions = count($submissions);
    $prepared_submissions = bdta_prepare_survey_submissions($submissions);

    foreach ($fields as $index => $field) {
        $type = array_string_value($field, 'type');
        $supports_visualization = bdta_survey_field_supports_visualization($field);
        $configured_options = bdta_survey_field_options($field);
        $counts = [];

        foreach ($configured_options as $option_label) {
            $counts[$option_label] = 0;
        }

        $response_count = 0;
        $recent_responses = [];

        foreach ($prepared_submissions as $submission) {
            $responses = assoc_row($submission['decoded_responses'] ?? []);
            $response = $responses[(string) $index] ?? null;

            if ($supports_visualization) {
                $selected_values = is_array($response) ? string_list($response) : [];
                if (!is_array($response)) {
                    $single_value = trim(scalar_string($response));
                    if ($single_value !== '') {
                        $selected_values = [$single_value];
                    }
                }

                if ($selected_values === []) {
                    continue;
                }

                $response_count++;
                foreach ($selected_values as $selected_value) {
                    if (!array_key_exists($selected_value, $counts)) {
                        $counts[$selected_value] = 0;
                    }
                    $counts[$selected_value]++;
                }
                continue;
            }

            $response_text = trim(scalar_string($response));
            if ($response_text === '') {
                continue;
            }

            $response_count++;
            if (count($recent_responses) < 5) {
                $recent_response = [
                    'value' => $response_text,
                    'submitted_at' => array_string_value($submission, 'submitted_at'),
                ];

                $client_name = bdta_survey_submission_client_name($submission);
                if ($client_name !== '') {
                    $recent_response['client_name'] = $client_name;
                }

                $recent_responses[] = $recent_response;
            }
        }

        $options = [];
        foreach ($counts as $option_label => $count) {
            $percentage = $total_submissions > 0 ? (int) round(($count / $total_submissions) * 100) : 0;
            $options[] = [
                'label' => $option_label,
                'count' => $count,
                'percentage' => $percentage,
            ];
        }

        if ($supports_visualization) {
            $visualized_field_count++;
        }

        $field_summaries[] = [
            'index' => (string) $index,
            'label' => array_string_value($field, 'label', 'Question ' . ((int) $index + 1)),
            'type' => $type,
            'supports_visualization' => $supports_visualization,
            'response_count' => $response_count,
            'options' => $options,
            'recent_responses' => $recent_responses,
        ];
    }

    return [
        'total_submissions' => $total_submissions,
        'visualized_field_count' => $visualized_field_count,
        'fields' => $field_summaries,
    ];
}
