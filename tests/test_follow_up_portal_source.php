#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/form_types.php';

function assertFollowUpPortalSource(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$agreements_page = file_get_contents(dirname(__DIR__) . '/portal/agreements.php');
if (!is_string($agreements_page)) {
    throw new RuntimeException('Expected to read the portal agreements page.');
}

$follow_up_notes = file_get_contents(dirname(__DIR__) . '/backend/includes/follow_up_notes.php');
if (!is_string($follow_up_notes)) {
    throw new RuntimeException('Expected to read the follow-up notes helper.');
}

assertFollowUpPortalSource(
    bdta_get_form_template_access_state('follow_up_note', 0)['effective_internal'] === true,
    'Follow-up note templates should remain internal for admin/staff completion.'
);
assertFollowUpPortalSource(
    str_contains($agreements_page, 'bdta_form_submission_is_client_portal_visible($submission)'),
    'Portal agreements page should explicitly allow client-visible follow-up review submissions.'
);
assertFollowUpPortalSource(
    str_contains($follow_up_notes, "if (!array_key_exists('template_is_internal', \$submission))"),
    'Portal visibility helper should fail closed when template_is_internal is not provided.'
);
assertFollowUpPortalSource(
    str_contains($agreements_page, 'bdta_get_client_portal_form_submission_url($fs)'),
    'Portal agreements page should route follow-up submissions to the portal review page.'
);
assertFollowUpPortalSource(
    str_contains($agreements_page, "\$client_review_submission ? 'Review' : 'View'"),
    'Portal agreements page should distinguish follow-up review actions from normal form viewing.'
);
assertFollowUpPortalSource(
    str_contains($follow_up_notes, 'function bdta_get_follow_up_note_email_details'),
    'Follow-up note helper should build email detail sections.'
);
assertFollowUpPortalSource(
    str_contains($follow_up_notes, 'Follow-up details'),
    'Follow-up notification emails should include the follow-up details heading.'
);

echo "Follow-up portal source checks passed.\n";
