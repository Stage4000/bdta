#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/booking_action_links.php';

function bdta_assert_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function bdta_read_file(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, 'Unable to read fixture: ' . $path . PHP_EOL);
        exit(1);
    }

    return $contents;
}

bdta_assert_test(
    bdta_build_portal_booking_link('https://example.com', 42) === 'https://example.com/portal/appointments.php?booking_id=42',
    'Booking link should point to the appointments page with the booking id.'
);
bdta_assert_test(
    bdta_build_portal_booking_link('https://example.com/', 42, 'reschedule') === 'https://example.com/portal/appointments.php?booking_id=42&action=reschedule',
    'Reschedule link should append the reschedule action.'
);
bdta_assert_test(
    bdta_build_portal_booking_link('https://example.com', 42, 'cancel') === 'https://example.com/portal/appointments.php?booking_id=42&action=cancel',
    'Cancel link should append the cancel action.'
);
bdta_assert_test(
    bdta_build_portal_booking_link('https://example.com', 42, 'unexpected') === 'https://example.com/portal/appointments.php?booking_id=42',
    'Unexpected actions should be ignored.'
);

$email_service = bdta_read_file(dirname(__DIR__) . '/backend/includes/email_service.php');
$booking_reminder = bdta_read_file(dirname(__DIR__) . '/backend/cron/tasks/booking_reminder.php');
$portal_appointments = bdta_read_file(dirname(__DIR__) . '/portal/appointments.php');
$clients_view = bdta_read_file(dirname(__DIR__) . '/client/clients_view.php');
$templates_edit = bdta_read_file(dirname(__DIR__) . '/client/email_templates_edit.php');
$templates_list = bdta_read_file(dirname(__DIR__) . '/client/email_templates_list.php');

bdta_assert_test(
    str_contains($email_service, "'booking_reschedule_link'") && str_contains($email_service, "'booking_cancel_link'"),
    'Email service should expose booking reschedule and cancel template variables.'
);
bdta_assert_test(
    str_contains($email_service, 'Reschedule Appointment') && str_contains($email_service, 'Cancel Appointment'),
    'Built-in booking confirmation emails should include reschedule and cancel actions.'
);
bdta_assert_test(
    str_contains($booking_reminder, "'booking_reschedule_link'") && str_contains($booking_reminder, "'booking_cancel_link'"),
    'Booking reminder templates should receive reschedule and cancel links.'
);
bdta_assert_test(
    str_contains($booking_reminder, 'MANAGE YOUR APPOINTMENT'),
    'Built-in booking reminder text should include booking management links.'
);
bdta_assert_test(
    str_contains($portal_appointments, 'new URLSearchParams(window.location.search)') &&
    str_contains($portal_appointments, "params.get('action')"),
    'Portal appointments page should honor booking action deep links from emails.'
);
bdta_assert_test(
    str_contains($clients_view, "name=\"booking_action\" value=\"reschedule\"") &&
    str_contains($clients_view, 'showAdminRescheduleModal'),
    'Client view should include admin reschedule controls.'
);
bdta_assert_test(
    str_contains($clients_view, "name=\"booking_action\" value=\"cancel\""),
    'Client view should include admin cancellation controls.'
);
bdta_assert_test(
    str_contains($templates_edit, '{{booking_reschedule_link}}') &&
    str_contains($templates_edit, '{{booking_cancel_link}}'),
    'Email template editor should document the new booking action variables.'
);
bdta_assert_test(
    str_contains($templates_list, '{{booking_reschedule_link}}') &&
    str_contains($templates_list, '{{booking_cancel_link}}'),
    'Email templates list should highlight the new booking action variables.'
);

echo "Booking action link checks passed.\n";
