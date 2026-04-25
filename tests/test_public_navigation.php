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

    $sectionLinksHtml = <<<HTML
<div>
    <a class="nav-link" href="#services">Services</a>
    <a class="text-white-50 text-decoration-none" href="../index.html#contact">Contact</a>
    <a class="text-white-50 text-decoration-none" href="index.php#about">About</a>
    <a class="text-white-50 text-decoration-none" href="#faq">FAQ</a>
    <a class="text-white-50 text-decoration-none" href="page.php?slug=directory#contact">Directory Contact</a>
</div>
HTML;
    $normalizedSectionLinksHtml = bdta_sync_public_navigation_links($sectionLinksHtml);
    assertTrue(str_contains($normalizedSectionLinksHtml, 'href="/#services">Services</a>'), 'Expected homepage services links to point back to the homepage.');
    assertTrue(str_contains($normalizedSectionLinksHtml, 'href="/#contact">Contact</a>'), 'Expected relative homepage contact links to point back to the homepage.');
    assertTrue(str_contains($normalizedSectionLinksHtml, 'href="/#about">About</a>'), 'Expected index.php homepage about links to point back to the homepage.');
    assertTrue(str_contains($normalizedSectionLinksHtml, 'href="#faq">FAQ</a>'), 'Expected unrelated in-page anchor links to remain unchanged.');
    assertTrue(str_contains($normalizedSectionLinksHtml, 'href="page.php?slug=directory#contact">Directory Contact</a>'), 'Expected non-homepage anchors with queries to remain unchanged.');
    assertTrue(!str_contains($normalizedSectionLinksHtml, 'href="../index.html#contact">Contact</a>'), 'Expected relative homepage index links to be normalized.');

    $nestedIndexLinksHtml = <<<HTML
<div>
    <a href="blog/index.php#contact">Blog Contact</a>
    <a href="/foo/index.html#services">Foo Services</a>
    <a href="./index.html#home">Current Home</a>
</div>
HTML;
    $normalizedNestedIndexLinksHtml = bdta_sync_public_navigation_links($nestedIndexLinksHtml);
    assertTrue(str_contains($normalizedNestedIndexLinksHtml, 'href="blog/index.php#contact">Blog Contact</a>'), 'Expected nested blog index links not to be treated as homepage links.');
    assertTrue(str_contains($normalizedNestedIndexLinksHtml, 'href="/foo/index.html#services">Foo Services</a>'), 'Expected nested directory index links not to be treated as homepage links.');
    assertTrue(str_contains($normalizedNestedIndexLinksHtml, 'href="/#home">Current Home</a>'), 'Expected current-directory homepage links to still normalize to the root homepage.');

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

    $importedHtml = '<div id="wb_root" class="root wb-layout-vertical"><div class="wb-layout-element"></div></div>';
    $wrappedImportedHtml = bdta_wrap_imported_page_html($importedHtml);
    assertTrue(str_starts_with($wrappedImportedHtml, '<div class="bdta-imported-page">'), 'Expected imported page HTML to be wrapped for runtime CSS targeting.');
    assertTrue(str_contains($wrappedImportedHtml, $importedHtml), 'Expected imported page HTML wrapper to preserve the original markup.');
    assertSameString($wrappedImportedHtml, bdta_wrap_imported_page_html($wrappedImportedHtml), 'Expected imported page HTML not to be wrapped twice.');
    assertSameString($baseHtml, bdta_wrap_imported_page_html($baseHtml), 'Expected non-imported public HTML not to be wrapped.');

    $runtimeCss = bdta_get_imported_page_runtime_css();
    assertTrue(str_contains($runtimeCss, 'min-width: 0 !important;'), 'Expected runtime CSS to include imported mobile width override.');
    assertTrue(str_contains($runtimeCss, ':is(.bdta-imported-page, body > #wb_root)'), 'Expected runtime CSS to support wrapped and full-document imported pages.');
    assertTrue(str_contains($runtimeCss, 'body > #wb_root'), 'Expected runtime CSS to support imported full-document fallback pages.');
    assertTrue(str_contains($runtimeCss, 'overflow-x: clip;'), 'Expected runtime CSS to clip imported page horizontal overflow.');
    assertTrue(str_contains($runtimeCss, '[data-bs-theme="dark"] :is(.bdta-imported-page, body > #wb_root)'), 'Expected runtime CSS to include imported page dark-mode overrides.');
    assertTrue(str_contains($runtimeCss, 'background-color: #ffffff;'), 'Expected imported page dark-mode override to keep manual pages readable.');
    assertTrue(str_contains($runtimeCss, '.bdta-import-stack-phone,'), 'Expected runtime CSS to widen stacked imported layouts on mobile.');
    assertTrue(str_contains($runtimeCss, '.bdta-imported-page > .root,'), 'Expected runtime CSS to constrain the imported root container width.');
    assertTrue(str_contains($runtimeCss, '[id^="wb_header_"] .wb_content.wb-layout-horizontal'), 'Expected runtime CSS to target imported site header rows on mobile.');
    assertTrue(str_contains($runtimeCss, 'flex-wrap: wrap !important;'), 'Expected runtime CSS to wrap imported site header rows on mobile.');
    assertTrue(str_contains($runtimeCss, '[id^="wb_main_"] .wb_content.wb-layout-horizontal'), 'Expected runtime CSS to target imported main content rows on mobile.');
    assertTrue(str_contains($runtimeCss, '[id^="wb_main_"] .wb_content.wb-layout-horizontal > .wb_element,'), 'Expected runtime CSS to stack imported main child elements on mobile.');
    assertTrue(str_contains($runtimeCss, '[id^="wb_main_"] .wb_content.wb-layout-horizontal > .wb-layout-element'), 'Expected runtime CSS to stack imported main layout elements on mobile.');
    assertTrue(str_contains($runtimeCss, 'flex: 1 1 100% !important;'), 'Expected runtime CSS to let imported mobile layout elements consume a full wrapped row.');
    assertTrue(str_contains($runtimeCss, '[id^="wb_main_"] img,'), 'Expected runtime CSS to constrain imported main media width on mobile.');
    assertTrue(str_contains($runtimeCss, '[data-plugin="tawkto"]'), 'Expected runtime CSS to target the imported header Tawk placeholder on mobile.');
    assertTrue(str_contains($runtimeCss, 'flex: 0 0 0 !important;'), 'Expected runtime CSS to fully collapse the imported header Tawk placeholder width on mobile.');
    assertTrue(str_contains($runtimeCss, 'overflow: hidden !important;'), 'Expected runtime CSS to hide the imported header Tawk placeholder overflow on mobile.');
    assertTrue(str_contains($runtimeCss, '.wb-menu-mobile'), 'Expected runtime CSS to target the imported mobile menu on mobile.');
    assertTrue(str_contains($runtimeCss, 'margin-left: auto !important;'), 'Expected runtime CSS to keep the imported mobile menu aligned inside the header.');

    $fullDocumentHtml = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><title>Imported</title></head>
<body><div id="wb_root" class="root wb-layout-vertical"></div></body>
</html>
HTML;
    $styledFullDocumentHtml = bdta_inject_imported_page_runtime_css($fullDocumentHtml);
    assertTrue(str_contains($styledFullDocumentHtml, '<style>'), 'Expected imported full-document HTML to receive runtime CSS in the head.');
    assertTrue(str_contains($styledFullDocumentHtml, 'body > #wb_root'), 'Expected injected runtime CSS to support imported full-document fallback pages.');
    assertSameString($styledFullDocumentHtml, bdta_inject_imported_page_runtime_css($styledFullDocumentHtml), 'Expected imported full-document HTML not to receive duplicate runtime CSS.');
    assertSameString($baseHtml, bdta_inject_imported_page_runtime_css($baseHtml), 'Expected non-imported HTML not to receive injected runtime CSS.');

    $publicToggleButton = bdta_get_public_theme_toggle_button_html('d-lg-none');
    assertTrue(str_contains($publicToggleButton, 'data-theme-toggle'), 'Expected shared public theme toggle markup to use the multi-button theme toggle selector.');
    assertTrue(str_contains($publicToggleButton, 'data-theme-icon'), 'Expected shared public theme toggle markup to expose a theme icon hook.');
    assertTrue(str_contains($publicToggleButton, 'd-lg-none'), 'Expected shared public theme toggle markup to support mobile-only visibility.');
    assertTrue(str_contains($publicToggleButton, 'public-theme-toggle'), 'Expected shared public theme toggle markup to use the shared theme-aware floating toggle class.');
    assertTrue(!str_contains($publicToggleButton, 'background-color:rgba(255,255,255,0.95)'), 'Expected shared public theme toggle markup not to hard-code a light floating background.');
    assertTrue(!str_contains($publicToggleButton, 'id="darkModeToggle"'), 'Expected shared public theme toggle markup to avoid duplicate dark mode toggle IDs.');
    assertTrue(!str_contains($publicToggleButton, 'top-0'), 'Expected shared public theme toggle markup not to hard-code a top-edge position that can overlap header actions.');
    assertTrue(!str_contains($publicToggleButton, 'end-0'), 'Expected shared public theme toggle markup not to hard-code a right-edge position that can overlap header actions.');
    assertTrue(!str_contains($publicToggleButton, 'm-3'), 'Expected shared public theme toggle markup not to rely on fixed utility margins for placement.');

    $defaultPublicToggleButton = bdta_get_public_theme_toggle_button_html();
    assertTrue(!str_contains($defaultPublicToggleButton, 'd-lg-none'), 'Expected shared public theme toggle markup to stay visible on desktop when no extra visibility class is requested.');

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
