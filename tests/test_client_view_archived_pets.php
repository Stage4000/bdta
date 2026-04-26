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
    fwrite(STDERR, "Failed to read client/clients_view.php\n");
    exit(1);
}

bdta_assert_true(
    str_contains($clients_view, 'ORDER BY COALESCE(p.is_active, 1) DESC, p.name'),
    'Expected client profile pets query to sort archived pets after active pets.'
);

bdta_assert_true(
    str_contains($clients_view, "<?php \$pet_is_active = !empty(\$pet['is_active']); ?>"),
    'Expected client profile pet cards to detect archived pet state.'
);

bdta_assert_true(
    str_contains($clients_view, "<span class=\"badge bg-secondary ms-1\">Archived</span>"),
    'Expected archived pets on the client profile to show an Archived badge.'
);

bdta_assert_true(
    str_contains($clients_view, "bg-light rounded px-2 text-muted opacity-75"),
    'Expected archived pets on the client profile to use muted archived styling.'
);

bdta_assert_true(
    str_contains($clients_view, "pets_delete.php?id=<?= (int) \$pet['id'] ?>&client_id=<?= \$id ?>"),
    'Expected client profile pets to expose a delete action that returns to the client profile.'
);

echo "Client profile archived pet checks passed.\n";
