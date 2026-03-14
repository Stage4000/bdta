#!/usr/bin/env php
<?php

require_once __DIR__ . '/backend/includes/config.php';
require_once __DIR__ . '/backend/includes/tawk_to.php';

echo "=== Tawk.to Widget Test ===\n\n";

$original_values = [
    'tawk_to_enabled' => scalar_string(Settings::get('tawk_to_enabled', '0')),
    'tawk_to_property_id' => scalar_string(Settings::get('tawk_to_property_id', '')),
    'tawk_to_widget_id' => scalar_string(Settings::get('tawk_to_widget_id', 'default')),
];

try {
    $advanced_settings = array_column(Settings::getCategory('advanced'), null, 'key');
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
}
