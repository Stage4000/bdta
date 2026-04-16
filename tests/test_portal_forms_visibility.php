#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/config.php';
require_once dirname(__DIR__) . '/backend/includes/form_types.php';
require_once dirname(__DIR__) . '/backend/includes/follow_up_notes.php';

echo "=== Portal Forms Visibility Tests ===\n\n";

$db = new Database();
$conn = $db->getConnection();
$cleanup = [
    'submission_ids' => [],
    'template_ids' => [],
    'client_ids' => [],
];

try {
    $suffix = bin2hex(random_bytes(4));

    $conn->prepare("INSERT INTO clients (name, email, password_hash) VALUES (?, ?, ?)")
        ->execute([
            'Portal Visible Client ' . $suffix,
            'portal-visible-' . $suffix . '@example.com',
            password_hash('PortalPass123!', PASSWORD_DEFAULT),
        ]);
    $client_id = (int) $conn->lastInsertId();
    $cleanup['client_ids'][] = $client_id;

    $conn->prepare("INSERT INTO clients (name, email, password_hash) VALUES (?, ?, ?)")
        ->execute([
            'Portal Other Client ' . $suffix,
            'portal-other-' . $suffix . '@example.com',
            password_hash('PortalPass123!', PASSWORD_DEFAULT),
        ]);
    $other_client_id = (int) $conn->lastInsertId();
    $cleanup['client_ids'][] = $other_client_id;

    $create_template = $conn->prepare("
        INSERT INTO form_templates (name, form_type, fields, is_internal, is_active)
        VALUES (?, ?, ?, ?, 1)
    ");
    $fields_json = json_encode([['label' => 'Question', 'type' => 'text']]);
    if (!is_string($fields_json)) {
        throw new RuntimeException('Unable to encode test form fields.');
    }

    $create_template->execute(['Client Visible Form ' . $suffix, 'client_form', $fields_json, 0]);
    $client_form_template_id = (int) $conn->lastInsertId();
    $cleanup['template_ids'][] = $client_form_template_id;

    $create_template->execute(['Internal Flag Form ' . $suffix, 'client_form', $fields_json, 1]);
    $internal_flag_template_id = (int) $conn->lastInsertId();
    $cleanup['template_ids'][] = $internal_flag_template_id;

    $create_template->execute(['Follow-up Review Form ' . $suffix, 'follow_up_note', $fields_json, 1]);
    $follow_up_template_id = (int) $conn->lastInsertId();
    $cleanup['template_ids'][] = $follow_up_template_id;

    $create_template->execute(['Forced Internal Form ' . $suffix, 'pet_form', $fields_json, 0]);
    $forced_internal_template_id = (int) $conn->lastInsertId();
    $cleanup['template_ids'][] = $forced_internal_template_id;

    $create_submission = $conn->prepare("
        INSERT INTO form_submissions (client_id, template_id, responses, status, submitted_at)
        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");

    $visible_responses = json_encode(['0' => 'Visible']);
    if (!is_string($visible_responses)) {
        throw new RuntimeException('Unable to encode test responses.');
    }

    $create_submission->execute([$client_id, $client_form_template_id, $visible_responses, 'submitted']);
    $visible_submission_id = (int) $conn->lastInsertId();
    $cleanup['submission_ids'][] = $visible_submission_id;

    $create_submission->execute([$client_id, $client_form_template_id, $visible_responses, 'reviewed']);
    $reviewed_submission_id = (int) $conn->lastInsertId();
    $cleanup['submission_ids'][] = $reviewed_submission_id;

    $create_submission->execute([$client_id, $client_form_template_id, $visible_responses, 'pending']);
    $pending_submission_id = (int) $conn->lastInsertId();
    $cleanup['submission_ids'][] = $pending_submission_id;

    $create_submission->execute([$client_id, $internal_flag_template_id, $visible_responses, 'submitted']);
    $internal_flag_submission_id = (int) $conn->lastInsertId();
    $cleanup['submission_ids'][] = $internal_flag_submission_id;

    $create_submission->execute([$client_id, $follow_up_template_id, $visible_responses, 'submitted']);
    $follow_up_submission_id = (int) $conn->lastInsertId();
    $cleanup['submission_ids'][] = $follow_up_submission_id;

    $create_submission->execute([$client_id, $forced_internal_template_id, $visible_responses, 'submitted']);
    $forced_internal_submission_id = (int) $conn->lastInsertId();
    $cleanup['submission_ids'][] = $forced_internal_submission_id;

    $create_submission->execute([$other_client_id, $client_form_template_id, $visible_responses, 'submitted']);
    $other_client_submission_id = (int) $conn->lastInsertId();
    $cleanup['submission_ids'][] = $other_client_submission_id;

    $_SESSION['portal_client_id'] = $client_id;
    $_SESSION['portal_client_name'] = 'Portal Visible Client ' . $suffix;

    $is_view_allowed = static function (?array $submission): bool {
        return $submission !== null
            && $submission !== []
            && bdta_form_type_forced_internal(array_string_value($submission, 'form_type')) === 0;
    };

    echo "Test 1: Agreements query returns completed client-visible submissions, including follow-up reviews\n";
    $agreements_stmt = $conn->prepare("
        SELECT fs.*, ft.name as form_title, ft.form_type, COALESCE(ft.is_internal, 0) AS template_is_internal
        FROM form_submissions fs
        LEFT JOIN form_templates ft ON fs.template_id = ft.id
        WHERE fs.client_id = ?
          AND fs.status IN ('submitted', 'reviewed')
        ORDER BY fs.submitted_at DESC
    ");
    $agreements_stmt->execute([$client_id]);
    $portal_list_rows = array_values(array_filter(
        $agreements_stmt->fetchAll(PDO::FETCH_ASSOC),
        static fn (array $submission): bool => bdta_form_submission_is_client_portal_visible($submission)
    ));
    $listed_ids = array_map(static fn (array $row): int => array_int_value($row, 'id'), $portal_list_rows);
    sort($listed_ids);
    $expected_list_ids = [$follow_up_submission_id, $reviewed_submission_id, $visible_submission_id];
    sort($expected_list_ids);
    if ($listed_ids !== $expected_list_ids) {
        throw new RuntimeException('Portal agreements list should include submitted/reviewed client-visible and follow-up review submissions.');
    }
    echo "  ✓ Portal list includes follow-up reviews and excludes pending, internal-flagged, forced-internal, and other-client submissions\n";

    echo "\nTest 2: portal list routes follow-up reviews to the dedicated portal review page\n";
    $portal_urls = [];
    foreach ($portal_list_rows as $row) {
        $portal_urls[array_int_value($row, 'id')] = bdta_get_client_portal_form_submission_url($row);
    }
    if (($portal_urls[$follow_up_submission_id] ?? '') !== PORTAL_URL . 'form_submission_view.php?id=' . $follow_up_submission_id) {
        throw new RuntimeException('Follow-up submissions should route to the portal review page.');
    }
    if (($portal_urls[$visible_submission_id] ?? '') !== PORTAL_URL . 'form_view.php?id=' . $visible_submission_id) {
        throw new RuntimeException('Standard client forms should keep using the generic portal form view page.');
    }
    echo "  ✓ Portal actions send follow-up notes to review view and client forms to generic form view\n";

    echo "\nTest 3: form_view allows submitted/reviewed non-internal submissions for owner\n";
    $view_stmt = $conn->prepare("
        SELECT fs.*,
               ft.name AS form_name,
               ft.form_type,
               ft.fields
        FROM form_submissions fs
        LEFT JOIN form_templates ft ON fs.template_id = ft.id
        WHERE fs.id = ?
          AND fs.client_id = ?
          AND COALESCE(ft.is_internal, 0) = 0
          AND fs.status IN ('submitted', 'reviewed')
        LIMIT 1
    ");
    $view_stmt->execute([$visible_submission_id, $client_id]);
    $allowed_submission = $view_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$is_view_allowed($allowed_submission)) {
        throw new RuntimeException('Client should be able to view their submitted non-internal form.');
    }
    echo "  ✓ Submitted non-internal form is viewable by owner\n";

    echo "\nTest 4: form_view blocks pending submissions\n";
    $view_stmt->execute([$pending_submission_id, $client_id]);
    if ($view_stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('Pending submission should not be viewable.');
    }
    echo "  ✓ Pending submission is blocked\n";

    echo "\nTest 5: form_view blocks forced-internal form types even when template flag is off\n";
    $view_stmt->execute([$forced_internal_submission_id, $client_id]);
    $forced_internal_fetch = $view_stmt->fetch(PDO::FETCH_ASSOC);
    $forced_internal_row = is_array($forced_internal_fetch) ? $forced_internal_fetch : null;
    if ($is_view_allowed($forced_internal_row)) {
        throw new RuntimeException('Forced-internal form type must not be viewable.');
    }
    echo "  ✓ Forced-internal template type is blocked\n";

    echo "\nTest 6: form_view blocks submissions from other clients\n";
    $view_stmt->execute([$other_client_submission_id, $client_id]);
    $other_client_row = $view_stmt->fetch(PDO::FETCH_ASSOC);
    if ($other_client_row !== false) {
        throw new RuntimeException('Submission from another client should not be viewable.');
    }
    echo "  ✓ Cross-client access is blocked\n";

    echo "\n=== ALL PORTAL FORMS VISIBILITY TESTS PASSED! ===\n";
} catch (Throwable $e) {
    echo "✗ TEST FAILED: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    if ($cleanup['submission_ids'] !== []) {
        $placeholders = implode(',', array_fill(0, count($cleanup['submission_ids']), '?'));
        $stmt = $conn->prepare("DELETE FROM form_submissions WHERE id IN ($placeholders)");
        $stmt->execute($cleanup['submission_ids']);
    }
    if ($cleanup['template_ids'] !== []) {
        $placeholders = implode(',', array_fill(0, count($cleanup['template_ids']), '?'));
        $stmt = $conn->prepare("DELETE FROM form_templates WHERE id IN ($placeholders)");
        $stmt->execute($cleanup['template_ids']);
    }
    if ($cleanup['client_ids'] !== []) {
        $placeholders = implode(',', array_fill(0, count($cleanup['client_ids']), '?'));
        $stmt = $conn->prepare("DELETE FROM clients WHERE id IN ($placeholders)");
        $stmt->execute($cleanup['client_ids']);
    }
}
