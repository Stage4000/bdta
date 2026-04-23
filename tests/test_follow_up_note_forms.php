<?php

require_once dirname(__DIR__) . '/backend/includes/config.php';
require_once dirname(__DIR__) . '/backend/includes/database.php';
require_once dirname(__DIR__) . '/backend/includes/form_link_requests.php';
require_once dirname(__DIR__) . '/backend/includes/follow_up_notes.php';
require_once dirname(__DIR__) . '/backend/includes/settings.php';

$db = new Database();
$conn = $db->getConnection();

function assertFollowUpNoteTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$cleanup_submission_ids = [];
$cleanup_template_ids = [];
$cleanup_booking_id = 0;
$cleanup_appointment_type_id = 0;
$cleanup_client_id = 0;

try {
    Settings::set('email_service', 'smtp');
    Settings::set('smtp_host', '');
    Settings::set('smtp_port', '587');
    Settings::set('smtp_encryption', 'tls');
    Settings::set('smtp_username', '');
    Settings::set('smtp_password', '');

    $suffix = uniqid('follow-up-', true);
    $conn->prepare("INSERT INTO clients (name, email, created_at, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")
        ->execute(['Follow Up Client ' . $suffix, $suffix . '@example.com']);
    $client_id = (int) $conn->lastInsertId();
    $cleanup_client_id = $client_id;

    $conn->prepare("INSERT INTO appointment_types (name, duration_minutes, is_active) VALUES (?, 60, 1)")
        ->execute(['Follow Up Session ' . $suffix]);
    $appointment_type_id = (int) $conn->lastInsertId();
    $cleanup_appointment_type_id = $appointment_type_id;

    $conn->prepare("
        INSERT INTO bookings (
            client_id, appointment_type_id, client_name, client_email,
            service_type, appointment_date, appointment_time, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'completed')
    ")->execute([
        $client_id,
        $appointment_type_id,
        'Follow Up Client ' . $suffix,
        $suffix . '@example.com',
        'Private Lesson',
        date('Y-m-d', strtotime('-1 day')),
        '11:00',
    ]);
    $booking_id = (int) $conn->lastInsertId();
    $cleanup_booking_id = $booking_id;

    $fields = json_encode([
        ['label' => 'Summary', 'type' => 'textarea', 'required' => 1],
        ['label' => 'Homework', 'type' => 'textarea'],
    ]);
    $conn->prepare("
        INSERT INTO form_templates (name, description, form_type, fields, is_internal, show_in_client_portal, is_active)
        VALUES (?, ?, 'follow_up_note', ?, 1, 1, 1)
    ")->execute([
        'Follow Up Note ' . $suffix,
        'Completed by staff after the appointment.',
        $fields,
    ]);
    $template_id = (int) $conn->lastInsertId();
    $cleanup_template_ids[] = $template_id;

    $request = bdta_create_form_request($conn, $template_id, $client_id, $booking_id, null, date('Y-m-d H:i:s'));
    $first_submission_id = array_int_value($request, 'submission_id');
    $cleanup_submission_ids[] = $first_submission_id;
    $latest_submission_id = $first_submission_id;

    $conn->prepare("
        UPDATE form_submissions
        SET responses = ?, status = 'submitted', submitted_at = '2026-01-02 09:00:00'
        WHERE id = ?
    ")->execute([json_encode(['0' => 'Great progress', '1' => 'Practice leash work']), $first_submission_id]);

    $notify_result = bdta_notify_follow_up_note_completed($conn, $first_submission_id);
    assertFollowUpNoteTest($notify_result['success'] === false, 'Expected notification email send to fail with the test SMTP settings.');

    $email_stmt = $conn->prepare("SELECT subject, body_html, body_text, status FROM client_emails WHERE client_id = ? ORDER BY id DESC LIMIT 1");
    $email_stmt->execute([$client_id]);
    $email_row = $email_stmt->fetch(PDO::FETCH_ASSOC);
    assertFollowUpNoteTest(is_array($email_row), 'Expected follow-up notification email to be logged.');
    assertFollowUpNoteTest(
        scalar_string($email_row['subject'] ?? '') === 'Your follow-up note is ready to review',
        'Expected follow-up notification subject.'
    );
    assertFollowUpNoteTest(
        str_contains(scalar_string($email_row['body_html'] ?? ''), '/portal/form_submission_view.php?id=' . $first_submission_id),
        'Expected follow-up portal review link in email body.'
    );
    assertFollowUpNoteTest(
        str_contains(scalar_string($email_row['body_html'] ?? ''), 'Follow-up details'),
        'Expected follow-up email to include a details section.'
    );
    assertFollowUpNoteTest(
        str_contains(scalar_string($email_row['body_html'] ?? ''), 'Great progress'),
        'Expected follow-up email HTML body to include submitted note details.'
    );
    assertFollowUpNoteTest(
        str_contains(scalar_string($email_row['body_html'] ?? ''), 'Practice leash work'),
        'Expected follow-up email HTML body to include all submitted responses.'
    );
    assertFollowUpNoteTest(
        str_contains(scalar_string($email_row['body_text'] ?? ''), "Follow-up details:\nSummary:\nGreat progress"),
        'Expected follow-up email text body to include submitted note details.'
    );
    assertFollowUpNoteTest(scalar_string($email_row['status'] ?? '') === 'failed', 'Expected the logged email to reflect the SMTP failure.');

    $notification_stmt = $conn->prepare("
        SELECT title, message, url
        FROM notifications
        WHERE audience = 'portal' AND recipient_id = ? AND entity_type = 'follow_up_note' AND entity_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $notification_stmt->execute([$client_id, $first_submission_id]);
    $notification_row = $notification_stmt->fetch(PDO::FETCH_ASSOC);
    assertFollowUpNoteTest(is_array($notification_row), 'Expected a portal notification for the follow-up note.');
    assertFollowUpNoteTest(
        scalar_string($notification_row['title'] ?? '') === 'New follow-up note available',
        'Expected follow-up portal notification title.'
    );
    assertFollowUpNoteTest(
        str_contains(scalar_string($notification_row['message'] ?? ''), 'ready to review'),
        'Expected follow-up portal notification message.'
    );
    assertFollowUpNoteTest(
        scalar_string($notification_row['url'] ?? '') === '/portal/form_submission_view.php?id=' . $first_submission_id,
        'Expected follow-up portal notification to link to the review page.'
    );

    $conn->prepare("
        INSERT INTO form_templates (name, description, form_type, fields, is_internal, show_in_client_portal, is_active)
        VALUES (?, ?, 'follow_up_note', ?, 1, 0, 1)
    ")->execute([
        'Hidden Follow Up Note ' . $suffix,
        'Completed by staff after the appointment but hidden from the portal.',
        $fields,
    ]);
    $hidden_template_id = (int) $conn->lastInsertId();
    $cleanup_template_ids[] = $hidden_template_id;

    $hidden_request = bdta_create_form_request($conn, $hidden_template_id, $client_id, $booking_id, null, date('Y-m-d H:i:s'));
    $hidden_submission_id = array_int_value($hidden_request, 'submission_id');
    $cleanup_submission_ids[] = $hidden_submission_id;
    $conn->prepare("
        UPDATE form_submissions
        SET responses = ?, status = 'submitted', submitted_at = '2026-01-03 09:00:00'
        WHERE id = ?
    ")->execute([json_encode(['0' => 'Private update', '1' => 'No portal view']), $hidden_submission_id]);

    $latest_email_id_stmt = $conn->query("SELECT COALESCE(MAX(id), 0) FROM client_emails");
    $latest_email_id_before_hidden_notify = safe_int($latest_email_id_stmt->fetchColumn());
    $latest_notification_id_stmt = $conn->query("SELECT COALESCE(MAX(id), 0) FROM notifications");
    $latest_notification_id_before_hidden_notify = safe_int($latest_notification_id_stmt->fetchColumn());

    $hidden_notify_result = bdta_notify_follow_up_note_completed($conn, $hidden_submission_id);
    assertFollowUpNoteTest($hidden_notify_result['success'] === false, 'Expected hidden follow-up portal notifications to be suppressed.');
    $hidden_notify_message = isset($hidden_notify_result['message']) && is_string($hidden_notify_result['message'])
        ? $hidden_notify_result['message']
        : '';
    assertFollowUpNoteTest(
        $hidden_notify_message === 'This follow-up note is hidden from the client portal.',
        'Expected hidden follow-up notifications to explain why the client was not notified.'
    );

    $latest_email_id_stmt = $conn->query("SELECT COALESCE(MAX(id), 0) FROM client_emails");
    assertFollowUpNoteTest(
        safe_int($latest_email_id_stmt->fetchColumn()) === $latest_email_id_before_hidden_notify,
        'Hidden follow-up notes should not create a client email.'
    );
    $latest_notification_id_stmt = $conn->query("SELECT COALESCE(MAX(id), 0) FROM notifications");
    assertFollowUpNoteTest(
        safe_int($latest_notification_id_stmt->fetchColumn()) === $latest_notification_id_before_hidden_notify,
        'Hidden follow-up notes should not create a portal notification.'
    );

    $second_request = bdta_create_form_request($conn, $template_id, $client_id, $booking_id, null, date('Y-m-d H:i:s'));
    $second_submission_id = array_int_value($second_request, 'submission_id');
    $cleanup_submission_ids[] = $second_submission_id;
    $conn->prepare("
        UPDATE form_submissions
        SET responses = ?, status = 'reviewed', submitted_at = '2026-01-01 09:00:00', reviewed_at = '2026-01-01 09:30:00'
        WHERE id = ?
    ")->execute([json_encode(['0' => 'Reviewed update', '1' => 'Continue reinforcement']), $second_submission_id]);

    $forms_stmt = $conn->prepare("
        SELECT fs.id, fs.booking_id, fs.status, fs.submitted_at, ft.form_type
        FROM form_submissions fs
        JOIN form_templates ft ON fs.template_id = ft.id
        WHERE fs.client_id = ?
        ORDER BY fs.id DESC
    ");
    $forms_stmt->execute([$client_id]);
    $indexed = bdta_index_follow_up_submissions_by_booking(assoc_rows($forms_stmt->fetchAll(PDO::FETCH_ASSOC)));
    assertFollowUpNoteTest(isset($indexed[$booking_id]), 'Expected the booking to be indexed with its follow-up submission.');
    assertFollowUpNoteTest(array_int_value($indexed[$booking_id], 'id') === $latest_submission_id, 'Expected the indexed follow-up submission to use the newest submitted_at value even when rows are unsorted.');
    assertFollowUpNoteTest(bdta_form_submission_requires_client_review('follow_up_note'), 'Expected follow-up note forms to require client review.');
    assertFollowUpNoteTest(bdta_form_submission_requires_client_review('session_note'), 'Expected legacy session notes to require client review.');
    assertFollowUpNoteTest(!bdta_form_submission_requires_client_review('client_form'), 'Expected client forms to remain outside the follow-up review flow.');

    echo "=== Follow-up Note Form Tests ===\n\n";
    echo "Follow-up notification emails point clients to the portal review page\n";
    echo "Follow-up submissions are indexed by booking for the client profile action state\n";
    echo "Only follow-up note form types participate in the client review flow\n\n";
    echo "=== Follow-up Note Form Tests Passed! ===\n";
} finally {
    if ($cleanup_submission_ids !== []) {
        $delete_submission_stmt = $conn->prepare("DELETE FROM form_submissions WHERE id = ?");
        $conn->beginTransaction();
        try {
            foreach ($cleanup_submission_ids as $cleanup_submission_id) {
                $delete_submission_stmt->execute([(int) $cleanup_submission_id]);
            }
            $conn->commit();
        } catch (Throwable $cleanup_error) {
            $conn->rollBack();
            throw $cleanup_error;
        }
    }
    if ($cleanup_booking_id > 0) {
        $conn->prepare("DELETE FROM bookings WHERE id = ?")->execute([(int) $cleanup_booking_id]);
    }
    if ($cleanup_template_ids !== []) {
        $delete_template_stmt = $conn->prepare("DELETE FROM form_templates WHERE id = ?");
        foreach ($cleanup_template_ids as $cleanup_template_id) {
            $delete_template_stmt->execute([(int) $cleanup_template_id]);
        }
    }
    if ($cleanup_appointment_type_id > 0) {
        $conn->prepare("DELETE FROM appointment_types WHERE id = ?")->execute([(int) $cleanup_appointment_type_id]);
    }
    if ($cleanup_client_id > 0) {
        $conn->prepare("DELETE FROM client_emails WHERE client_id = ?")->execute([(int) $cleanup_client_id]);
        $conn->prepare("DELETE FROM clients WHERE id = ?")->execute([(int) $cleanup_client_id]);
    }
}
