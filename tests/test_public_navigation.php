<?php

require_once dirname(__DIR__) . '/backend/public/includes/public_navigation.php';

function assertSameString(string $expected, string $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . $expected . "\nActual:   " . $actual);
    }
}

function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;
$originalSlug = $_GET['slug'] ?? null;

try {
    $baseHtml = <<<HTML
<ul class="navbar-nav ms-auto">
    <li class="nav-item"><a class="nav-link active" href="/#home">Home</a></li>
    <li class="nav-item"><a class="nav-link" href="/blog/index.php">Blog</a></li>
</ul>
HTML;

    $_SERVER['REQUEST_URI'] = '/page.php?slug=directory';
    $_GET['slug'] = 'directory';
    $directoryHtml = bdta_sync_public_navigation_links($baseHtml);
    assertTrue(str_contains($directoryHtml, 'href="/page.php?slug=directory">Directory</a>'), 'Expected Directory link to be injected.');
    assertTrue(str_contains($directoryHtml, 'class="nav-link active" href="/page.php?slug=directory">Directory</a>'), 'Expected Directory link to be active on the directory page.');
    assertTrue(!str_contains($directoryHtml, 'class="nav-link active" href="/#home">Home</a>'), 'Expected Home link not to stay active on the directory page.');

    $_SERVER['REQUEST_URI'] = '/blog/index.php';
    unset($_GET['slug']);
    $blogHtml = bdta_sync_public_navigation_links($baseHtml);
    assertTrue(str_contains($blogHtml, 'class="nav-link active" href="/blog/index.php">Blog</a>'), 'Expected Blog link to be active on blog pages.');

    $_SERVER['REQUEST_URI'] = '/';
    $_GET['slug'] = '';
    $factSheetHtml = bdta_sync_public_navigation_links(
        $baseHtml . '<li class="nav-item"><a class="nav-link" href="/facts">Dog Training Fact Sheet</a></li>'
    );
    assertTrue(!str_contains($factSheetHtml, 'Dog Training Fact Sheet'), 'Expected Dog Training Fact Sheet nav link to be removed.');

    $importedHtml = '<div id="wb_root" class="root wb-layout-vertical"><div class="wb_element"></div></div>';
    $wrappedImportedHtml = bdta_wrap_imported_page_html($importedHtml);
    assertTrue(str_starts_with($wrappedImportedHtml, '<div class="bdta-imported-page">'), 'Expected imported page HTML to be wrapped for runtime CSS targeting.');
    assertTrue(str_contains($wrappedImportedHtml, $importedHtml), 'Expected imported page HTML wrapper to preserve the original markup.');
    assertSameString($wrappedImportedHtml, bdta_wrap_imported_page_html($wrappedImportedHtml), 'Expected imported page HTML not to be wrapped twice.');
    assertSameString($baseHtml, bdta_wrap_imported_page_html($baseHtml), 'Expected non-imported public HTML not to be wrapped.');

    $runtimeCss = bdta_get_imported_page_runtime_css();
    assertTrue(str_contains($runtimeCss, 'min-width: 0 !important;'), 'Expected runtime CSS to include imported mobile width override.');
    assertTrue(str_contains($runtimeCss, 'align-items: center;'), 'Expected runtime CSS to center imported page content.');
    assertTrue(str_contains($runtimeCss, '.bdta-imported-page .bdta-import-stack-phone,'), 'Expected runtime CSS to widen stacked imported layouts on mobile.');
    assertTrue(str_contains($runtimeCss, '[id^="wb_header_"] .wb_content.wb-layout-horizontal'), 'Expected runtime CSS to target imported site header rows on mobile.');
    assertTrue(str_contains($runtimeCss, 'flex-wrap: wrap !important;'), 'Expected runtime CSS to wrap imported site header rows on mobile.');
    assertTrue(str_contains($runtimeCss, '[data-plugin="tawkto"]'), 'Expected runtime CSS to target the imported header Tawk placeholder on mobile.');
    assertTrue(str_contains($runtimeCss, 'flex: 0 0 0 !important;'), 'Expected runtime CSS to fully collapse the imported header Tawk placeholder width on mobile.');
    assertTrue(str_contains($runtimeCss, 'overflow: hidden !important;'), 'Expected runtime CSS to hide the imported header Tawk placeholder overflow on mobile.');
    assertTrue(str_contains($runtimeCss, '.wb-menu-mobile'), 'Expected runtime CSS to target the imported mobile menu on mobile.');
    assertTrue(str_contains($runtimeCss, 'margin-left: auto !important;'), 'Expected runtime CSS to keep the imported mobile menu aligned inside the header.');

    echo "Public navigation helper test passed.\n";
} finally {
    if ($originalRequestUri === null) {
        unset($_SERVER['REQUEST_URI']);
    } else {
        $_SERVER['REQUEST_URI'] = $originalRequestUri;
    }

    if ($originalSlug === null) {
        unset($_GET['slug']);
    } else {
        $_GET['slug'] = $originalSlug;
    }
}
