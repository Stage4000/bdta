#!/usr/bin/env php
<?php

require_once __DIR__ . '/backend/includes/database.php';

function expectTrue(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fetchStatus(PDO $conn, string $table, int $id): string {
    $stmt = $conn->prepare("SELECT status FROM {$table} WHERE id = ?");
    $stmt->execute([$id]);
    $status = $stmt->fetchColumn();

    if (!is_string($status)) {
        throw new RuntimeException("Failed to load status from {$table} for row {$id}");
    }

    return $status;
}

echo "=== Client Archive Test ===\n\n";

$db = new Database();
$conn = $db->getConnection();
$suffix = bin2hex(random_bytes(5));
$today = gmdate('Y-m-d');
$tomorrow = gmdate('Y-m-d', strtotime('+1 day'));
$workflow_id = null;
$workflow_step_id = null;
$active_enrollment_id = null;
$completed_enrollment_id = null;
$form_template_id = null;
$pending_quote_id = null;
$accepted_quote_id = null;
$pending_contract_id = null;
$signed_contract_id = null;
$pending_invoice_id = null;
$paid_invoice_id = null;
$pending_installment_id = null;
$paid_installment_id = null;
$pending_form_id = null;
$submitted_form_id = null;
$pending_booking_id = null;
$completed_booking_id = null;
$client_id = null;

try {
    $stmt = $conn->prepare("
        INSERT INTO clients (name, email, phone)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([
        'Archive Test Client ' . $suffix,
        'archive-test-' . $suffix . '@example.invalid',
        '555-0100',
    ]);
    $client_id = (int)$conn->lastInsertId();

    $stmt = $conn->prepare("
        INSERT INTO quotes (quote_number, client_id, title, description, amount, expiration_date, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute(['Q-PENDING-' . $suffix, $client_id, 'Pending Quote', '', 100.00, $tomorrow, 'sent']);
    $pending_quote_id = (int)$conn->lastInsertId();
    $stmt->execute(['Q-ACCEPTED-' . $suffix, $client_id, 'Accepted Quote', '', 150.00, $tomorrow, 'accepted']);
    $accepted_quote_id = (int)$conn->lastInsertId();

    $stmt = $conn->prepare("
        INSERT INTO contracts (contract_number, client_id, title, description, contract_text, status, created_date)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute(['C-PENDING-' . $suffix, $client_id, 'Pending Contract', '', 'Contract', 'sent', $today]);
    $pending_contract_id = (int)$conn->lastInsertId();
    $stmt->execute(['C-SIGNED-' . $suffix, $client_id, 'Signed Contract', '', 'Contract', 'signed', $today]);
    $signed_contract_id = (int)$conn->lastInsertId();

    $stmt = $conn->prepare("
        INSERT INTO invoices (invoice_number, client_id, issue_date, due_date, subtotal, total_amount, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute(['I-PENDING-' . $suffix, $client_id, $today, $tomorrow, 200.00, 200.00, 'sent']);
    $pending_invoice_id = (int)$conn->lastInsertId();
    $stmt->execute(['I-PAID-' . $suffix, $client_id, $today, $tomorrow, 300.00, 300.00, 'paid']);
    $paid_invoice_id = (int)$conn->lastInsertId();

    $stmt = $conn->prepare("
        INSERT INTO invoice_installments (invoice_id, installment_number, amount, due_date, status)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$pending_invoice_id, 1, 200.00, $tomorrow, 'unpaid']);
    $pending_installment_id = (int)$conn->lastInsertId();
    $stmt->execute([$paid_invoice_id, 1, 300.00, $tomorrow, 'paid']);
    $paid_installment_id = (int)$conn->lastInsertId();

    $stmt = $conn->prepare("
        INSERT INTO form_templates (name, description, fields)
        VALUES (?, ?, ?)
    ");
    $stmt->execute(['Archive Test Form ' . $suffix, '', '[]']);
    $form_template_id = (int)$conn->lastInsertId();

    $stmt = $conn->prepare("
        INSERT INTO form_submissions (client_id, template_id, responses, status, submitted_at)
        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$client_id, $form_template_id, '{}', 'pending']);
    $pending_form_id = (int)$conn->lastInsertId();
    $stmt->execute([$client_id, $form_template_id, '{}', 'submitted']);
    $submitted_form_id = (int)$conn->lastInsertId();

    $stmt = $conn->prepare("
        INSERT INTO workflows (name, description)
        VALUES (?, ?)
    ");
    $stmt->execute(['Archive Test Workflow ' . $suffix, '']);
    $workflow_id = (int)$conn->lastInsertId();

    $stmt = $conn->prepare("
        INSERT INTO workflow_steps (workflow_id, step_order, step_name, email_subject, email_body_html, delay_type)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$workflow_id, 1, 'Step 1', 'Subject', '<p>Hello</p>', 'immediate']);
    $workflow_step_id = (int)$conn->lastInsertId();

    $stmt = $conn->prepare("
        INSERT INTO workflow_enrollments (workflow_id, client_id, status)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$workflow_id, $client_id, 'active']);
    $active_enrollment_id = (int)$conn->lastInsertId();
    $stmt->execute([$workflow_id, $client_id, 'completed']);
    $completed_enrollment_id = (int)$conn->lastInsertId();

    $stmt = $conn->prepare("
        INSERT INTO workflow_step_executions (enrollment_id, step_id, scheduled_for, status)
        VALUES (?, ?, CURRENT_TIMESTAMP, ?)
    ");
    $stmt->execute([$active_enrollment_id, $workflow_step_id, 'pending']);
    $pending_step_execution_id = (int)$conn->lastInsertId();
    $stmt->execute([$completed_enrollment_id, $workflow_step_id, 'completed']);
    $completed_step_execution_id = (int)$conn->lastInsertId();

    $stmt = $conn->prepare("
        INSERT INTO bookings (client_name, client_email, service_type, appointment_date, appointment_time, client_id, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute(['Archive Test Client', 'archive-test-' . $suffix . '@example.invalid', 'Training', $tomorrow, '10:00', $client_id, 'pending']);
    $pending_booking_id = (int)$conn->lastInsertId();
    $stmt->execute(['Archive Test Client', 'archive-test-' . $suffix . '@example.invalid', 'Training', $today, '11:00', $client_id, 'completed']);
    $completed_booking_id = (int)$conn->lastInsertId();

    expectTrue($db->archiveClient($client_id), 'archiveClient should archive an active client');

    $stmt = $conn->prepare("SELECT is_archived, archived_at FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    expectTrue(is_array($client), 'Archived client should still exist');
    expectTrue((int)($client['is_archived'] ?? 0) === 1, 'Client should be marked archived');
    expectTrue(!empty($client['archived_at']), 'Archived client should record archived_at');

    expectTrue(fetchStatus($conn, 'quotes', $pending_quote_id) === 'declined', 'Pending quote should be declined on archive');
    expectTrue(fetchStatus($conn, 'quotes', $accepted_quote_id) === 'accepted', 'Accepted quote should remain accepted');
    expectTrue(fetchStatus($conn, 'contracts', $pending_contract_id) === 'expired', 'Pending contract should be expired on archive');
    expectTrue(fetchStatus($conn, 'contracts', $signed_contract_id) === 'signed', 'Signed contract should remain signed');
    expectTrue(fetchStatus($conn, 'invoices', $pending_invoice_id) === 'cancelled', 'Pending invoice should be cancelled on archive');
    expectTrue(fetchStatus($conn, 'invoices', $paid_invoice_id) === 'paid', 'Paid invoice should remain paid');
    expectTrue(fetchStatus($conn, 'invoice_installments', $pending_installment_id) === 'cancelled', 'Unpaid installment should be cancelled on archive');
    expectTrue(fetchStatus($conn, 'invoice_installments', $paid_installment_id) === 'paid', 'Paid installment should remain paid');
    expectTrue(fetchStatus($conn, 'form_submissions', $pending_form_id) === 'cancelled', 'Pending form should be cancelled on archive');
    expectTrue(fetchStatus($conn, 'form_submissions', $submitted_form_id) === 'submitted', 'Submitted form should remain submitted');
    expectTrue(fetchStatus($conn, 'workflow_enrollments', $active_enrollment_id) === 'cancelled', 'Active workflow enrollment should be cancelled on archive');
    expectTrue(fetchStatus($conn, 'workflow_enrollments', $completed_enrollment_id) === 'completed', 'Completed workflow enrollment should remain completed');
    expectTrue(fetchStatus($conn, 'workflow_step_executions', $pending_step_execution_id) === 'failed', 'Pending workflow step should be marked failed on archive');
    expectTrue(fetchStatus($conn, 'workflow_step_executions', $completed_step_execution_id) === 'completed', 'Completed workflow step should remain completed');
    expectTrue(fetchStatus($conn, 'bookings', $pending_booking_id) === 'cancelled', 'Pending booking should be cancelled on archive');
    expectTrue(fetchStatus($conn, 'bookings', $completed_booking_id) === 'completed', 'Completed booking should remain completed');

    expectTrue($db->unarchiveClient($client_id), 'unarchiveClient should reactivate an archived client');

    $stmt = $conn->prepare("SELECT is_archived, archived_at FROM clients WHERE id = ?");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    expectTrue(is_array($client), 'Unarchived client should still exist');
    expectTrue((int)($client['is_archived'] ?? 0) === 0, 'Client should no longer be marked archived');
    expectTrue(empty($client['archived_at']), 'Unarchived client should clear archived_at');

    echo "✓ Client archive and unarchive behavior verified\n";
} catch (Throwable $e) {
    echo "\n✗ TEST FAILED\n";
    echo $e->getMessage() . "\n";
    exit(1);
} finally {
    if ($pending_booking_id !== null) {
        $conn->prepare("DELETE FROM bookings WHERE id IN (?, ?)")->execute([$pending_booking_id, $completed_booking_id]);
    }
    if ($active_enrollment_id !== null) {
        $conn->prepare("DELETE FROM workflow_step_executions WHERE enrollment_id IN (?, ?)")->execute([$active_enrollment_id, $completed_enrollment_id]);
        $conn->prepare("DELETE FROM workflow_enrollments WHERE id IN (?, ?)")->execute([$active_enrollment_id, $completed_enrollment_id]);
    }
    if ($workflow_step_id !== null) {
        $conn->prepare("DELETE FROM workflow_steps WHERE id = ?")->execute([$workflow_step_id]);
    }
    if ($workflow_id !== null) {
        $conn->prepare("DELETE FROM workflows WHERE id = ?")->execute([$workflow_id]);
    }
    if ($pending_form_id !== null) {
        $conn->prepare("DELETE FROM form_submissions WHERE id IN (?, ?)")->execute([$pending_form_id, $submitted_form_id]);
    }
    if ($form_template_id !== null) {
        $conn->prepare("DELETE FROM form_templates WHERE id = ?")->execute([$form_template_id]);
    }
    if ($pending_installment_id !== null) {
        $conn->prepare("DELETE FROM invoice_installments WHERE id IN (?, ?)")->execute([$pending_installment_id, $paid_installment_id]);
    }
    if ($pending_invoice_id !== null) {
        $conn->prepare("DELETE FROM invoices WHERE id IN (?, ?)")->execute([$pending_invoice_id, $paid_invoice_id]);
    }
    if ($pending_contract_id !== null) {
        $conn->prepare("DELETE FROM contracts WHERE id IN (?, ?)")->execute([$pending_contract_id, $signed_contract_id]);
    }
    if ($pending_quote_id !== null) {
        $conn->prepare("DELETE FROM quotes WHERE id IN (?, ?)")->execute([$pending_quote_id, $accepted_quote_id]);
    }
    if ($client_id !== null) {
        $conn->prepare("DELETE FROM clients WHERE id = ?")->execute([$client_id]);
    }
}
