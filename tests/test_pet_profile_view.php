#!/usr/bin/env php
<?php

function bdta_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function bdta_read_file(string $relative_path): string
{
    $contents = file_get_contents(dirname(__DIR__) . '/' . $relative_path);
    if (!is_string($contents)) {
        fwrite(STDERR, 'Failed to read ' . $relative_path . PHP_EOL);
        exit(1);
    }

    return $contents;
}

$pets_view = bdta_read_file('client/pets_view.php');
bdta_assert_true(
    str_contains($pets_view, '<i class="fas fa-pencil me-1"></i>Edit Pet'),
    'Expected the pet profile view to provide an explicit Edit Pet button.'
);
bdta_assert_true(
    str_contains($pets_view, '<h5 class="card-title mb-3">Pet Sitting Notes</h5>'),
    'Expected the pet profile view to render pet sitting notes read-only.'
);
bdta_assert_true(
    str_contains($pets_view, 'pet_files_view.php?id=<?= (int) array_int_value($file, \'id\') ?>&download=1'),
    'Expected the pet profile view to offer read-only file downloads.'
);
bdta_assert_true(
    str_contains($pets_view, 'target="_blank" rel="noopener noreferrer"'),
    'Expected pet profile file view links that open in a new tab to use rel="noopener noreferrer".'
);
bdta_assert_true(
    !str_contains($pets_view, 'COUNT(pf.id) AS file_count'),
    'Expected the pet profile query to avoid an unused file count aggregate.'
);

$pets_list = bdta_read_file('client/pets_list.php');
bdta_assert_true(
    str_contains($pets_list, 'pets_view.php?id=<?= (int) $pet[\'id\'] ?>'),
    'Expected the pets list pet names to link to the new read-only pet profile view.'
);
bdta_assert_true(
    str_contains($pets_list, '<i class="fas fa-eye me-2 text-info"></i>View'),
    'Expected the pets list mobile actions to offer a View action.'
);
bdta_assert_true(
    str_contains($pets_list, 'aria-label="View <?= htmlspecialchars($pet[\'name\']) ?>"'),
    'Expected the pets list icon-only view action to include an accessible label.'
);

$clients_view = bdta_read_file('client/clients_view.php');
bdta_assert_true(
    str_contains($clients_view, 'pets_view.php?id=<?= (int) $pet[\'id\'] ?>'),
    'Expected client profile pet names to open the new read-only pet profile view.'
);
bdta_assert_true(
    str_contains($clients_view, '<i class="fas fa-eye"></i> View'),
    'Expected client profile pet actions to include a View button.'
);

$form_requests_create = bdta_read_file('client/form_requests_create.php');
bdta_assert_true(
    str_contains($form_requests_create, '$back_link = \'pets_view.php?id=\' . array_int_value($pet, \'id\');'),
    'Expected pet form requests to return to the read-only pet profile view.'
);

$pets_edit = bdta_read_file('client/pets_edit.php');
bdta_assert_true(
    str_contains($pets_edit, '<a href="<?= htmlspecialchars($return_url) ?>" class="btn btn-secondary">'),
    'Expected pet edit cancel actions to return to the originating page, including the new pet profile view.'
);
bdta_assert_true(
    str_contains($pets_edit, "requestMethodIs('POST')"),
    'Expected pet edit pages to preserve the original return target across failed POST submissions.'
);

echo "Pet profile view checks passed.\n";
