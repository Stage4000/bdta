#!/usr/bin/env php
<?php

function bdta_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$appointments = file_get_contents(dirname(__DIR__) . '/portal/appointments.php');

if ($appointments === false) {
    fwrite(STDERR, "Test setup failed: unable to read portal/appointments.php\n");
    exit(1);
}

bdta_assert(
    str_contains($appointments, 'class="btn btn-sm btn-primary d-inline-flex align-items-center justify-content-center"'),
    'Book Now links should use inline flex centering utilities so the label stays centered inside the button.'
);
bdta_assert(
    str_contains($appointments, "/portal/book_credit.php?type=") ||
    str_contains($appointments, "/portal/book_credit.php?link="),
    'Portal appointments Book Now links should route into the authenticated portal booking flow.'
);
bdta_assert(
    !str_contains($appointments, '/backend/public/book.php'),
    'Portal appointments Book Now links should no longer send clients to the public booking page.'
);

echo "Portal appointments Book Now button alignment checks passed.\n";
