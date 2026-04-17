#!/usr/bin/env php
<?php

function assert_dashboard_contains(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$dashboard = file_get_contents(dirname(__DIR__) . '/client/index.php');

if ($dashboard === false) {
    fwrite(STDERR, "Failed to read client/index.php\n");
    exit(1);
}

assert_dashboard_contains(
    str_contains($dashboard, 'dashboard-link'),
    'Admin dashboard cards should use interactive dashboard links.'
);
assert_dashboard_contains(
    str_contains($dashboard, '30-Day Snapshot'),
    'Admin dashboard should expose a 30-day snapshot section.'
);
assert_dashboard_contains(
    str_contains($dashboard, 'Recent Activity'),
    'Admin dashboard should replace recent bookings with recent activity.'
);
assert_dashboard_contains(
    str_contains($dashboard, 'form_submissions_list.php')
    && str_contains($dashboard, 'quotes_list.php?status=accepted')
    && str_contains($dashboard, 'contracts_list.php')
    && str_contains($dashboard, 'invoices_list.php'),
    'Admin dashboard should link to related admin areas from the new cards and shortcuts.'
);

echo "Admin dashboard enhancement checks passed.\n";
