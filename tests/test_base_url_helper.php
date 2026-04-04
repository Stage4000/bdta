#!/usr/bin/env php
<?php

define('DEFAULT_LOCALHOST_URL', 'http://localhost:8000');

require_once dirname(__DIR__) . '/backend/includes/base_url_helper.php';

function assertBaseUrlHelperTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    assertBaseUrlHelperTest(
        bdta_normalize_base_url('example.com///') === 'https://example.com',
        'Expected missing schemes to normalize to https.'
    );
    assertBaseUrlHelperTest(
        bdta_normalize_base_url('https://example.com/path/to/page?x=1') === 'https://example.com',
        'Expected paths and query strings to be removed from normalized base URLs.'
    );
    assertBaseUrlHelperTest(
        bdta_normalize_base_url('http://localhost:8000') === 'http://localhost:8000',
        'Expected localhost base URLs to remain intact when normalized.'
    );
    assertBaseUrlHelperTest(
        bdta_is_default_localhost_base_url('http://localhost:8000/') === true,
        'Expected the seeded localhost base URL to be detected as the default placeholder.'
    );
    assertBaseUrlHelperTest(
        bdta_is_default_localhost_base_url('http://localhost:8080') === false,
        'Expected non-default localhost ports not to be treated as the seeded placeholder.'
    );
    assertBaseUrlHelperTest(
        bdta_guess_base_url_from_email('bookings@brooksdogtrainingacademy.com') === 'https://brooksdogtrainingacademy.com',
        'Expected business email domains to produce an https base URL fallback.'
    );
    assertBaseUrlHelperTest(
        bdta_guess_base_url_from_email('admin@localhost') === '',
        'Expected localhost email domains to be ignored for public base URL fallback.'
    );

    echo "Base URL helper tests passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
