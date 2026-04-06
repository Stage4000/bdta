#!/usr/bin/env php
<?php

function bdta_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function bdta_read_file(string $path): string {
    $contents = file_get_contents($path);

    if ($contents === false) {
        fwrite(STDERR, "Test setup failed: unable to read {$path}" . PHP_EOL);
        exit(1);
    }

    return $contents;
}

$clients_view = bdta_read_file(dirname(__DIR__) . '/client/clients_view.php');
$clients_edit = bdta_read_file(dirname(__DIR__) . '/client/clients_edit.php');

$credits_summary_guard = "if (!empty(\$pkg_credits_summary))";
$credits_summary_guard_pos = strpos($clients_view, $credits_summary_guard);
$credits_summary_end_pos = $credits_summary_guard_pos === false
    ? false
    : strpos($clients_view, '<?php endif; ?>', $credits_summary_guard_pos);
$manage_label_pos = strpos($clients_view, '<dt>Packages &amp; Credits:</dt>');
$manage_link_pos = strpos($clients_view, 'credits_manage.php?client_id=<?= $id ?>');

// This is a source-level regression test that verifies the client-view template keeps the
// credit summary conditional while leaving the management link after that conditional ends.
bdta_assert($credits_summary_guard_pos !== false, 'Client view should keep the credit-summary conditional.');
bdta_assert($credits_summary_end_pos !== false, 'Client view should still close the credit-summary conditional.');
bdta_assert($manage_label_pos !== false, 'Client view should label the package and credit management action in the client details list.');
bdta_assert($manage_link_pos !== false, 'Client view should include a link to credit/package management.');
bdta_assert($manage_label_pos < $manage_link_pos, 'Client view should render the package and credit management label before the action link.');
bdta_assert($credits_summary_end_pos < $manage_link_pos, 'Client view should leave the management link outside the credit-summary conditional.');
bdta_assert(str_contains($clients_view, 'Manage Credits &amp; Packages'), 'Client view should advertise package assignment access.');

bdta_assert(str_contains($clients_edit, 'credits_manage.php?client_id=<?= $id ?>'), 'Client edit should include a link to credit/package management.');
bdta_assert(str_contains($clients_edit, 'Manage Credits &amp; Packages'), 'Client edit should use the package-aware management label.');

echo "Client package management entry-point checks passed.\n";
