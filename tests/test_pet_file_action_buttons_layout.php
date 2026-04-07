#!/usr/bin/env php
<?php

function bdta_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function bdta_read_file(string $path, string $label): string
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        fwrite(STDERR, sprintf('Test setup failed: unable to read %s', $label) . PHP_EOL);
        exit(1);
    }

    return $contents;
}

$pets_edit = bdta_read_file(dirname(__DIR__) . '/client/pets_edit.php', 'pets_edit.php');

bdta_assert(
    str_contains($pets_edit, '<div class="card-body d-flex flex-column ${isImage ? \'pt-2\' : \'\'}">'),
    'Uploaded file cards should use a flex column body so the action buttons stay anchored cleanly.'
);
bdta_assert(
    str_contains($pets_edit, '<div class="d-grid gap-1 mt-auto">'),
    'Uploaded file action buttons should use a stacked grid layout instead of a shrinking button group.'
);
bdta_assert(
    str_contains($pets_edit, 'class="btn btn-sm btn-outline-primary text-nowrap"'),
    'The View action should keep its label on one line.'
);
bdta_assert(
    str_contains($pets_edit, 'class="btn btn-sm btn-outline-secondary text-nowrap"'),
    'The Download action should keep its label on one line.'
);
bdta_assert(
    str_contains($pets_edit, 'class="btn btn-sm btn-outline-danger text-nowrap"'),
    'The Delete action should keep its label on one line.'
);
bdta_assert(
    !str_contains($pets_edit, '<div class="btn-group btn-group-sm w-100" role="group">'),
    'Uploaded file action buttons should no longer use the shrinking full-width button group layout.'
);

echo "Pet file action button layout checks passed.\n";
