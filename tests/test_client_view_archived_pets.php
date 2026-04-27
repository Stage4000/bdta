#!/usr/bin/env php
<?php

function bdta_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$clients_view = file_get_contents(dirname(__DIR__) . '/client/clients_view.php');
if (!is_string($clients_view)) {
    fwrite(STDERR, 'Failed to read ' . dirname(__DIR__) . "/client/clients_view.php\n");
    exit(1);
}

bdta_assert_true(
    str_contains($clients_view, 'ORDER BY COALESCE(p.is_active, 1) DESC, p.name'),
    'Expected client profile pets query to sort archived pets after active pets.'
);

bdta_assert_true(
    str_contains($clients_view, "\$pet_is_active = array_int_value(\$pet, 'is_active', 1) === 1;"),
    'Expected client profile pet cards to treat missing archived state as active by default via the shared integer helper.'
);

bdta_assert_true(
    str_contains($clients_view, "\$pet_archived_classes = \$pet_is_active ? '' : ' bg-light rounded px-2 text-muted opacity-75';"),
    'Expected client profile pet cards to derive archived styling from the normalized pet active state.'
);

bdta_assert_true(
    str_contains($clients_view, '<span class="badge bg-secondary ms-1">Archived</span>'),
    'Expected archived pets on the client profile to show an Archived badge.'
);

bdta_assert_true(
    str_contains($clients_view, '<form method="post" action="pets_delete.php" class="d-inline" onsubmit="return confirm(\'Are you sure you want to delete this pet?\')">'),
    'Expected client profile pets to delete through a POST form.'
);

bdta_assert_true(
    str_contains($clients_view, '<input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">'),
    'Expected client profile pet delete forms to include a CSRF token.'
);

$pets_delete = file_get_contents(dirname(__DIR__) . '/client/pets_delete.php');
if (!is_string($pets_delete)) {
    fwrite(STDERR, 'Failed to read ' . dirname(__DIR__) . "/client/pets_delete.php\n");
    exit(1);
}

$pets_list = file_get_contents(dirname(__DIR__) . '/client/pets_list.php');
if (!is_string($pets_list)) {
    fwrite(STDERR, 'Failed to read ' . dirname(__DIR__) . "/client/pets_list.php\n");
    exit(1);
}

bdta_assert_true(
    str_contains($pets_delete, 'if (!isPostRequest()) {'),
    'Expected pet deletion endpoint to reject non-POST requests.'
);

bdta_assert_true(
    str_contains($pets_delete, 'requireValidCsrfToken($redirect_url);'),
    'Expected pet deletion endpoint to validate CSRF tokens.'
);

bdta_assert_true(
    str_contains($pets_list, '<form method="post" action="pets_delete.php" class="d-inline" onsubmit="return confirm(\'Are you sure you want to delete this pet?\')">'),
    'Expected the pets list to use a POST form for desktop delete actions.'
);

bdta_assert_true(
    str_contains($pets_list, '<button type="submit" class="dropdown-item text-danger">'),
    'Expected the pets list mobile delete action to submit a POST form.'
);

bdta_assert_true(
    !str_contains($pets_list, 'pets_delete.php?'),
    'Expected the pets list to stop linking directly to any GET-based pet deletion URL.'
);

echo "Client profile archived pet checks passed.\n";
