<?php
require_once __DIR__ . '/form_types.php';
require_once __DIR__ . '/form_link_requests.php';

function bdta_form_submission_requires_client_review(string $form_type): bool
{
    $canonical = bdta_form_type_meta_string(
        bdta_get_form_type_meta($form_type),
        'canonical',
        $form_type
    );

    return $canonical === 'follow_up_note';
}

/**
 * @param array<int, array<string, mixed>> $forms
 * @return array<int, array<string, mixed>>
 */
function bdta_index_follow_up_submissions_by_booking(array $forms): array
{
    $submissions = [];

    foreach ($forms as $form) {
        $booking_id = array_int_value($form, 'booking_id');
        if ($booking_id <= 0 || !bdta_form_submission_requires_client_review(array_string_value($form, 'form_type'))) {
            continue;
        }

        if (!isset($submissions[$booking_id]) || bdta_follow_up_submission_is_newer($form, $submissions[$booking_id])) {
            $submissions[$booking_id] = $form;
        }
    }

    return $submissions;
}

/**
 * @param array<string, mixed> $candidate
 * @param array<string, mixed> $current
 */
function bdta_follow_up_submission_is_newer(array $candidate, array $current): bool
{
    $candidate_submitted_at = array_string_value($candidate, 'submitted_at');
    $current_submitted_at = array_string_value($current, 'submitted_at');
    $candidate_timestamp = $candidate_submitted_at !== '' ? strtotime($candidate_submitted_at) : false;
    $current_timestamp = $current_submitted_at !== '' ? strtotime($current_submitted_at) : false;

    if ($candidate_timestamp !== false && $current_timestamp !== false && $candidate_timestamp !== $current_timestamp) {
        return $candidate_timestamp > $current_timestamp;
    }

    if ($candidate_timestamp !== false && $current_timestamp === false) {
        return true;
    }

    if ($candidate_timestamp === false && $current_timestamp !== false) {
        return false;
    }

    return array_int_value($candidate, 'id') > array_int_value($current, 'id');
}

function bdta_get_follow_up_review_url(int $submission_id): string
{
    return rtrim(getDynamicBaseUrl(), '/') . '/portal/form_submission_view.php?id=' . $submission_id;
}

/**
 * @param array<string, mixed> $submission
 */
function bdta_form_submission_is_client_portal_visible(array $submission): bool
{
    $form_type = array_string_value($submission, 'form_type');
    if (bdta_form_submission_requires_client_review($form_type)) {
        return true;
    }

    if (!array_key_exists('template_is_internal', $submission)) {
        return false;
    }

    return array_int_value($submission, 'template_is_internal') === 0
        && bdta_form_type_forced_internal($form_type) === 0;
}

/**
 * @param array<string, mixed> $submission
 */
function bdta_get_client_portal_form_submission_url(array $submission): string
{
    $submission_id = array_int_value($submission, 'id');

    if (bdta_form_submission_requires_client_review(array_string_value($submission, 'form_type'))) {
        return PORTAL_URL . 'form_submission_view.php?id=' . $submission_id;
    }

    return PORTAL_URL . 'form_view.php?id=' . $submission_id;
}

/**
 * @param array<int, array<string, mixed>> $fields
 * @param array<string, mixed> $responses
 * @return array{html: string, text: string}
 */
function bdta_get_follow_up_note_email_details(array $fields, array $responses): array
{
    if ($fields === []) {
        return ['html' => '', 'text' => ''];
    }

    $html_items = [];
    $text_sections = [];

    foreach ($fields as $index => $field) {
        $field_label = array_string_value($field, 'label', 'Field');
        $field_type = array_string_value($field, 'type', 'text');
        $response = $responses[(string) $index] ?? null;
        $response_text = is_array($response) ? '' : scalar_string($response);

        if ($field_type === 'checkbox' && is_array($response)) {
            if ($response !== []) {
                $html_values = [];
                $text_values = [];
                foreach ($response as $value) {
                    $string_value = scalar_string($value);
                    $html_values[] = '<li>' . escape($string_value) . '</li>';
                    $text_values[] = '- ' . $string_value;
                }

                $html_value = '<ul style="margin:8px 0 0 18px;padding:0;">' . implode('', $html_values) . '</ul>';
                $text_value = implode("\n", $text_values);
            } else {
                $html_value = '<span style="color:#6c757d;">None selected</span>';
                $text_value = 'None selected';
            }
        } elseif ($field_type === 'textarea') {
            if ($response_text !== '') {
                $html_value = nl2br(escape($response_text));
                $text_value = $response_text;
            } else {
                $html_value = '<span style="color:#6c757d;">No response</span>';
                $text_value = 'No response';
            }
        } elseif ($response_text !== '') {
            $html_value = escape($response_text);
            $text_value = $response_text;
        } else {
            $html_value = '<span style="color:#6c757d;">No response</span>';
            $text_value = 'No response';
        }

        $html_items[] = '<div style="margin:0 0 16px;">'
            . '<div style="font-weight:600;color:#6c757d;margin-bottom:4px;">' . escape($field_label) . '</div>'
            . '<div style="border-left:3px solid #0d6efd;padding-left:12px;">' . $html_value . '</div>'
            . '</div>';
        $text_sections[] = $field_label . ":\n" . $text_value;
    }

    return [
        'html' => implode('', $html_items),
        'text' => implode("\n\n", $text_sections),
    ];
}

/**
 * @return array{success: bool, message: string, email_id?: int}
 */
function bdta_notify_follow_up_note_completed(PDO $conn, int $submission_id): array
{
    $stmt = $conn->prepare("
        SELECT fs.id, fs.client_id, fs.submitted_at, fs.responses,
               c.name AS client_name, c.email AS client_email,
               ft.name AS form_name, ft.form_type, ft.fields,
               b.service_type, b.appointment_date, b.appointment_time
        FROM form_submissions fs
        JOIN clients c ON fs.client_id = c.id
        JOIN form_templates ft ON fs.template_id = ft.id
        LEFT JOIN bookings b ON fs.booking_id = b.id
        WHERE fs.id = ?
        LIMIT 1
    ");
    $stmt->execute([$submission_id]);
    $submission = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));

    if ($submission === []) {
        return [
            'success' => false,
            'message' => 'Follow-up note submission not found.',
        ];
    }

    if (!bdta_form_submission_requires_client_review(array_string_value($submission, 'form_type'))) {
        return [
            'success' => false,
            'message' => 'This form type does not notify clients for review.',
        ];
    }

    if (array_string_value($submission, 'client_email') === '') {
        return [
            'success' => false,
            'message' => 'Client email address is not available.',
        ];
    }

    $review_url = bdta_get_follow_up_review_url($submission_id);
    $client_name_raw = array_string_value($submission, 'client_name');
    $form_name_raw = array_string_value($submission, 'form_name');
    $fields = decode_json_assoc_list(array_string_value($submission, 'fields'));
    $responses = decode_json_assoc(array_string_value($submission, 'responses'));
    $details = bdta_get_follow_up_note_email_details($fields, $responses);
    $appointment_summary = trim(
        array_string_value($submission, 'service_type') . ' ' .
        array_string_value($submission, 'appointment_date') . ' ' .
        array_string_value($submission, 'appointment_time')
    );

    $subject = 'Your follow-up note is ready to review';
    $html_body = '<p>Hello ' . escape($client_name_raw) . ',</p>'
        . '<p>Your <strong>' . escape($form_name_raw) . '</strong> has been completed by our team.</p>'
        . ($appointment_summary !== ''
            ? '<p>Appointment: <strong>' . escape($appointment_summary) . '</strong></p>'
            : '')
        . ($details['html'] !== ''
            ? '<div style="margin:24px 0;"><p><strong>Follow-up details</strong></p>' . $details['html'] . '</div>'
            : '')
        . '<p>Please review it here: <a href="' . escape($review_url) . '">Review Follow-up Note</a></p>';
    $text_body = "Hello " . $client_name_raw . ",\n\n"
        . "Your " . $form_name_raw . " has been completed by our team.\n"
        . ($appointment_summary !== '' ? "Appointment: " . $appointment_summary . "\n" : '')
        . ($details['text'] !== '' ? "\nFollow-up details:\n" . $details['text'] . "\n" : '')
        . "Please review it here:\n" . $review_url;

    return bdta_send_client_form_link_email(
        $conn,
        array_int_value($submission, 'client_id'),
        $subject,
        $html_body,
        $text_body
    );
}
