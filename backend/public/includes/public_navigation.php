<?php

const BDTA_IMPORTED_PAGE_ROOT_ID = 'wb_root';
const BDTA_PUBLIC_HOMEPAGE_SECTION_IDS = [
    'home' => true,
    'about' => true,
    'services' => true,
    'events' => true,
    'testimonials' => true,
    'contact' => true,
];

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
:is(.bdta-imported-page, body > #wb_root) {
    width: 100%;
    max-width: 100%;
    overflow-x: clip;
}
[data-bs-theme="dark"] :is(.bdta-imported-page, body > #wb_root) {
    background-color: #ffffff;
    color: #333333;
}
.bdta-imported-page > .bdta-import-layout {
    margin-left: auto !important;
    margin-right: auto !important;
}
.bdta-imported-page > .root,
.bdta-imported-page #wb_root,
body > #wb_root {
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
    :is(.bdta-imported-page, body > #wb_root) .bdta-import-stack-phone,
    :is(.bdta-imported-page, body > #wb_root) .bdta-import-layout,
    :is(.bdta-imported-page, body > #wb_root) .bdta-import-block {
        width: 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
    }
    :is(.bdta-imported-page, body > #wb_root) .bdta-import-block {
        margin-left: auto !important;
        margin-right: auto !important;
    }
    :is(.bdta-imported-page, body > #wb_root) .bdta-import-image {
        width: 100%;
        height: auto;
    }
    :is(.bdta-imported-page, body > #wb_root) a,
    :is(.bdta-imported-page, body > #wb_root) p,
    :is(.bdta-imported-page, body > #wb_root) h1,
    :is(.bdta-imported-page, body > #wb_root) h2,
    :is(.bdta-imported-page, body > #wb_root) h3,
    :is(.bdta-imported-page, body > #wb_root) h4,
    :is(.bdta-imported-page, body > #wb_root) h5,
    :is(.bdta-imported-page, body > #wb_root) h6,
    :is(.bdta-imported-page, body > #wb_root) span {
        overflow-wrap: break-word;
    }
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_header_"] > .wb_content,
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_header_"] .wb_content.wb-layout-horizontal {
        flex-wrap: wrap !important;
    }
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_main_"],
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_main_"] > .wb_content,
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_main_"] .wb_content.wb-layout-horizontal,
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_main_"] .wb_content.wb-layout-vertical {
        width: 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
    }
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_main_"] .wb_content.wb-layout-horizontal {
        flex-wrap: wrap !important;
    }
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_main_"] .wb_content.wb-layout-horizontal > .wb_element,
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_main_"] .wb_content.wb-layout-horizontal > .wb-layout-element,
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_main_"] .wb_content.wb-layout-vertical > .wb-layout-element {
        width: 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
        flex: 1 1 100% !important;
    }
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_main_"] .wb_content,
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_main_"] .wb_element {
        min-width: 0 !important;
        max-width: 100% !important;
    }
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_main_"] img,
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_main_"] svg,
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_main_"] video,
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_main_"] iframe {
        max-width: 100% !important;
        height: auto !important;
    }
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_header_"] .wb_content.wb-layout-horizontal > .wb_element {
        min-width: 0 !important;
        max-width: 100% !important;
    }
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_header_"] [data-plugin="TextArea"] {
        flex: 1 1 12rem !important;
        width: auto !important;
        margin-right: auto !important;
    }
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_header_"] [data-plugin="TextArea"] a,
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_header_"] [data-plugin="TextArea"] span {
        white-space: normal !important;
        overflow-wrap: anywhere !important;
    }
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_header_"] [data-plugin="tawkto"] {
        width: 0 !important;
        height: 0 !important;
        min-width: 0 !important;
        min-height: 0 !important;
        margin: 0 !important;
        flex: 0 0 0 !important;
        overflow: hidden !important;
    }
    :is(.bdta-imported-page, body > #wb_root) [id^="wb_header_"] .wb-menu-mobile {
        margin-left: auto !important;
    }
}
CSS;
}

function bdta_get_public_theme_toggle_button_html(string $extraClasses = ''): string {
    $classNames = trim('btn btn-outline-secondary btn-sm position-fixed top-0 end-0 m-3 no-print ' . $extraClasses);

    return '<button type="button" data-theme-toggle class="' . htmlspecialchars($classNames, ENT_QUOTES, 'UTF-8') . '" style="z-index:1100;" title="Toggle dark mode" aria-label="Toggle dark mode">'
        . '<i class="fas fa-moon" data-theme-icon></i>'
        . '</button>';
}

function bdta_inject_imported_page_runtime_css(string $html): string {
    $importedRootIdPattern = preg_quote(BDTA_IMPORTED_PAGE_ROOT_ID, '/');
    if (preg_match('/\bid\s*=\s*(?:"' . $importedRootIdPattern . '"|\'' . $importedRootIdPattern . '\')/', $html) !== 1) {
        return $html;
    }

    $styleTag = "<style>\n" . bdta_get_imported_page_runtime_css() . "\n</style>";
    if (str_contains($html, $styleTag)) {
        return $html;
    }

    if (preg_match('/<\/head>/i', $html) === 1) {
        return preg_replace('/<\/head>/i', $styleTag . "\n</head>", $html, 1) ?? $html;
    }

    return $styleTag . $html;
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

function bdta_normalize_public_homepage_section_href(string $href): ?string {
    $parsedHref = parse_url($href);
    if ($parsedHref === false) {
        return null;
    }

    if (
        isset($parsedHref['scheme'])
        || isset($parsedHref['host'])
        || isset($parsedHref['user'])
        || isset($parsedHref['pass'])
        || isset($parsedHref['port'])
    ) {
        return null;
    }

    $fragment = strtolower(trim((string) ($parsedHref['fragment'] ?? '')));
    if ($fragment === '' || !isset(BDTA_PUBLIC_HOMEPAGE_SECTION_IDS[$fragment])) {
        return null;
    }

    $path = trim((string) ($parsedHref['path'] ?? ''));
    $query = trim((string) ($parsedHref['query'] ?? ''));
    if ($query !== '') {
        return null;
    }

    if ($path !== '') {
        $normalizedPath = str_replace('\\', '/', $path);
        if (
            $normalizedPath !== '/'
            && preg_match('~^(?:(?:\./|\.\./)*)/?index\.(?:php|html)$~i', $normalizedPath) !== 1
        ) {
            return null;
        }
    }

    return '/#' . $fragment;
}

function bdta_normalize_public_homepage_section_links(string $html): string {
    return preg_replace_callback(
        '~href=(["\'])(?P<href>[^"\']+)\1~i',
        static function (array $matches): string {
            $normalizedHref = bdta_normalize_public_homepage_section_href($matches['href']);
            if ($normalizedHref === null || $normalizedHref === $matches['href']) {
                return $matches[0];
            }

            return 'href=' . $matches[1] . $normalizedHref . $matches[1];
        },
        $html
    ) ?? $html;
}

function bdta_sync_public_navigation_links(string $html): string {
    $html = bdta_normalize_public_homepage_section_links($html);

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
