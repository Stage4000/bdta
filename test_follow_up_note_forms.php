<?php

$sqlite_filename = 'follow_up_note_forms_test_' . uniqid('', true) . '.sqlite';
$sqlite_path = __DIR__ . '/backend/' . $sqlite_filename;
putenv('DB_TYPE=sqlite');
putenv('SQLITE_DB_PATH=' . $sqlite_filename);

require_once __DIR__ . '/backend/includes/config.php';
require_once __DIR__ . '/backend/includes/database.php';
require_once __DIR__ . '/backend/includes/form_link_requests.php';
require_once __DIR__ . '/backend/includes/follow_up_notes.php';
require_once __DIR__ . '/backend/includes/settings.php';

$db = new Database();
$conn = $db->getConnection();

function assertFollowUpNoteTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$cleanup_submission_id = 0;

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

    $conn->prepare("INSERT INTO appointment_types (name, duration_minutes, is_active) VALUES (?, 60, 1)")
        ->execute(['Follow Up Session ' . $suffix]);
    $appointment_type_id = (int) $conn->lastInsertId();

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

    $fields = json_encode([
        ['label' => 'Summary', 'type' => 'textarea', 'required' => 1],
        ['label' => 'Homework', 'type' => 'textarea'],
    ]);
    $conn->prepare("
        INSERT INTO form_templates (name, description, form_type, fields, is_internal, is_active)
        VALUES (?, ?, 'follow_up_note', ?, 1, 1)
    ")->execute([
        'Follow Up Note ' . $suffix,
        'Completed by staff after the appointment.',
        $fields,
    ]);
    $template_id = (int) $conn->lastInsertId();

    $request = bdta_create_form_request($conn, $template_id, $client_id, $booking_id, null, date('Y-m-d H:i:s'));
    $cleanup_submission_id = (int) $request['submission_id'];

    $conn->prepare("
        UPDATE form_submissions
        SET responses = ?, status = 'submitted', submitted_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([json_encode(['0' => 'Great progress', '1' => 'Practice leash work']), $cleanup_submission_id]);

    $notify_result = bdta_notify_follow_up_note_completed($conn, $cleanup_submission_id);
    assertFollowUpNoteTest($notify_result['success'] === false, 'Expected notification email send to fail with the test SMTP settings.');

    $email_stmt = $conn->prepare("SELECT subject, body_html, status FROM client_emails WHERE client_id = ? ORDER BY id DESC LIMIT 1");
    $email_stmt->execute([$client_id]);
    $email_row = $email_stmt->fetch(PDO::FETCH_ASSOC);
    assertFollowUpNoteTest(is_array($email_row), 'Expected follow-up notification email to be logged.');
    assertFollowUpNoteTest(str_contains(scalar_string($email_row['subject'] ?? ''), 'follow-up note'), 'Expected follow-up notification subject.');
    assertFollowUpNoteTest(str_contains(scalar_string($email_row['body_html'] ?? ''), '/portal/form_submission_view.php?id=' . $cleanup_submission_id), 'Expected follow-up portal review link in email body.');
    assertFollowUpNoteTest(scalar_string($email_row['status'] ?? '') === 'failed', 'Expected the logged email to reflect the SMTP failure.');

    $forms_stmt = $conn->prepare("
        SELECT fs.id, fs.booking_id, fs.status, ft.form_type
        FROM form_submissions fs
        JOIN form_templates ft ON fs.template_id = ft.id
        WHERE fs.client_id = ?
        ORDER BY fs.submitted_at DESC
    ");
    $forms_stmt->execute([$client_id]);
    $indexed = bdta_index_follow_up_submissions_by_booking(assoc_rows($forms_stmt->fetchAll(PDO::FETCH_ASSOC)));
    assertFollowUpNoteTest(isset($indexed[$booking_id]), 'Expected the booking to be indexed with its follow-up submission.');
    assertFollowUpNoteTest(array_int_value($indexed[$booking_id], 'id') === $cleanup_submission_id, 'Expected the indexed follow-up submission to match the booking.');
    assertFollowUpNoteTest(bdta_form_submission_requires_client_review('follow_up_note'), 'Expected follow-up note forms to require client review.');
    assertFollowUpNoteTest(bdta_form_submission_requires_client_review('session_note'), 'Expected legacy session notes to require client review.');
    assertFollowUpNoteTest(!bdta_form_submission_requires_client_review('client_form'), 'Expected client forms to remain outside the follow-up review flow.');

    echo "=== Follow-up Note Form Tests ===\n\n";
    echo "✓ Follow-up notification emails point clients to the portal review page\n";
    echo "✓ Follow-up submissions are indexed by booking for the client profile action state\n";
    echo "✓ Only follow-up note form types participate in the client review flow\n\n";
    echo "=== Follow-up Note Form Tests Passed! ===\n";
} finally {
    if ($cleanup_submission_id > 0) {
        $conn->prepare("DELETE FROM form_submissions WHERE id = ?")->execute([$cleanup_submission_id]);
    }
    if (file_exists($sqlite_path)) {
        unlink($sqlite_path);
    }
}
