#!/usr/bin/env php
<?php

echo "=== Portal Forms Visibility Tests ===\n\n";

try {
    $agreements_php = file_get_contents(__DIR__ . '/portal/agreements.php');
    $header_php = file_get_contents(__DIR__ . '/portal/includes/header.php');
    $form_view_php = file_get_contents(__DIR__ . '/portal/form_view.php');
    if ($agreements_php === false || $header_php === false || $form_view_php === false) {
        throw new RuntimeException('Unable to read portal PHP files for assertions.');
    }

    echo "Test 1: Agreements query excludes internal-only forms\n";
    if (strpos($agreements_php, 'COALESCE(ft.is_internal, 0) = 0') !== false) {
        echo "  ✓ Internal-only forms are filtered from the client portal list\n";
    } else {
        echo "  ✗ agreements.php is missing internal form filtering\n";
        exit(1);
    }

    echo "\nTest 2: Agreements list links to a form details page\n";
    if (strpos($agreements_php, 'form_view.php?id=') !== false) {
        echo "  ✓ Form submissions in portal include a view link\n";
    } else {
        echo "  ✗ agreements.php is missing form view links\n";
        exit(1);
    }

    echo "\nTest 3: Navigation exposes Agreements & Forms label\n";
    if (strpos($header_php, 'Agreements &amp; Forms') !== false) {
        echo "  ✓ Portal navigation includes Agreements & Forms\n";
    } else {
        echo "  ✗ Portal navigation label was not updated\n";
        exit(1);
    }

    echo "\nTest 4: Form view endpoint enforces ownership and internal filter\n";
    if (
        strpos($form_view_php, 'AND fs.client_id = ?') !== false
        && strpos($form_view_php, 'COALESCE(ft.is_internal, 0) = 0') !== false
    ) {
        echo "  ✓ form_view.php restricts access to client-owned, non-internal submissions\n";
    } else {
        echo "  ✗ form_view.php is missing access restrictions\n";
        exit(1);
    }

    echo "\n=== ALL PORTAL FORMS VISIBILITY TESTS PASSED! ===\n";
} catch (Throwable $e) {
    echo "✗ TEST FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
