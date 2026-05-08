#!/usr/bin/env php
<?php

function bdta_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function bdta_contract_read(string $path, string $label): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, sprintf('Test setup failed: unable to read %s', $label) . PHP_EOL);
        exit(1);
    }

    return $contents;
}

require_once dirname(__DIR__) . '/backend/includes/contract_delivery.php';

$contract = [
    'client_name' => 'Taylor Example',
    'title' => 'Training Agreement',
    'contract_number' => 'CON-20260508-0001',
];
$contract_link = 'https://example.test/backend/public/contract.php?token=abc123';
$email = bdta_build_contract_delivery_email($contract, $contract_link);

bdta_contract_assert(
    str_contains($email['subject'], 'Training Agreement'),
    'Contract delivery subject should include the contract title.'
);
bdta_contract_assert(
    str_contains($email['html_body'], 'Taylor Example') && str_contains($email['html_body'], $contract_link),
    'Contract delivery HTML body should include the client name and signing link.'
);
bdta_contract_assert(
    str_contains($email['text_body'], 'CON-20260508-0001') && str_contains($email['text_body'], $contract_link),
    'Contract delivery text body should include the contract number and signing link.'
);

$contracts_create = bdta_contract_read(dirname(__DIR__) . '/client/contracts_create.php', 'contracts_create.php');
$contracts_view = bdta_contract_read(dirname(__DIR__) . '/client/contracts_view.php', 'contracts_view.php');
$public_contract = bdta_contract_read(dirname(__DIR__) . '/backend/public/contract.php', 'contract.php');

bdta_contract_assert(
    str_contains($contracts_create, 'bdta_send_contract_to_client('),
    'Contract creation should call the shared contract delivery helper.'
);
bdta_contract_assert(
    str_contains($contracts_view, 'resend_contract') && str_contains($contracts_view, 'bdta_send_contract_to_client('),
    'Contract view should expose a resend/send action backed by the shared delivery helper.'
);
bdta_contract_assert(
    str_contains($public_contract, "status               = 'signed'")
        && str_contains($public_contract, 'signature_typed_name = ?')
        && str_contains($public_contract, 'signature_font       = ?'),
    'Public contract signing should persist signed status plus typed-signature fields.'
);

echo "Contract delivery regression checks passed.\n";
