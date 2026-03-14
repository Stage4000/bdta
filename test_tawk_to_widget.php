#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/backend/includes/config.php';
require_once __DIR__ . '/backend/includes/tawk_to.php';

echo "=== Tawk.to Widget Test ===\n\n";

$advanced_settings = array_column(Settings::getCategory('advanced'), null, 'key');
$original_values = [
    'tawk_to_enabled' => scalar_string($advanced_settings['tawk_to_enabled']['actual_value'] ?? '0'),
    'tawk_to_property_id' => scalar_string($advanced_settings['tawk_to_property_id']['actual_value'] ?? ''),
    'tawk_to_widget_id' => scalar_string($advanced_settings['tawk_to_widget_id']['actual_value'] ?? 'default'),
];
$original_admin_id = $_SESSION['admin_id'] ?? null;
$original_impersonation = $_SESSION['portal_impersonating_admin_id'] ?? null;

try {
    foreach (['tawk_to_enabled', 'tawk_to_property_id', 'tawk_to_widget_id'] as $key) {
        if (!isset($advanced_settings[$key])) {
            throw new RuntimeException('Missing advanced setting: ' . $key);
        }
    }
    echo "✓ Advanced settings include Tawk.to fields\n";

    Settings::set('tawk_to_enabled', '1');
    Settings::set('tawk_to_property_id', '0123456789abcdef01234567');
    Settings::set('tawk_to_widget_id', '');

    $script = bdta_get_tawk_to_widget_script();
    if (!str_contains($script, 'https://embed.tawk.to/0123456789abcdef01234567/default')) {
        throw new RuntimeException('Widget script did not include the expected default embed URL.');
    }
    echo "✓ Enabled widget renders the expected embed URL\n";

    Settings::set('tawk_to_enabled', '0');
    if (bdta_get_tawk_to_widget_script() !== '') {
        throw new RuntimeException('Disabled widget should not render any script.');
    }
    echo "✓ Disabled widget renders nothing\n";

    Settings::set('tawk_to_enabled', '1');
    Settings::set('tawk_to_property_id', 'invalid/property');
    Settings::set('tawk_to_widget_id', '../../bad-widget');
    if (bdta_get_tawk_to_widget_script() !== '') {
        throw new RuntimeException('Invalid Tawk.to identifiers should not render any script.');
    }
    echo "✓ Invalid identifiers are rejected safely\n";

    Settings::set('tawk_to_property_id', '0123456789abcdef01234567');
    Settings::set('tawk_to_widget_id', 'default');
    $_SESSION['admin_id'] = 1;
    if (bdta_get_tawk_to_widget_script() !== '') {
        throw new RuntimeException('Authenticated admin sessions should not receive the widget.');
    }
    echo "✓ Authenticated admin sessions suppress the widget\n";

    unset($_SESSION['admin_id']);
    $_SESSION['portal_impersonating_admin_id'] = 1;
    if (bdta_get_tawk_to_widget_script() !== '') {
        throw new RuntimeException('Impersonating admins should not receive the widget on portal pages.');
    }
    echo "✓ Admin impersonation suppresses the portal widget\n";

    echo "\n=== All Tests Passed! ===\n";
} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . "\n";
    echo "  Line: " . $e->getLine() . "\n";
    exit(1);
} finally {
    foreach ($original_values as $key => $value) {
        Settings::set($key, $value);
    }
    if ($original_admin_id === null) {
        unset($_SESSION['admin_id']);
    } else {
        $_SESSION['admin_id'] = $original_admin_id;
    }
    if ($original_impersonation === null) {
        unset($_SESSION['portal_impersonating_admin_id']);
    } else {
        $_SESSION['portal_impersonating_admin_id'] = $original_impersonation;
    }
}
