<?php

const BDTA_IMPORTED_PAGE_ROOT_ID = 'wb_root';

function bdta_current_public_nav_context(): string {
    $slugValue = $_GET['slug'] ?? '';
    $slug = is_string($slugValue) ? trim($slugValue) : '';
    if ($slug === 'directory') {
        return 'directory';
    }

    $requestUriValue = $_SERVER['REQUEST_URI'] ?? '';
    $requestUri = is_string($requestUriValue) ? $requestUriValue : '';
    $requestPath = parse_url($requestUri, PHP_URL_PATH);
    $requestPath = is_string($requestPath) ? $requestPath : '';
    if (preg_match('~(?:^|/)blog(?:/|$)~', $requestPath) === 1) {
        return 'blog';
    }

    if ($requestPath === '' || $requestPath === '/' || str_ends_with($requestPath, '/index.php') || str_ends_with($requestPath, '/index.html')) {
        return 'home';
    }

    return '';
}

function bdta_get_imported_page_runtime_css(): string {
    return <<<'CSS'
.bdta-imported-page {
    width: 100%;
    max-width: 100%;
    overflow-x: clip;
}
.bdta-imported-page > .bdta-import-layout {
    margin-left: auto !important;
    margin-right: auto !important;
}
.bdta-imported-page > .root,
.bdta-imported-page #wb_root {
    width: 100%;
    max-width: 100%;
    margin-left: auto;
    margin-right: auto;
    overflow-x: clip;
}
.bdta-imported-page .bdta-import-layout,
.bdta-imported-page .bdta-import-block {
    box-sizing: border-box;
}
@media (max-width: 767.98px) {
    .bdta-imported-page .bdta-import-stack-phone,
    .bdta-imported-page .bdta-import-layout,
    .bdta-imported-page .bdta-import-block {
        width: 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
    }
    .bdta-imported-page .bdta-import-block {
        margin-left: auto !important;
        margin-right: auto !important;
    }
    .bdta-imported-page .bdta-import-image {
        width: 100%;
        height: auto;
    }
    .bdta-imported-page a,
    .bdta-imported-page p,
    .bdta-imported-page h1,
    .bdta-imported-page h2,
    .bdta-imported-page h3,
    .bdta-imported-page h4,
    .bdta-imported-page h5,
    .bdta-imported-page h6,
    .bdta-imported-page span {
        overflow-wrap: break-word;
    }
    .bdta-imported-page [id^="wb_header_"] > .wb_content,
    .bdta-imported-page [id^="wb_header_"] .wb_content.wb-layout-horizontal {
        flex-wrap: wrap !important;
    }
    .bdta-imported-page [id^="wb_main_"],
    .bdta-imported-page [id^="wb_main_"] > .wb_content,
    .bdta-imported-page [id^="wb_main_"] .wb_content.wb-layout-horizontal,
    .bdta-imported-page [id^="wb_main_"] .wb_content.wb-layout-vertical {
        width: 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
    }
    .bdta-imported-page [id^="wb_main_"] .wb_content.wb-layout-horizontal {
        flex-wrap: wrap !important;
    }
    .bdta-imported-page [id^="wb_main_"] .wb_content.wb-layout-horizontal > .wb_element,
    .bdta-imported-page [id^="wb_main_"] .wb_content.wb-layout-horizontal > .wb-layout-element,
    .bdta-imported-page [id^="wb_main_"] .wb_content.wb-layout-vertical > .wb-layout-element {
        width: 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
        flex: 1 1 100% !important;
    }
    .bdta-imported-page [id^="wb_main_"] .wb_content,
    .bdta-imported-page [id^="wb_main_"] .wb_element {
        min-width: 0 !important;
        max-width: 100% !important;
    }
    .bdta-imported-page [id^="wb_main_"] img,
    .bdta-imported-page [id^="wb_main_"] svg,
    .bdta-imported-page [id^="wb_main_"] video,
    .bdta-imported-page [id^="wb_main_"] iframe {
        max-width: 100% !important;
        height: auto !important;
    }
    .bdta-imported-page [id^="wb_header_"] .wb_content.wb-layout-horizontal > .wb_element {
        min-width: 0 !important;
        max-width: 100% !important;
    }
    .bdta-imported-page [id^="wb_header_"] [data-plugin="TextArea"] {
        flex: 1 1 12rem !important;
        width: auto !important;
        margin-right: auto !important;
    }
    .bdta-imported-page [id^="wb_header_"] [data-plugin="TextArea"] a,
    .bdta-imported-page [id^="wb_header_"] [data-plugin="TextArea"] span {
        white-space: normal !important;
        overflow-wrap: anywhere !important;
    }
    .bdta-imported-page [id^="wb_header_"] [data-plugin="tawkto"] {
        width: 0 !important;
        height: 0 !important;
        min-width: 0 !important;
        min-height: 0 !important;
        margin: 0 !important;
        flex: 0 0 0 !important;
        overflow: hidden !important;
    }
    .bdta-imported-page [id^="wb_header_"] .wb-menu-mobile {
        margin-left: auto !important;
    }
}
CSS;
}

function bdta_wrap_imported_page_html(string $html): string {
    $trimmedHtml = trim($html);
    if ($trimmedHtml === '' || preg_match('/^<div class=(["\'])bdta-imported-page\1>/', $trimmedHtml) === 1) {
        return $html;
    }

    $importedRootIdPattern = preg_quote(BDTA_IMPORTED_PAGE_ROOT_ID, '/');
    if (preg_match('/\bid\s*=\s*(?:"' . $importedRootIdPattern . '"|\'' . $importedRootIdPattern . '\')/', $html) !== 1) {
        return $html;
    }

    return '<div class="bdta-imported-page">' . $html . '</div>';
}

function bdta_sync_public_navigation_links(string $html): string {
    $directoryLink = '/page.php?slug=directory';
    if (!str_contains($html, 'href="' . $directoryLink . '"')) {
        $directoryItem = '<li class="nav-item">' . "\n"
            . '                        <a class="nav-link" href="' . $directoryLink . '">Directory</a>' . "\n"
            . '                    </li>' . "\n"
            . '                    ';
        $html = preg_replace(
            '~<li class="nav-item">\s*<a class="nav-link(?: active)?" href="(?:/)?(?:blog/index\.php|index\.php)">Blog</a>\s*</li>\s*~',
            $directoryItem . '$0',
            $html,
            1
        ) ?? $html;
    }

    $html = preg_replace(
        '~<li class="nav-item">\s*<a class="nav-link(?: active)?" href="(?:/)?(?:page\.php\?slug=dog-training-fact-sheet|facts/?(?:index\.php)?)">Dog Training Fact Sheet</a>\s*</li>\s*~',
        '',
        $html
    ) ?? $html;

    $currentContext = bdta_current_public_nav_context();
    $activeLabels = [
        'Home' => $currentContext === 'home',
        'Blog' => $currentContext === 'blog',
        'Directory' => $currentContext === 'directory',
    ];

    $html = preg_replace_callback(
        '~<a class="(?P<class>[^"]*\bnav-link\b[^"]*)" href="(?P<href>[^"]+)">(?P<label>Home|Blog|Directory)</a>~',
        static function (array $matches) use ($activeLabels): string {
            $classValue = trim($matches['class']);
            $classes = preg_split('/\s+/', $classValue);
            if ($classes === false) {
                $classes = [$classValue];
            }

            $classes = array_values(array_filter($classes, static fn(string $class): bool => $class !== '' && $class !== 'active'));
            if ($activeLabels[$matches['label']] === true) {
                $classes[] = 'active';
            }

            return '<a class="' . implode(' ', array_unique($classes)) . '" href="' . $matches['href'] . '">' . $matches['label'] . '</a>';
        },
        $html
    ) ?? $html;

    return $html;
}
