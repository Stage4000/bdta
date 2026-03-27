<?php

final class SiteBuilderPageImporter {
    private PDO $targetConn;
    private string $siteBuilderPath;
    private string $siteRootPath;
    private string $assetOutputDir;
    private string $assetPublicBase = '/backend/uploads/sitebuilder/';
    private ?ZipArchive $zip = null;
    private ?PDO $sourceConn = null;
    private ?string $tempZipPath = null;
    private ?string $tempDbPath = null;
    /** @var array<string, array<string, mixed>> */
    private array $sourcePagesById = [];
    /** @var array<string, string> */
    private array $sourcePageSlugs = [];
    private string $siteStyleCss = '';
    private string $navbarHtml = '';
    private string $footerHtml = '';

    public function __construct(PDO $targetConn, string $siteBuilderPath, string $siteRootPath) {
        $this->targetConn = $targetConn;
        $this->siteBuilderPath = $siteBuilderPath;
        $this->siteRootPath = rtrim($siteRootPath, '/');
        $this->assetOutputDir = $this->siteRootPath . '/backend/uploads/sitebuilder';
    }

    public function import(): void {
        if (!$this->shouldImport() || !is_readable($this->siteBuilderPath) || !class_exists('ZipArchive')) {
            return;
        }

        try {
            $this->openSourceArchive();
            $this->openSourceDatabase();
            $this->loadSourcePages();
            if (empty($this->sourcePagesById)) {
                return;
            }
            $this->siteStyleCss = $this->buildSiteStyleCss();
            $this->loadShellHtml();

            foreach ($this->sourcePagesById as $pageId => $page) {
                $this->importPage($pageId, $page);
            }
        } finally {
            $this->cleanupTempFiles();
        }
    }

    private function shouldImport(): bool {
        $existing = $this->targetConn->query(
            "SELECT slug FROM site_pages WHERE is_homepage = 0"
        )->fetchAll(PDO::FETCH_COLUMN);

        if (!is_array($existing) || $existing === []) {
            return true;
        }

        $existingSlugs = array_map('strval', $existing);
        foreach (['about-us', 'pet-sitting', 'connect'] as $requiredSlug) {
            if (!in_array($requiredSlug, $existingSlugs, true)) {
                return true;
            }
        }

        return false;
    }

    private function openSourceArchive(): void {
        $archiveBytes = @file_get_contents($this->siteBuilderPath);
        if (!is_string($archiveBytes) || $archiveBytes === '') {
            throw new RuntimeException('Unable to read site builder export.');
        }

        $zipOffset = strpos($archiveBytes, "PK\x03\x04");
        if ($zipOffset === false) {
            throw new RuntimeException('Site builder export ZIP header not found.');
        }

        $this->tempZipPath = tempnam(sys_get_temp_dir(), 'bdta-sitebuilder-');
        if (!is_string($this->tempZipPath) || $this->tempZipPath === '') {
            throw new RuntimeException('Unable to create temporary ZIP file.');
        }
        if (@file_put_contents($this->tempZipPath, substr($archiveBytes, $zipOffset)) === false) {
            throw new RuntimeException('Unable to write temporary ZIP file.');
        }

        $this->zip = new ZipArchive();
        if ($this->zip->open($this->tempZipPath) !== true) {
            throw new RuntimeException('Unable to open temporary ZIP file.');
        }
    }

    private function openSourceDatabase(): void {
        if (!$this->zip instanceof ZipArchive) {
            throw new RuntimeException('Source ZIP archive is not open.');
        }

        $dbBytes = $this->zip->getFromName('dat/project.db');
        if (!is_string($dbBytes) || $dbBytes === '') {
            throw new RuntimeException('Source project database not found in export.');
        }

        $this->tempDbPath = tempnam(sys_get_temp_dir(), 'bdta-sitebuilder-db-');
        if (!is_string($this->tempDbPath) || $this->tempDbPath === '') {
            throw new RuntimeException('Unable to create temporary project database.');
        }
        if (@file_put_contents($this->tempDbPath, $dbBytes) === false) {
            throw new RuntimeException('Unable to write temporary project database.');
        }

        $this->sourceConn = new PDO('sqlite:' . $this->tempDbPath);
        $this->sourceConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private function loadSourcePages(): void {
        if (!$this->sourceConn instanceof PDO) {
            return;
        }

        $stmt = $this->sourceConn->query(
            "SELECT id, isFront, sortOrder, data FROM pages WHERE type = 0 ORDER BY sortOrder ASC, id ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows)) {
            return;
        }

        /** @var array<string, array<string, mixed>> $pages */
        $pages = [];
        $slugCounts = [];

        foreach ($rows as $row) {
            $pageData = json_decode((string) ($row['data'] ?? ''), true);
            if (!is_array($pageData) || !($pageData['enabled'] ?? false)) {
                continue;
            }

            $name = trim((string) ($pageData['name'] ?? ''));
            $title = trim((string) ($pageData['title'] ?? ''));
            $pageLabel = $name !== '' ? $name : $title;
            if ($pageLabel === '' || (int) ($row['isFront'] ?? 0) === 1 || strcasecmp($name, 'Blog') === 0) {
                continue;
            }

            $slug = $this->slugify($pageLabel);
            $slugCounts[$slug] = ($slugCounts[$slug] ?? 0) + 1;
            if ($slugCounts[$slug] > 1) {
                $slug .= '-' . $slugCounts[$slug];
            }

            $pageId = (string) ($row['id'] ?? '');
            $pageData['id'] = $pageId;
            $pageData['isFront'] = (int) ($row['isFront'] ?? 0);
            $pageData['sortOrder'] = (int) ($row['sortOrder'] ?? 0);
            $pageData['pageTitle'] = $pageLabel;
            $pageData['seoTitle'] = $title !== '' ? $title : $pageLabel;
            $pageData['slug'] = $slug;
            $pages[$pageId] = $pageData;
            $this->sourcePageSlugs[$pageId] = $slug;
        }

        $this->sourcePagesById = $pages;
    }

    private function importPage(string $pageId, array $page): void {
        $title = (string) ($page['pageTitle'] ?? '');
        $slug = (string) ($page['slug'] ?? '');
        if ($title === '' || $slug === '') {
            return;
        }

        $existingStmt = $this->targetConn->prepare(
            "SELECT id, html_content FROM site_pages WHERE slug = ? OR title = ? LIMIT 1"
        );
        $existingStmt->execute([$slug, $title]);
        $existingRow = $existingStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($existingRow) && trim((string) ($existingRow['html_content'] ?? '')) !== '') {
            return;
        }

        $contentHtml = $this->renderSourcePageBody($pageId);
        if ($contentHtml === '') {
            return;
        }

        $fullHtml = $this->navbarHtml
            . "\n<main class=\"bdta-imported-page\">\n"
            . $contentHtml
            . "\n</main>\n"
            . $this->footerHtml;

        $metaTitle = trim((string) ($page['seoTitle'] ?? ''));
        $metaDescription = trim((string) ($page['description'] ?? ''));
        $metaKeywords = trim((string) ($page['keywords'] ?? ''));
        $ogImage = $this->rewriteAssetPath((string) ($page['image'] ?? ''));
        $cssContent = $this->buildImportedPageCss();

        if (is_array($existingRow)) {
            $update = $this->targetConn->prepare(
                "UPDATE site_pages
                 SET slug = ?, title = ?, html_content = ?, css_content = ?, meta_description = ?, meta_keywords = ?,
                     og_title = ?, og_description = ?, og_image = ?, is_published = 1, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $update->execute([
                $slug,
                $title,
                $fullHtml,
                $cssContent,
                $metaDescription,
                $metaKeywords,
                $metaTitle !== '' ? $metaTitle : $title,
                $metaDescription,
                $ogImage,
                (int) ($existingRow['id'] ?? 0),
            ]);
            return;
        }

        $insert = $this->targetConn->prepare(
            "INSERT INTO site_pages
             (slug, title, html_content, css_content, meta_description, meta_keywords, og_title, og_description, og_image,
              is_homepage, is_published, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?)"
        );
        $insert->execute([
            $slug,
            $title,
            $fullHtml,
            $cssContent,
            $metaDescription,
            $metaKeywords,
            $metaTitle !== '' ? $metaTitle : $title,
            $metaDescription,
            $ogImage,
            max(1, (int) ($page['sortOrder'] ?? 0)),
        ]);
    }

    private function renderSourcePageBody(string $pageId): string {
        if (!$this->sourceConn instanceof PDO) {
            return '';
        }

        $stmt = $this->sourceConn->prepare(
            "SELECT id, parentId, class, sortOrder, data
             FROM elements
             WHERE pageId = ?
             ORDER BY sortOrder ASC, id ASC"
        );
        $stmt->execute([$pageId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === []) {
            return '';
        }

        /** @var array<string|null, list<array<string, mixed>>> $childrenByParent */
        $childrenByParent = [];
        /** @var array<string, array<string, mixed>> $elementsById */
        $elementsById = [];

        foreach ($rows as $row) {
            $elementData = json_decode((string) ($row['data'] ?? ''), true);
            if (!is_array($elementData)) {
                continue;
            }

            $element = [
                'id' => (string) ($row['id'] ?? ''),
                'parentId' => $row['parentId'] !== null ? (string) $row['parentId'] : null,
                'class' => (string) ($row['class'] ?? ''),
                'sortOrder' => (int) ($row['sortOrder'] ?? 0),
                'data' => $elementData,
            ];
            $elementsById[$element['id']] = $element;
            $childrenByParent[$element['parentId']][] = $element;
        }

        foreach ($childrenByParent as &$children) {
            usort(
                $children,
                static fn(array $left, array $right): int => ($left['sortOrder'] <=> $right['sortOrder'])
                    ?: strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''))
            );
        }
        unset($children);

        $html = '';
        foreach ($childrenByParent[null] ?? [] as $element) {
            $rendered = $this->renderElement($element, $childrenByParent, $elementsById);
            if ($rendered !== '') {
                $html .= $rendered . "\n";
            }
        }

        return trim($html);
    }

    /**
     * @param array<string, mixed> $element
     * @param array<string|null, list<array<string, mixed>>> $childrenByParent
     * @param array<string, array<string, mixed>> $elementsById
     */
    private function renderElement(array $element, array $childrenByParent, array $elementsById): string {
        $class = (string) ($element['class'] ?? '');
        /** @var array<string, mixed> $data */
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        $children = $childrenByParent[(string) ($element['id'] ?? '')] ?? [];

        return match ($class) {
            'LayoutElement' => $this->renderLayoutElement($data, $children, $childrenByParent, $elementsById),
            'TextArea' => $this->wrapHtmlBlock($this->rewriteMarkup((string) (($data['content']['text'] ?? ''))), $data, 'div'),
            'Picture' => $this->renderPictureElement($data),
            'Button' => $this->renderButtonElement($data),
            'Line' => $this->renderLineElement($data),
            'CustomHtml' => $this->wrapHtmlBlock($this->rewriteMarkup((string) (($data['content']['html'] ?? ''))), $data, 'div'),
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $children
     * @param array<string|null, list<array<string, mixed>>> $childrenByParent
     * @param array<string, array<string, mixed>> $elementsById
     */
    private function renderLayoutElement(array $data, array $children, array $childrenByParent, array $elementsById): string {
        $childrenHtml = '';
        foreach ($children as $child) {
            $rendered = $this->renderElement($child, $childrenByParent, $elementsById);
            if ($rendered !== '') {
                $childrenHtml .= $rendered . "\n";
            }
        }
        $childrenHtml = trim($childrenHtml);

        if ($childrenHtml === '') {
            return '';
        }

        $classes = ['bdta-import-layout'];
        $layout = is_array($data['content']['layout'] ?? null) ? $data['content']['layout'] : [];
        $direction = ((string) ($layout['type'] ?? 'vertical')) === 'horizontal' ? 'row' : 'column';
        if ((string) ($layout['wrapRes'] ?? '') === 'phone') {
            $classes[] = 'bdta-import-stack-phone';
        }

        $style = $this->buildElementStyle($data);
        unset($style['height'], $style['max-height']);
        if (isset($style['min-height']) && preg_match('/^(\d+(?:\.\d+)?)px$/', $style['min-height'], $matches) === 1 && (float) $matches[1] > 600) {
            unset($style['min-height']);
        }
        $style['display'] = 'flex';
        $style['flex-direction'] = $direction;
        $style['gap'] = $style['gap'] ?? '1rem';
        if ($direction === 'row') {
            $style['justify-content'] = $this->mapFlexPosition((string) ($layout['hSpacing'] ?? 'flex-start'));
            $style['align-items'] = $this->mapFlexPosition((string) ($layout['vAlign'] ?? 'stretch'), true);
        } else {
            $style['justify-content'] = $this->mapFlexPosition((string) ($layout['vSpacing'] ?? 'flex-start'));
            $style['align-items'] = $this->mapFlexPosition((string) ($layout['hAlign'] ?? 'stretch'), true);
        }
        if (((string) ($layout['wrap'] ?? 'nowrap')) === 'wrap') {
            $style['flex-wrap'] = 'wrap';
        }

        return $this->tag('section', implode(' ', $classes), $this->styleAttribute($style), $childrenHtml);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderPictureElement(array $data): string {
        $content = is_array($data['content'] ?? null) ? $data['content'] : [];
        $source = $content['src'] ?? null;
        $innerHtml = '';

        if (is_string($source) && $source !== '') {
            $src = $this->rewriteAssetPath($source);
            $innerHtml = '<img class="img-fluid bdta-import-image" src="' . $this->escape($src) . '" alt="">';
        } elseif (is_array($source)) {
            $iconClass = $this->mapIconClass($source);
            if ($iconClass !== '') {
                $innerHtml = '<i class="' . $this->escape($iconClass) . ' bdta-import-icon"></i>';
            }
        }

        if ($innerHtml === '') {
            return '';
        }

        $link = $this->resolveLink(is_array($content['link'] ?? null) ? $content['link'] : null);
        if ($link !== '') {
            $target = !empty($content['link']['target']) ? ' target="_blank" rel="noopener noreferrer"' : '';
            $innerHtml = '<a href="' . $this->escape($link) . '"' . $target . '>' . $innerHtml . '</a>';
        }

        return $this->wrapHtmlBlock($innerHtml, $data, 'div');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderButtonElement(array $data): string {
        $content = is_array($data['content'] ?? null) ? $data['content'] : [];
        $text = trim(strip_tags((string) ($content['text'] ?? 'Learn more')));
        if ($text === '') {
            $text = 'Learn more';
        }

        $href = $this->resolveLink(is_array($content['link'] ?? null) ? $content['link'] : null);
        if ($href === '') {
            $href = '#';
        }

        $iconHtml = '';
        if (is_array($content['buttonTextIcon'] ?? null)) {
            $iconClass = $this->mapIconClass($content['buttonTextIcon']);
            if ($iconClass !== '') {
                $iconHtml = '<i class="' . $this->escape($iconClass) . '"></i> ';
            }
        }

        $target = !empty($content['link']['target']) ? ' target="_blank" rel="noopener noreferrer"' : '';
        $buttonHtml = '<a class="btn btn-primary bdta-import-button" href="' . $this->escape($href) . '"' . $target . '>'
            . $iconHtml
            . '<span>' . $this->escape($text) . '</span>'
            . '</a>';

        return $this->wrapHtmlBlock($buttonHtml, $data, 'div');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderLineElement(array $data): string {
        $style = $this->buildElementStyle($data);
        $content = is_array($data['content'] ?? null) ? $data['content'] : [];
        $weight = max(1, (int) ($content['size'] ?? 1));
        $color = (string) ($content['color'] ?? '#dee2e6');
        $style['border-top'] = $weight . 'px solid ' . $color;
        $style['width'] = $style['width'] ?? '100%';
        $style['min-height'] = '0';
        $style['height'] = '0';

        return $this->tag('div', 'bdta-import-line', $this->styleAttribute($style), '');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function wrapHtmlBlock(string $html, array $data, string $tagName): string {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        return $this->tag($tagName, 'bdta-import-block', $this->styleAttribute($this->buildElementStyle($data)), $html);
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, string>
     */
    private function collectCssDeclarations(array $source): array {
        $declarations = [];
        foreach ($source as $key => $value) {
            if ($key === 'css' && is_array($value)) {
                foreach ($value as $property => $propertyValue) {
                    if (is_string($property) && is_scalar($propertyValue) && $propertyValue !== '') {
                        $declarations[$property] = (string) $propertyValue;
                    }
                }
                continue;
            }

            if (is_array($value)) {
                foreach ($this->collectCssDeclarations($value) as $property => $propertyValue) {
                    $declarations[$property] = $propertyValue;
                }
            }
        }

        return $declarations;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function buildElementStyle(array $data): array {
        $style = $this->collectCssDeclarations($data);
        foreach (['width', 'height', 'minWidth', 'maxWidth', 'minHeight', 'maxHeight'] as $dimensionKey) {
            if (array_key_exists($dimensionKey, $data) && $data[$dimensionKey] !== null && $data[$dimensionKey] !== '') {
                $property = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $dimensionKey) ?? $dimensionKey);
                $style[$property] = $this->cssSize($data[$dimensionKey]);
            }
        }
        if (isset($data['zIndex']) && $this->toInt($data['zIndex']) !== 0) {
            $style['z-index'] = (string) $this->toInt($data['zIndex']);
            $style['position'] = $style['position'] ?? 'relative';
        }
        if (isset($data['flexGrow'])) {
            $style['flex-grow'] = (string) $this->toInt($data['flexGrow']);
        }
        if (isset($data['flexShrink'])) {
            $style['flex-shrink'] = (string) $this->toInt($data['flexShrink']);
        }

        unset($style['font'], $style['background-size']);
        return $style;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hasVisibleElementStyle(array $data): bool {
        $style = $this->buildElementStyle($data);
        foreach (['background', 'border', 'min-height', 'height', 'padding', 'margin'] as $property) {
            if (!empty($style[$property])) {
                return true;
            }
        }
        return false;
    }

    private function buildImportedPageCss(): string {
        return trim(
            ".bdta-imported-page {\n"
            . "    padding-top: 110px;\n"
            . "    padding-bottom: 4rem;\n"
            . "    min-height: 60vh;\n"
            . "}\n"
            . ".bdta-imported-page .bdta-import-layout,\n"
            . ".bdta-imported-page .bdta-import-block {\n"
            . "    width: 100%;\n"
            . "}\n"
            . ".bdta-imported-page .bdta-import-layout {\n"
            . "    margin-left: auto;\n"
            . "    margin-right: auto;\n"
            . "}\n"
            . ".bdta-imported-page .bdta-import-image {\n"
            . "    display: block;\n"
            . "    max-width: 100%;\n"
            . "    height: auto;\n"
            . "}\n"
            . ".bdta-imported-page .bdta-import-button {\n"
            . "    display: inline-flex;\n"
            . "    align-items: center;\n"
            . "    gap: 0.5rem;\n"
            . "}\n"
            . ".bdta-imported-page iframe,\n"
            . ".bdta-imported-page embed,\n"
            . ".bdta-imported-page object {\n"
            . "    max-width: 100%;\n"
            . "}\n"
            . ".bdta-imported-page .mc-field-group input,\n"
            . ".bdta-imported-page input,\n"
            . ".bdta-imported-page textarea,\n"
            . ".bdta-imported-page select {\n"
            . "    max-width: 100%;\n"
            . "}\n"
            . "@media (max-width: 767.98px) {\n"
            . "    .bdta-imported-page .bdta-import-stack-phone {\n"
            . "        flex-direction: column !important;\n"
            . "    }\n"
            . "}\n\n"
            . $this->siteStyleCss
        );
    }

    private function buildSiteStyleCss(): string {
        if (!$this->sourceConn instanceof PDO) {
            return '';
        }

        $stmt = $this->sourceConn->prepare("SELECT value FROM options WHERE key = '__Site__' LIMIT 1");
        $stmt->execute();
        $json = $stmt->fetchColumn();
        if (!is_string($json) || $json === '') {
            return '';
        }

        $siteOptions = json_decode($json, true);
        $styles = is_array($siteOptions['content']['styles'] ?? null) ? $siteOptions['content']['styles'] : [];
        if ($styles === []) {
            return '';
        }

        $css = [];
        foreach ($styles as $styleDefinition) {
            if (!is_array($styleDefinition)) {
                continue;
            }
            $selector = trim((string) ($styleDefinition['selector'] ?? ''));
            if ($selector === '') {
                continue;
            }

            $declarations = $this->collectCssDeclarations($styleDefinition);
            if ($declarations !== []) {
                $css[] = $selector . " {\n" . $this->formatCssDeclarations($declarations) . "\n}";
            }

            $sys = is_array($styleDefinition['sys'] ?? null) ? $styleDefinition['sys'] : [];
            $linkNormalColor = trim((string) ($sys['linkNormalColor'] ?? ''));
            $linkHoverColor = trim((string) ($sys['linkHoverColor'] ?? ''));
            if ($linkNormalColor !== '') {
                $css[] = $selector . " a {\n    color: " . $linkNormalColor . ";\n}";
            }
            if ($linkHoverColor !== '') {
                $css[] = $selector . " a:hover {\n    color: " . $linkHoverColor . ";\n}";
            }
        }

        return implode("\n\n", $css);
    }

    private function loadShellHtml(): void {
        $indexPath = $this->siteRootPath . '/index.html';
        $rawHtml = @file_get_contents($indexPath);
        if (!is_string($rawHtml) || $rawHtml === '') {
            return;
        }

        $bodyHtml = $rawHtml;
        if (preg_match('/<body[^>]*>(.*?)<\/body>/si', $rawHtml, $matches) === 1) {
            $bodyHtml = $matches[1];
        }

        $navbar = '';
        if (preg_match('/<nav\b[^>]*>.*?<\/nav>/si', $bodyHtml, $matches) === 1) {
            $navbar = $matches[0];
        }

        $footer = '';
        if (preg_match('/<footer\b[^>]*>.*?<\/footer>/si', $bodyHtml, $matches) === 1) {
            $footer = $matches[0];
        }

        $this->navbarHtml = $this->rewriteShellMarkup($navbar);
        $this->footerHtml = $this->rewriteShellMarkup($footer);
    }

    private function rewriteShellMarkup(string $html): string {
        $html = $this->makeMarkupPathsAbsolute($html);
        $html = preg_replace('/href=(["\'])#([^"\']+)\1/i', 'href=$1/#$2$1', $html) ?? $html;
        return $html;
    }

    private function rewriteMarkup(string $html): string {
        $html = $this->rewriteGalleryPathsInMarkup($html);
        return $this->makeMarkupPathsAbsolute($html);
    }

    private function rewriteGalleryPathsInMarkup(string $html): string {
        $html = preg_replace_callback(
            '/\b(src|href)=(["\'])(gallery\/[^"\']+)\2/i',
            fn(array $matches): string => $matches[1] . '=' . $matches[2] . $this->rewriteAssetPath($matches[3]) . $matches[2],
            $html
        ) ?? $html;

        $html = preg_replace_callback(
            '/url\((["\']?)(gallery\/[^)\'"]+)\1\)/i',
            fn(array $matches): string => 'url(' . $matches[1] . $this->rewriteAssetPath($matches[2]) . $matches[1] . ')',
            $html
        ) ?? $html;

        return $html;
    }

    private function makeMarkupPathsAbsolute(string $html): string {
        $html = preg_replace(
            '/\bsrc=(["\'])(?!\/|https?:|data:|#)([^"\']+)\1/i',
            'src=$1/$2$1',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/\bhref=(["\'])(?!\/|https?:|mailto:|tel:|data:|#)([^"\']+)\1/i',
            'href=$1/$2$1',
            $html
        ) ?? $html;
        $html = preg_replace(
            '/url\((["\']?)(?!\/|https?:|data:)([^)\'"]+)\1\)/i',
            'url($1/$2$1)',
            $html
        ) ?? $html;
        return $html;
    }

    private function rewriteAssetPath(string $path): string {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '' || !str_starts_with($normalized, 'gallery/')) {
            return $normalized;
        }
        if (str_contains($normalized, '..')) {
            return '';
        }

        $zipPath = ltrim($normalized, '/');
        $destinationPath = $this->assetOutputDir . '/' . $zipPath;
        if (!is_file($destinationPath)) {
            $this->extractAsset($zipPath, $destinationPath);
        }

        return rtrim($this->assetPublicBase, '/') . '/' . $this->encodePathSegments($zipPath);
    }

    private function extractAsset(string $zipPath, string $destinationPath): void {
        if (!$this->zip instanceof ZipArchive) {
            return;
        }

        $contents = $this->zip->getFromName($zipPath);
        if (!is_string($contents)) {
            return;
        }

        $directory = dirname($destinationPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return;
        }

        @file_put_contents($destinationPath, $contents);
    }

    /**
     * @param array<string, mixed>|null $link
     */
    private function resolveLink(?array $link): string {
        if (!is_array($link)) {
            return '';
        }

        $type = (string) ($link['type'] ?? '');
        $url = trim((string) ($link['url'] ?? ''));
        if ($url === '') {
            return '';
        }

        if ($type === 'page') {
            if (isset($this->sourcePagesById[$url]['isFront']) && $this->toInt($this->sourcePagesById[$url]['isFront']) === 1) {
                return '/';
            }
            if (strcasecmp((string) ($this->sourcePagesById[$url]['name'] ?? ''), 'Blog') === 0) {
                return '/blog/index.php';
            }
            if (isset($this->sourcePageSlugs[$url])) {
                return '/page.php?slug=' . rawurlencode($this->sourcePageSlugs[$url]);
            }
            return '#';
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $assetUrl = $this->rewriteAssetPath($url);
        if ($assetUrl !== '' && str_starts_with($assetUrl, '/')) {
            return $assetUrl;
        }
        return '/' . ltrim($assetUrl, '/');
    }

    /**
     * @param array<string, mixed> $icon
     */
    private function mapIconClass(array $icon): string {
        $iconName = trim((string) ($icon['icon'] ?? ''));
        if ($iconName === '') {
            return '';
        }

        $family = trim((string) ($icon['family'] ?? ''));
        if ($family === 'FontAwesome') {
            $prefix = trim((string) ($icon['prefix'] ?? 'fa fa-'));
            if ($prefix !== '' && str_contains($prefix, ' ')) {
                return trim($prefix . $iconName);
            }
            return 'fa fa-' . $iconName;
        }

        $map = [
            'tiktok' => 'fab fa-tiktok',
            'facebook-square' => 'fab fa-facebook-square',
            'instagram' => 'fab fa-instagram',
        ];

        return $map[$iconName] ?? '';
    }

    private function mapFlexPosition(string $value, bool $allowStretch = false): string {
        return match ($value) {
            'flex-start', 'left', 'top' => 'flex-start',
            'flex-end', 'right', 'bottom' => 'flex-end',
            'center' => 'center',
            'space-between' => 'space-between',
            'space-around' => 'space-around',
            'space-evenly' => 'space-evenly',
            'stretch' => $allowStretch ? 'stretch' : 'flex-start',
            default => 'flex-start',
        };
    }

    /**
     * @param array<string, string> $style
     */
    private function styleAttribute(array $style): string {
        if ($style === []) {
            return '';
        }

        return ' style="' . $this->escape($this->inlineCss($style)) . '"';
    }

    /**
     * @param array<string, string> $style
     */
    private function inlineCss(array $style): string {
        $parts = [];
        foreach ($style as $property => $value) {
            $trimmedValue = trim((string) $value);
            if ($trimmedValue === '') {
                continue;
            }
            $parts[] = $property . ': ' . $trimmedValue;
        }
        return implode('; ', $parts);
    }

    /**
     * @param array<string, string> $style
     */
    private function formatCssDeclarations(array $style): string {
        $lines = [];
        foreach ($style as $property => $value) {
            $trimmedValue = trim((string) $value);
            if ($trimmedValue === '') {
                continue;
            }
            $lines[] = '    ' . $property . ': ' . $trimmedValue . ';';
        }
        return implode("\n", $lines);
    }

    private function cssSize(mixed $value): string {
        if (is_int($value) || is_float($value)) {
            return $value . 'px';
        }

        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $text) === 1) {
            return $text . 'px';
        }
        return $text;
    }

    private function toInt(mixed $value): int {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function slugify(string $text): string {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? $text;
        $text = trim($text, '-');
        return $text !== '' ? $text : 'page';
    }

    private function encodePathSegments(string $path): string {
        $segments = array_map(
            static fn(string $segment): string => rawurlencode($segment),
            array_values(array_filter(explode('/', $path), static fn(string $segment): bool => $segment !== ''))
        );
        return implode('/', $segments);
    }

    private function tag(string $tagName, string $className, string $styleAttribute, string $innerHtml): string {
        $classAttr = $className !== '' ? ' class="' . $this->escape($className) . '"' : '';
        return '<' . $tagName . $classAttr . $styleAttribute . '>' . $innerHtml . '</' . $tagName . '>';
    }

    private function escape(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    private function cleanupTempFiles(): void {
        if ($this->zip instanceof ZipArchive) {
            $this->zip->close();
            $this->zip = null;
        }
        if (is_string($this->tempZipPath) && is_file($this->tempZipPath)) {
            @unlink($this->tempZipPath);
        }
        if (is_string($this->tempDbPath) && is_file($this->tempDbPath)) {
            @unlink($this->tempDbPath);
        }
        $this->tempZipPath = null;
        $this->tempDbPath = null;
        $this->sourceConn = null;
    }
}
