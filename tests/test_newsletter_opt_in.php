#!/usr/bin/env php
<?php

define('BDTA_TEST_MODE', true);

if (!function_exists('array_string_value')) {
    /**
     * @param array<string, mixed> $array
     */
    function array_string_value(array $array, string $key, string $default = ''): string
    {
        $value = $array[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }
}

require_once dirname(__DIR__) . '/backend/includes/database.php';
require_once dirname(__DIR__) . '/backend/includes/settings.php';
require_once dirname(__DIR__) . '/backend/includes/form_types.php';
require_once dirname(__DIR__) . '/backend/includes/mailjet_newsletter.php';

function assertNewsletterOptIn(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

Settings::seedCacheForTesting([
    'mailjet_api_key' => 'test-mailjet-key',
    'mailjet_api_secret' => 'test-mailjet-secret',
    'mailjet_newsletter_list_id' => '123456',
]);

$fields = [
    ['label' => 'Receive updates?', 'type' => bdta_newsletter_opt_in_field_type()],
    ['label' => 'Comments', 'type' => 'textarea'],
];

assertNewsletterOptIn(
    bdta_form_field_is_newsletter_opt_in($fields[0]) === true,
    'Expected newsletter opt-in fields to be detected by type.'
);
assertNewsletterOptIn(
    bdta_form_field_newsletter_normalize_value('1') === bdta_form_field_newsletter_checkbox_label(),
    'Expected truthy newsletter opt-in values to normalize to the fixed checkbox label.'
);
assertNewsletterOptIn(
    bdta_form_fields_include_newsletter_opt_in($fields, ['0' => bdta_form_field_newsletter_checkbox_label()]) === true,
    'Expected newsletter opt-in detection to find opted-in responses.'
);
assertNewsletterOptIn(
    bdta_form_fields_include_newsletter_opt_in($fields, ['0' => '']) === false,
    'Expected newsletter opt-in detection to ignore empty responses.'
);

$test_mailjet_service = new class() extends MailjetNewsletterService {
    /** @var list<array{method: string, url: string, payload: array<string, mixed>|null}> */
    public array $calls = [];

    /**
     * @param array<string, mixed>|null $payload
     * @return array<int|string, mixed>
     */
    protected function requestJson(
        string $method,
        string $url,
        string $api_key,
        string $api_secret,
        ?array $payload = null
    ): array {
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'payload' => $payload,
        ];

        return ['Data' => []];
    }
};

$subscribe_result = $test_mailjet_service->subscribeContact('newsletter@example.com', 'Newsletter Test');
assertNewsletterOptIn($subscribe_result['success'] === true, 'Expected Mailjet newsletter subscriptions to succeed when requests succeed.');
assertNewsletterOptIn(count($test_mailjet_service->calls) === 2, 'Expected Mailjet newsletter subscriptions to issue two API requests.');
assertNewsletterOptIn(
    str_contains($test_mailjet_service->calls[0]['url'], '/contact/managemanycontacts'),
    'Expected the first Mailjet call to manage the contact without failing on duplicates.'
);
$first_contact_email = '';
$first_contacts_payload = $test_mailjet_service->calls[0]['payload']['Contacts'] ?? null;
if (
    is_array($first_contacts_payload)
    && isset($first_contacts_payload[0])
    && is_array($first_contacts_payload[0])
) {
    $first_contact_email = array_string_value($first_contacts_payload[0], 'Email');
}

assertNewsletterOptIn(
    ($first_contact_email === 'newsletter@example.com'),
    'Expected the first Mailjet call to pass the contact email in the manage-many-contacts payload.'
);
assertNewsletterOptIn(
    str_contains($test_mailjet_service->calls[1]['url'], '/contactslist/123456/managecontact'),
    'Expected the second Mailjet call to subscribe the contact to the configured list.'
);
assertNewsletterOptIn(
    ($test_mailjet_service->calls[1]['payload']['Action'] ?? '') === 'addforce',
    'Expected Mailjet list subscriptions to use addforce.'
);

$mailjet_helper_source = file_get_contents(dirname(__DIR__) . '/backend/includes/mailjet_newsletter.php');
if (!is_string($mailjet_helper_source)) {
    throw new RuntimeException('Expected to read the Mailjet newsletter helper source.');
}

assertNewsletterOptIn(
    !str_contains($mailjet_helper_source, 'form_types.php'),
    'Expected the Mailjet newsletter helper to avoid unused form type includes.'
);

$edit_page = file_get_contents(dirname(__DIR__) . '/client/form_templates_edit.php');
if (!is_string($edit_page)) {
    throw new RuntimeException('Expected to read the form template edit page source.');
}

assertNewsletterOptIn(
    str_contains($edit_page, 'Newsletter Opt-In'),
    'Expected the form template editor to offer the Newsletter Opt-In field type.'
);
assertNewsletterOptIn(
    str_contains($edit_page, 'Mailjet Newsletter List ID'),
    'Expected the form template editor to point admins to the Mailjet newsletter list setting.'
);

$settings_source = file_get_contents(dirname(__DIR__) . '/backend/includes/database.php');
if (!is_string($settings_source)) {
    throw new RuntimeException('Expected to read the database settings source.');
}

assertNewsletterOptIn(
    str_contains($settings_source, 'mailjet_newsletter_list_id'),
    'Expected Mailjet newsletter settings to be seeded for existing installations.'
);

$public_booking_source = file_get_contents(dirname(__DIR__) . '/backend/public/api_bookings.php');
if (!is_string($public_booking_source)) {
    throw new RuntimeException('Expected to read the public booking API source.');
}

assertNewsletterOptIn(
    str_contains($public_booking_source, 'SELECT id, fields FROM form_templates WHERE id IN'),
    'Expected public booking newsletter detection to preload template fields in one query.'
);

$portal_booking_source = file_get_contents(dirname(__DIR__) . '/portal/api_book_credit.php');
if (!is_string($portal_booking_source)) {
    throw new RuntimeException('Expected to read the portal booking API source.');
}

assertNewsletterOptIn(
    str_contains($portal_booking_source, 'SELECT id, fields FROM form_templates WHERE id IN'),
    'Expected portal credit booking newsletter detection to preload template fields in one query.'
);

echo "Newsletter opt-in regression checks passed.\n";
