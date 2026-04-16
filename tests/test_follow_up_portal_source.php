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

$visibility_helper_start = strpos($follow_up_notes, 'function bdta_form_submission_is_client_portal_visible');
$visibility_helper_end = strpos($follow_up_notes, 'function bdta_get_client_portal_form_submission_url');
if ($visibility_helper_start === false || $visibility_helper_end === false || $visibility_helper_end <= $visibility_helper_start) {
    throw new RuntimeException('Expected to locate the portal visibility helper in follow_up_notes.php.');
}

$visibility_helper = substr($follow_up_notes, $visibility_helper_start, $visibility_helper_end - $visibility_helper_start);
if ($visibility_helper === '') {
    throw new RuntimeException('Expected to extract the portal visibility helper.');
}

$sandbox_file = sys_get_temp_dir() . '/bdta-follow-up-visibility-helper-' . bin2hex(random_bytes(6)) . '.php';
$sandbox_code = <<<PHP
<?php
namespace BdtaFollowUpPortalSourceSandbox;

function array_string_value(array \$array, string \$key, string \$default = ''): string
{
    \$value = \$array[\$key] ?? \$default;
    return is_string(\$value) ? \$value : \$default;
}

function array_int_value(array \$array, string \$key, int \$default = 0): int
{
    \$value = \$array[\$key] ?? \$default;
    return is_numeric(\$value) ? (int) \$value : \$default;
}

function bdta_form_submission_requires_client_review(string \$form_type): bool
{
    return \$form_type === 'follow_up_note';
}

function bdta_form_type_forced_internal(string \$form_type): int
{
    return \$form_type === 'pet_form' ? 1 : 0;
}

{$visibility_helper}
PHP;

if (file_put_contents($sandbox_file, $sandbox_code) === false) {
    throw new RuntimeException('Expected to write the portal visibility sandbox file.');
}

try {
    require $sandbox_file;
} finally {
    if (file_exists($sandbox_file)) {
        unlink($sandbox_file);
    }
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
    \BdtaFollowUpPortalSourceSandbox\bdta_form_submission_is_client_portal_visible(['form_type' => 'client_form']) === false,
    'Portal visibility helper should fail closed when template_is_internal is not provided.'
);
assertFollowUpPortalSource(
    \BdtaFollowUpPortalSourceSandbox\bdta_form_submission_is_client_portal_visible(['form_type' => 'client_form', 'template_is_internal' => 0]) === true,
    'Portal visibility helper should allow client-facing submissions when template_is_internal is provided as 0.'
);
assertFollowUpPortalSource(
    \BdtaFollowUpPortalSourceSandbox\bdta_form_submission_is_client_portal_visible(['form_type' => 'follow_up_note']) === true,
    'Portal visibility helper should still allow follow-up review submissions.'
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
