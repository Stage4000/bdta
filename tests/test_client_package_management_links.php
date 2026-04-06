#!/usr/bin/env php
<?php

function bdta_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$clients_view = file_get_contents(dirname(__DIR__) . '/client/clients_view.php');
$clients_edit = file_get_contents(dirname(__DIR__) . '/client/clients_edit.php');

bdta_assert($clients_view !== false, 'Failed to read client/clients_view.php');
bdta_assert($clients_edit !== false, 'Failed to read client/clients_edit.php');

// This is a source-level regression test that verifies the client-view template keeps the
// credit summary conditional while leaving the management link outside that conditional block.
bdta_assert(
    preg_match(
        '/if \(!empty\(\$pkg_credits_summary\)\):.*?endif;.*?credits_manage\.php\?client_id=<\?= \$id \?>/s',
        $clients_view
    ) === 1,
    'Client view should keep the credit summary conditional while leaving the management link available for every client.'
);
bdta_assert(str_contains($clients_view, 'credits_manage.php?client_id=<?= $id ?>'), 'Client view should include a link to credit/package management.');
bdta_assert(str_contains($clients_view, 'Manage Credits &amp; Packages'), 'Client view should advertise package assignment access.');

bdta_assert(str_contains($clients_edit, 'credits_manage.php?client_id=<?= $id ?>'), 'Client edit should include a link to credit/package management.');
bdta_assert(str_contains($clients_edit, 'Manage Credits &amp; Packages'), 'Client edit should use the package-aware management label.');

echo "Client package management entry-point checks passed.\n";
