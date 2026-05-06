#!/usr/bin/env php
<?php

function bdta_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$book_page = file_get_contents(dirname(__DIR__) . '/backend/public/book.php');

if ($book_page === false) {
    fwrite(STDERR, "Failed to read backend/public/book.php\n");
    exit(1);
}

bdta_assert(
    str_contains($book_page, 'Session Cost:')
        && str_contains($book_page, "number_format((float) \$selected_type['default_amount'], 2)")
        && str_contains($book_page, 'Free'),
    'Direct-link booking pages should render the selected appointment type cost in the header.'
);

bdta_assert(
    str_contains($book_page, 'id="confirmPrice"')
        && str_contains($book_page, 'let selectedTypePrice =')
        && str_contains($book_page, "document.getElementById('confirmPrice').textContent = formatBookingPrice(selectedTypePrice);"),
    'Public booking confirmation should include the selected appointment type cost.'
);

echo "Public booking direct-link price checks passed.\n";
