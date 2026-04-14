#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

define('BDTA_TEST_MODE', true);

require_once dirname(__DIR__) . '/backend/includes/settings.php';
require_once dirname(__DIR__) . '/backend/includes/public_notice.php';

function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

echo "=== Public Notice Helper Test ===\n\n";

$database_source = file_get_contents(dirname(__DIR__) . '/backend/includes/database.php');
if (!is_string($database_source)) {
    throw new RuntimeException('Unable to read backend/includes/database.php');
}

try {
    assertTrue(str_contains($database_source, "'public_notice_enabled'"), 'Expected database defaults to seed public_notice_enabled.');
    assertTrue(str_contains($database_source, "'public_notice_text'"), 'Expected database defaults to seed public_notice_text.');
    echo "✓ Database settings defaults include public notice fields\n";

    Settings::seedCacheForTesting([
        'public_notice_enabled' => false,
        'public_notice_text' => 'Scheduled maintenance tonight.',
    ]);
    assertTrue(bdta_get_public_notice_markup() === '', 'Disabled public notice should not render markup.');
    echo "✓ Disabled notice renders nothing\n";

    Settings::seedCacheForTesting([
        'public_notice_enabled' => true,
        'public_notice_text' => '',
    ]);
    assertTrue(bdta_get_public_notice_markup() === '', 'Empty public notice text should not render markup.');
    echo "✓ Empty notice text renders nothing\n";

    Settings::seedCacheForTesting([
        'public_notice_enabled' => true,
        'public_notice_text' => "Line 1\n<script>alert(1)</script>",
    ]);
    $markup = bdta_get_public_notice_markup();
    assertTrue(str_contains($markup, 'data-public-notice'), 'Enabled notice should render its container markup.');
    assertTrue(str_contains($markup, 'Line 1<br'), 'Notice markup should preserve line breaks.');
    assertTrue(str_contains($markup, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Notice markup should escape HTML content.');
    assertTrue(str_contains($markup, 'position: fixed;'), 'Notice markup should render as a sticky footer notice.');
    assertTrue(str_contains($markup, 'data-public-notice-dismiss'), 'Notice markup should include a dismiss button.');
    assertTrue(str_contains($markup, "addEventListener('click', dismissNotice)"), 'Notice markup should wire the dismiss button to a click handler.');
    assertTrue(str_contains($markup, 'requestAnimationFrame'), 'Notice markup should throttle resize-driven height syncing.');
    assertTrue(!str_contains($markup, 'localStorage') && !str_contains($markup, 'sessionStorage') && !str_contains($markup, 'document.cookie'), 'Dismissal should remain session-scoped in the page only.');
    echo "✓ Enabled notice renders escaped text with line breaks\n";

    $html = '<html><body><main>Content</main></body></html>';
    $injected = bdta_inject_public_notice_markup($html);
    assertTrue(substr_count($injected, '<div class="bdta-public-notice ') === 1, 'Notice injection should append markup before </body>.');
    $notice_position = strpos($injected, '<div class="bdta-public-notice ');
    $body_close_position = stripos($injected, '</body>');
    assertTrue($notice_position !== false && $body_close_position !== false && $notice_position < $body_close_position, 'Notice injection should place the notice before the closing body tag.');
    assertTrue($injected === bdta_inject_public_notice_markup($injected), 'Notice injection should not duplicate the notice markup.');
    echo "✓ Public notice injection appends a single notice before </body>\n";

    echo "\n=== All Tests Passed! ===\n";
} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . "\n";
    echo "  Line: " . $e->getLine() . "\n";
    exit(1);
}
