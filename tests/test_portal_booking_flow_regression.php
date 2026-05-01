#!/usr/bin/env php
<?php

function bdta_assert_portal_booking(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function bdta_read_portal_booking_source(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, 'Unable to read source file: ' . $path . PHP_EOL);
        exit(1);
    }

    return $contents;
}

function bdta_assert_portal_credit_ordering(string $source, string $message): void
{
    $pattern = '/ORDER\s+BY\s*\(cp\.expires_at IS NULL\)\s*ASC,\s*cp\.expires_at\s*ASC/s';
    bdta_assert_portal_booking((bool) preg_match($pattern, $source), $message);
}

$book_page = bdta_read_portal_booking_source(dirname(__DIR__) . '/portal/book_credit.php');
$api_page = bdta_read_portal_booking_source(dirname(__DIR__) . '/portal/api_book_credit.php');

bdta_assert_portal_booking(
    str_contains($book_page, '$has_available_credit = $available_credit_id > 0;'),
    'Portal booking page should detect whether the logged-in client has an available credit for the selected appointment type.'
);
bdta_assert_portal_credit_ordering(
    $book_page,
    'Portal booking page should prefer consuming the soonest-expiring eligible credit first.'
);
bdta_assert_portal_booking(
    str_contains($book_page, "This appointment type is not currently available to book from the portal."),
    'Portal booking page should block appointment types that are neither portal-available nor credit-backed.'
);
bdta_assert_portal_booking(
    str_contains($book_page, 'const hasAvailableCredit = <?= json_encode($has_available_credit) ?>;'),
    'Portal booking page should expose credit availability to the booking UI.'
);
bdta_assert_portal_booking(
    str_contains($book_page, 'data-pet-info-config'),
    'Portal booking page should render Pet Info Group fields with their per-field configuration.'
);
bdta_assert_portal_booking(
    str_contains($book_page, 'fields[group.dataset.formField] = collectPetInfoGroupResponse(group);') &&
    str_contains($book_page, 'getPetInfoGroupPetNames'),
    'Portal booking page should collect dynamic Pet Info Group responses and derive legacy pet-name fallbacks from them.'
);
bdta_assert_portal_booking(
    str_contains($api_page, '$portal_available = array_int_value($apt_type, \'portal_available\') === 1;'),
    'Portal booking API should allow standard portal booking types without requiring a package credit.'
);
bdta_assert_portal_credit_ordering(
    $api_page,
    'Portal booking API should deterministically select the soonest-expiring eligible credit.'
);
bdta_assert_portal_booking(
    str_contains($api_page, 'if (!$portal_available && !$has_available_credit) {'),
    'Portal booking API should reject appointment types that are unavailable in the portal and have no credit access.'
);
bdta_assert_portal_booking(
    str_contains($api_page, '$credit_applied = $pkg_credit_id !== null && !$is_pending_request;') &&
    str_contains($api_page, '$pending_credit_requested = $pkg_credit_id !== null && $is_pending_request;'),
    'Portal booking API should only report package credit usage when a credit actually exists.'
);
bdta_assert_portal_booking(
    str_contains($api_page, 'bdta_form_field_pet_info_group_profile_values'),
    'Portal booking API should translate submitted Pet Info Group responses into pet profile updates.'
);

echo "Portal booking flow regression checks passed.\n";
