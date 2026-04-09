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

echo "Portal appointments Book Now button alignment checks passed.\n";
