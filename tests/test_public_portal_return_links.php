#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/public_portal_return.php';

function bdta_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function bdta_read(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, 'Unable to read test fixture: ' . $path . PHP_EOL);
        exit(1);
    }

    return $contents;
}

$original_get = $_GET;

try {
    bdta_assert(
        bdta_append_public_portal_return('/backend/public/quote.php?token=quote-token', '/portal/quotes.php')
            === '/backend/public/quote.php?token=quote-token&portal_return=%2Fportal%2Fquotes.php',
        'Quote URLs should preserve the access token and append the portal return path.'
    );
    bdta_assert(
        bdta_append_public_portal_return('/backend/public/contract.php?token=abc123', '/portal/agreements.php')
            === '/backend/public/contract.php?token=abc123&portal_return=%2Fportal%2Fagreements.php',
        'Contract token URLs should preserve the token and append the portal return path.'
    );
    bdta_assert(
        bdta_append_public_portal_return('/backend/public/book.php?type=2', 'https://example.com/bad')
            === '/backend/public/book.php?type=2',
        'External return destinations must be rejected.'
    );
    bdta_assert(
        bdta_append_public_portal_return('/backend/public/book.php?type=2', '/portal/../client/index.php')
            === '/backend/public/book.php?type=2',
        'Portal return paths with traversal segments must be rejected.'
    );

    $_GET['portal_return'] = '/portal/appointments.php';
    bdta_assert(
        bdta_public_portal_return_path() === '/portal/appointments.php',
        'Portal return path should be readable from the current request.'
    );

    $_GET['portal_return'] = '/client/index.php';
    bdta_assert(
        bdta_public_portal_return_path() === '',
        'Non-portal return paths should not be accepted.'
    );

    $_GET['portal_return'] = '/portal/%2E%2E/client/index.php';
    bdta_assert(
        bdta_public_portal_return_path() === '',
        'Encoded traversal segments should not be accepted.'
    );

    $_GET['return_to'] = '/backend/public/form.php?template_id=7';
    bdta_assert(
        bdta_public_login_return_path() === '/backend/public/form.php?template_id=7',
        'Portal login should accept same-site public return paths.'
    );
    bdta_assert(
        bdta_public_portal_login_url('/client/package_detail.php?token=abc') === '/portal/login.php?return_to=%2Fclient%2Fpackage_detail.php%3Ftoken%3Dabc',
        'Portal login URLs should preserve the current public page as a return target.'
    );

    $contract_page = bdta_read(dirname(__DIR__) . '/backend/public/contract.php');
    $quote_page = bdta_read(dirname(__DIR__) . '/backend/public/quote.php');
    $book_page = bdta_read(dirname(__DIR__) . '/backend/public/book.php');
    $form_page = bdta_read(dirname(__DIR__) . '/backend/public/form.php');
    $agreements_page = bdta_read(dirname(__DIR__) . '/portal/agreements.php');
    $appointments_page = bdta_read(dirname(__DIR__) . '/portal/appointments.php');
    $portal_login_page = bdta_read(dirname(__DIR__) . '/portal/login.php');
    $package_page = bdta_read(dirname(__DIR__) . '/client/package_detail.php');

    bdta_assert(
        str_contains($contract_page, 'Back to Client Portal'),
        'Public contract page should render a client portal return action.'
    );
    bdta_assert(
        str_contains($quote_page, 'Back to Client Portal'),
        'Public quote page should render a client portal return action.'
    );
    bdta_assert(
        str_contains($book_page, 'Back to Client Portal'),
        'Public booking page should render a client portal return action.'
    );
    bdta_assert(
        str_contains($book_page, "escape(\$portal_return !== '' ? \$portal_return : '/')"),
        'Public booking success modal should switch its destination between the portal and home.'
    );
    bdta_assert(
        str_contains($book_page, "'Back to Client Portal' : 'Back to Home'"),
        'Public booking success modal should switch its label between the portal and home.'
    );
    bdta_assert(
        str_contains($form_page, 'Already a client with us?'),
        'Public form pages should render the client login shortcut copy for pet info groups.'
    );
    bdta_assert(
        str_contains($portal_login_page, "redirect(\$return_to !== '' ? \$return_to : PORTAL_URL . 'index.php');"),
        'Portal login should redirect back to the originating public page when a return target is provided.'
    );
    bdta_assert(
        str_contains($package_page, '$portal_login_url'),
        'Public package detail pages should preserve the current package page when linking to portal login.'
    );
    bdta_assert(
        str_contains($agreements_page, "bdta_append_public_portal_return("),
        'Portal agreements page should add a portal return path to public contract links.'
    );
    bdta_assert(
        str_contains($appointments_page, '/portal/book_credit.php?type=') || str_contains($appointments_page, '/portal/book_credit.php?link='),
        'Portal appointments page should send Book Now actions through the authenticated portal booking flow.'
    );

    echo "Public portal return link checks passed.\n";
} finally {
    $_GET = $original_get;
}
