#!/usr/bin/env php
<?php

function bdta_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$bookingCreate = file_get_contents(dirname(__DIR__) . '/client/bookings_create.php');

if ($bookingCreate === false) {
    fwrite(STDERR, "Test setup failed: unable to read client/bookings_create.php\n");
    exit(1);
}

bdta_assert(
    str_contains($bookingCreate, "\$_GET['client_id'] ?? 0") &&
    str_contains($bookingCreate, "\$_POST['client_id'] ?? 0"),
    'Booking creation should honor a linked client_id query parameter.'
);
bdta_assert(
    str_contains($bookingCreate, "data-searchable-select=\"client\"") &&
    str_contains($bookingCreate, "data-search-placeholder=\"Search clients...\""),
    'Booking creation client selection should use the shared searchable select UI.'
);
bdta_assert(
    str_contains($bookingCreate, "\$selected_client_id === safe_int(\$client['id']) ? ' selected' : ''"),
    'Booking creation should mark the requested client as selected in the dropdown.'
);
bdta_assert(
    str_contains($bookingCreate, "clientSelect.dispatchEvent(new Event('change'));"),
    'Booking creation should initialize dependent client UI when a client is preselected.'
);

echo "Admin booking client selection checks passed.\n";
