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

$original_get = $_GET ?? [];

try {
    bdta_assert(
        bdta_append_public_portal_return('/backend/public/quote.php?id=5', '/portal/quotes.php')
            === '/backend/public/quote.php?id=5&portal_return=%2Fportal%2Fquotes.php',
        'Quote URLs should preserve the quote ID and append the portal return path.'
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

    $contract_page = bdta_read(dirname(__DIR__) . '/backend/public/contract.php');
    $quote_page = bdta_read(dirname(__DIR__) . '/backend/public/quote.php');
    $book_page = bdta_read(dirname(__DIR__) . '/backend/public/book.php');
    $agreements_page = bdta_read(dirname(__DIR__) . '/portal/agreements.php');
    $appointments_page = bdta_read(dirname(__DIR__) . '/portal/appointments.php');

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
        str_contains($agreements_page, "bdta_append_public_portal_return("),
        'Portal agreements page should add a portal return path to public contract links.'
    );
    bdta_assert(
        str_contains($appointments_page, "bdta_append_public_portal_return(\$book_url, PORTAL_URL . 'appointments.php')"),
        'Portal appointments page should add a portal return path to public booking links.'
    );

    echo "Public portal return link checks passed.\n";
} finally {
    $_GET = $original_get;
}
