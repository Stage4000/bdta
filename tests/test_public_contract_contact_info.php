#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/public/includes/public_contract_access.php';
require_once dirname(__DIR__) . '/backend/public/includes/public_contract_contact_info.php';

function bdta_assert_true(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $full_html = bdta_render_contract_client_contact_info([
        'client_name' => 'Jane Client',
        'client_email' => 'jane@example.com',
        'client_phone' => '555-3300',
        'client_address' => "123 Training Lane\nUnit 4",
    ]);

    bdta_assert_true(str_contains($full_html, '<strong>For:</strong> Jane Client<br>'), 'Expected client name to be rendered.');
    bdta_assert_true(str_contains($full_html, '<strong>Email:</strong> jane@example.com<br>'), 'Expected client email to be rendered.');
    bdta_assert_true(str_contains($full_html, '<strong>Phone:</strong> 555-3300<br>'), 'Expected client phone to be rendered.');
    bdta_assert_true(str_contains($full_html, '<strong>Address:</strong><br>'), 'Expected address label to be rendered when present.');
    bdta_assert_true(str_contains($full_html, '123 Training Lane'), 'Expected first line of address to be rendered.');
    bdta_assert_true(str_contains($full_html, 'Unit 4'), 'Expected second line of address to be rendered.');
    $expected_address_html = nl2br(htmlspecialchars("123 Training Lane\nUnit 4", ENT_QUOTES, 'UTF-8'));
    bdta_assert_true(
        str_contains($full_html, $expected_address_html),
        'Expected address newlines to be converted into HTML line breaks.'
    );

    $public_html = bdta_render_contract_client_contact_info([
        'client_name' => 'Jane Client',
        'client_email' => 'jane@example.com',
        'client_phone' => '555-3300',
        'client_address' => "123 Training Lane\nUnit 4",
    ], false);
    bdta_assert_true(str_contains($public_html, '<strong>For:</strong> Jane Client<br>'), 'Expected public contact block to keep the client name.');
    bdta_assert_true(!str_contains($public_html, '<strong>Email:</strong>'), 'Expected public contact block to hide email without an authorized token.');
    bdta_assert_true(!str_contains($public_html, '<strong>Phone:</strong>'), 'Expected public contact block to hide phone without an authorized token.');
    bdta_assert_true(!str_contains($public_html, '<strong>Address:</strong>'), 'Expected public contact block to hide address without an authorized token.');

    $minimal_html = bdta_render_contract_client_contact_info([
        'client_name' => 'Only Name',
        'client_email' => '',
        'client_phone' => '',
        'client_address' => '',
    ]);

    bdta_assert_true(str_contains($minimal_html, '<strong>For:</strong> Only Name<br>'), 'Expected client name to always be rendered.');
    bdta_assert_true(!str_contains($minimal_html, '<strong>Email:</strong>'), 'Expected blank email to be omitted.');
    bdta_assert_true(!str_contains($minimal_html, '<strong>Phone:</strong>'), 'Expected blank phone to be omitted.');
    bdta_assert_true(!str_contains($minimal_html, '<strong>Address:</strong>'), 'Expected blank address to be omitted.');

    $fallback_html = bdta_render_contract_client_contact_info([
        'client_name' => '   ',
    ]);
    bdta_assert_true(str_contains($fallback_html, '<strong>For:</strong> Client<br>'), 'Expected a safe fallback label when the client name is blank.');

    $access_token = bdta_generate_contract_access_token();
    bdta_assert_true(
        strlen($access_token) === BDTA_CONTRACT_ACCESS_TOKEN_LENGTH,
        'Expected generated contract access tokens to be the configured hex length.'
    );
    bdta_assert_true(ctype_xdigit($access_token), 'Expected generated contract access tokens to be hexadecimal.');
    bdta_assert_true(
        bdta_contract_has_valid_access_token(['access_token' => $access_token], $access_token),
        'Expected matching contract access tokens to be authorized.'
    );
    bdta_assert_true(
        !bdta_contract_has_valid_access_token(['access_token' => $access_token], $access_token . 'x'),
        'Expected mismatched contract access tokens to be rejected.'
    );
    $access_state = bdta_get_contract_access_state(['id' => 42, 'access_token' => $access_token], $access_token);
    bdta_assert_true($access_state['contract_id'] === 42, 'Expected contract access state to report the contract id.');
    bdta_assert_true($access_state['can_view_private_contact_details'] === true, 'Expected contract access state to authorize matching tokens.');

    echo "Public contract contact info test passed.\n";
} catch (Throwable $e) {
    echo "\n✗ TEST FAILED\n";
    echo $e->getMessage() . "\n";
    exit(1);
}
