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
 * @return array{success: bool, message: string, email_id?: int}
 */
function bdta_notify_follow_up_note_completed(PDO $conn, int $submission_id): array
{
    $stmt = $conn->prepare("
        SELECT fs.id, fs.client_id, fs.submitted_at,
               c.name AS client_name, c.email AS client_email,
               ft.name AS form_name, ft.form_type,
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
        . '<p>Please review it here: <a href="' . escape($review_url) . '">Review Follow-up Note</a></p>';
    $text_body = "Hello " . $client_name_raw . ",\n\n"
        . "Your " . $form_name_raw . " has been completed by our team.\n"
        . ($appointment_summary !== '' ? "Appointment: " . $appointment_summary . "\n" : '')
        . "Please review it here:\n" . $review_url;

    return bdta_send_client_form_link_email(
        $conn,
        array_int_value($submission, 'client_id'),
        $subject,
        $html_body,
        $text_body
    );
}
