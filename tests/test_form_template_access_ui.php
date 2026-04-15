#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/form_types.php';

function assertFormTemplateAccessUi(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$client_access = bdta_get_form_template_access_state('client_form', 0);
assertFormTemplateAccessUi($client_access['effective_internal'] === false, 'Client forms should default to client-facing access.');
assertFormTemplateAccessUi($client_access['can_toggle_internal'] === true, 'Client forms should allow toggling internal access.');
assertFormTemplateAccessUi($client_access['label'] === 'Client facing', 'Client-facing templates should show the client-facing access label.');

$internal_client_access = bdta_get_form_template_access_state('client_form', 1);
assertFormTemplateAccessUi($internal_client_access['effective_internal'] === true, 'Client forms should respect the internal-use toggle.');
assertFormTemplateAccessUi($internal_client_access['can_toggle_internal'] === true, 'Internal client forms should still expose a toggle.');
assertFormTemplateAccessUi($internal_client_access['toggle_help'] === 'This template will only be available to admin/staff users.', 'Internal client forms should explain the admin-only toggle state.');

$non_boolean_internal_client_access = bdta_get_form_template_access_state('client_form', 2);
assertFormTemplateAccessUi($non_boolean_internal_client_access['effective_internal'] === true, 'Any non-zero internal flag should be treated as internal access.');
assertFormTemplateAccessUi($non_boolean_internal_client_access['requested_internal'] === true, 'Non-zero internal flags should normalize to requested internal access.');

$forced_internal_access = bdta_get_form_template_access_state('follow_up_note', 0);
assertFormTemplateAccessUi($forced_internal_access['effective_internal'] === true, 'Follow-up note templates should remain internal.');
assertFormTemplateAccessUi($forced_internal_access['can_toggle_internal'] === false, 'Forced-internal form types should not allow the internal-use toggle.');
assertFormTemplateAccessUi($forced_internal_access['toggle_help'] === 'This form type is always internal and cannot be shared with clients.', 'Forced-internal form types should explain why the toggle is locked.');

$edit_page = file_get_contents(dirname(__DIR__) . '/client/form_templates_edit.php');
if (!is_string($edit_page)) {
    throw new RuntimeException('Expected to read the form template edit page source.');
}
assertFormTemplateAccessUi(str_contains($edit_page, 'id="is_internal_toggle"'), 'Expected the form template editor to render an internal-use toggle.');
assertFormTemplateAccessUi(str_contains($edit_page, 'Internal use only'), 'Expected the form template editor to label the internal-use toggle.');

$list_page = file_get_contents(dirname(__DIR__) . '/client/form_templates_list.php');
if (!is_string($list_page)) {
    throw new RuntimeException('Expected to read the form template list page source.');
}
assertFormTemplateAccessUi(str_contains($list_page, '?access=internal'), 'Expected the Internal Forms tab create action to preserve internal access context.');

echo "Form template access UI regression test passed.\n";
