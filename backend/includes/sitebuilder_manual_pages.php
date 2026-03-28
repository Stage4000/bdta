<?php

final class SiteBuilderManualPageSeeder {
    private const COMMON_CSS_ENCODED = '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';

    /**
     * @return list<array{
     *     slug: string,
     *     title: string,
     *     meta_description: string,
     *     meta_keywords: string,
     *     og_title: string,
     *     og_description: string,
     *     og_image: string,
     *     sort_order: int,
     *     assets: list<string>,
     *     html_encoded: string
     * }>
     */
    private static function getPages(): array {
        return [
            [
                'slug' => 'dog-training-fact-sheet',
                'title' => 'Dog Training Fact Sheet',
                'meta_description' => '',
                'meta_keywords' => '',
                'og_title' => 'Dog Training Fact Sheet',
                'og_description' => '',
                'og_image' => '/backend/uploads/sitebuilder/gallery/Dog-Training-Fact-Sheet-1.png',
                'sort_order' => 1,
                'assets' => [
                    'gallery/Dog-Training-Fact-Sheet-1.png',
                    'gallery/Once-is-not-enough.png',
                    'gallery/breeders-interests.png',
                    'gallery/check-leash-laws.png',
                    'gallery/dog-emotions.png',
                    'gallery/dog-high5-spend-time.png',
                    'gallery/good-training-boring-tv.png',
                    'gallery/myths-facts-sign.png',
                    'gallery/no-breed-is-bad.png',
                    'gallery/other-end-of-leash.png',
                    'gallery/small-dog-bias.png',
                ],
                'html_encoded' => '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',
            ],
            [
                'slug' => 'directory',
                'title' => 'Directory',
                'meta_description' => '',
                'meta_keywords' => '',
                'og_title' => 'Directory',
                'og_description' => '',
                'og_image' => '',
                'sort_order' => 2,
                'assets' => [
                    'gallery/242159751_135250202159875_240629272138812113_n.png',
                    'gallery/464713509_122115682856547478_6511436599214494982_n.jpg',
                    'gallery/Heading (2).png',
                    'gallery/Your business here.png',
                    'gallery/highlands hammock entrance.jpg',
                ],
                'html_encoded' => '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',
            ],
        ];
    }

    public static function needsSeeding(PDO $conn): bool {
        $slugs = array_column(self::getPages(), 'slug');
        if ($slugs === []) {
            return false;
        }

        $placeholders = implode(', ', array_fill(0, count($slugs), '?'));
        $stmt = $conn->prepare(
            "SELECT slug, html_content FROM site_pages WHERE slug IN ($placeholders)"
        );
        $stmt->execute($slugs);

        /** @var array<string, string> $existingPages */
        $existingPages = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !isset($row['slug'])) {
                continue;
            }
            $existingPages[self::stringValue($row['slug'])] = self::stringValue($row['html_content'] ?? '');
        }

        foreach ($slugs as $slug) {
            if (trim($existingPages[$slug] ?? '') === '') {
                return true;
            }
        }

        return false;
    }

    public static function needsAssetExtraction(string $siteRootPath): bool {
        $assetBaseDir = rtrim($siteRootPath, "/") . "/backend/uploads/sitebuilder";

        foreach (self::getPages() as $page) {
            foreach ($page['assets'] as $asset) {
                $assetPath = self::normalizePath($assetBaseDir . "/" . $asset);
                if (!is_file($assetPath)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function seed(PDO $conn, string $siteRootPath, string $siteBuilderPath): void {
        if (
            (
                !self::needsSeeding($conn)
                && !self::needsAssetExtraction($siteRootPath)
            )
            || !is_readable($siteBuilderPath)
            || !class_exists('ZipArchive')
        ) {
            return;
        }

        $archive = self::openArchive($siteBuilderPath);
        if (!is_array($archive)) {
            return;
        }

        try {
            foreach (self::getPages() as $page) {
                self::extractAssets($archive['zip'], $siteRootPath, $page['assets']);
                self::upsertPage($conn, $page);
            }
        } finally {
            $archive['zip']->close();
            self::deleteTempZip($archive['tempZipPath']);
        }
    }

    /**
     * @return array{zip: ZipArchive, tempZipPath: string}|null
     */
    private static function openArchive(string $siteBuilderPath): ?array {
        $archiveBytes = @file_get_contents($siteBuilderPath);
        if (!is_string($archiveBytes) || $archiveBytes === "") {
            return null;
        }

        $zipOffset = strpos($archiveBytes, "PK\x03\x04");
        if ($zipOffset === false) {
            return null;
        }

        $tempZipPath = tempnam(sys_get_temp_dir(), "sitebuilder-pages-");
        if ($tempZipPath === false) {
            return null;
        }

        if (@file_put_contents($tempZipPath, substr($archiveBytes, $zipOffset)) === false) {
            self::deleteTempZip($tempZipPath);
            return null;
        }

        $zip = new ZipArchive();
        if ($zip->open($tempZipPath) !== true) {
            self::deleteTempZip($tempZipPath);
            return null;
        }
        return [
            'zip' => $zip,
            'tempZipPath' => $tempZipPath,
        ];
    }

    /**
     * @param list<string> $assets
     */
    private static function extractAssets(ZipArchive $zip, string $siteRootPath, array $assets): void {
        $assetBaseDir = rtrim($siteRootPath, "/") . "/backend/uploads/sitebuilder";
        if (!is_dir($assetBaseDir) && !mkdir($assetBaseDir, 0755, true) && !is_dir($assetBaseDir)) {
            return;
        }

        $resolvedBaseDir = realpath($assetBaseDir);
        if ($resolvedBaseDir === false) {
            return;
        }

        $normalizedBaseDir = self::normalizePath($resolvedBaseDir);

        foreach ($assets as $asset) {
            $decodedAsset = rawurldecode($asset);
            if (
                !str_starts_with($asset, "gallery/")
                || str_contains($asset, "..")
                || str_contains($decodedAsset, '..')
                || str_starts_with($asset, '/')
            ) {
                continue;
            }

            $destPath = self::normalizePath($assetBaseDir . "/" . $asset);
            if (!str_starts_with($destPath, $normalizedBaseDir . '/')) {
                continue;
            }

            if (is_file($destPath)) {
                continue;
            }
            $contents = $zip->getFromName($asset);
            if (!is_string($contents)) {
                continue;
            }
            $dir = dirname($destPath);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                continue;
            }
            $resolvedDir = realpath($dir);
            if ($resolvedDir === false || ($resolvedDir !== $resolvedBaseDir && !str_starts_with($resolvedDir, $resolvedBaseDir . DIRECTORY_SEPARATOR))) {
                continue;
            }
            if (file_put_contents($destPath, $contents) === false) {
                error_log('Unable to write SiteBuilder asset: ' . $asset);
            }
        }
    }

    /**
     * @param array{
     *     slug: string,
     *     title: string,
     *     meta_description: string,
     *     meta_keywords: string,
     *     og_title: string,
     *     og_description: string,
     *     og_image: string,
     *     sort_order: int,
     *     assets: list<string>,
     *     html_encoded: string
     * } $page
     */
    private static function upsertPage(PDO $conn, array $page): void {
        $check = $conn->prepare("SELECT id, html_content FROM site_pages WHERE slug = ? LIMIT 1");
        $check->execute([$page['slug']]);
        $existing = $check->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing) && trim(self::stringValue($existing['html_content'] ?? '')) !== '') {
            return;
        }

        $html = self::decode($page['html_encoded']);
        $css = self::decode(self::COMMON_CSS_ENCODED);
        if ($html === "" || $css === "") {
            return;
        }

        if (is_array($existing)) {
            $stmt = $conn->prepare("UPDATE site_pages SET slug=?, title=?, html_content=?, css_content=?, meta_description=?, meta_keywords=?, og_title=?, og_description=?, og_image=?, sort_order=?, is_published=1, updated_at=CURRENT_TIMESTAMP WHERE id=?");
            $stmt->execute([$page['slug'], $page['title'], $html, $css, $page['meta_description'], $page['meta_keywords'], $page['og_title'], $page['og_description'], $page['og_image'], $page['sort_order'], self::intValue($existing['id'])]);
            return;
        }

        $stmt = $conn->prepare("INSERT INTO site_pages (slug, title, html_content, css_content, meta_description, meta_keywords, og_title, og_description, og_image, is_homepage, is_published, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?)");
        $stmt->execute([$page['slug'], $page['title'], $html, $css, $page['meta_description'], $page['meta_keywords'], $page['og_title'], $page['og_description'], $page['og_image'], $page['sort_order']]);
    }

    private static function decode(mixed $encoded): string {
        if (!is_string($encoded) || $encoded === "") {
            return "";
        }
        $compressed = base64_decode($encoded, true);
        if (!is_string($compressed)) {
            return "";
        }
        $decoded = zlib_decode($compressed);
        return is_string($decoded) ? $decoded : "";
    }

    private static function stringValue(mixed $value): string {
        return is_string($value) ? $value : '';
    }

    private static function intValue(mixed $value): int {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }

    private static function normalizePath(string $path): string {
        $segments = preg_split('~/+~', str_replace('\\', '/', $path));
        if ($segments === false) {
            return $path;
        }

        $normalized = [];
        foreach ($segments as $index => $segment) {
            if ($segment === '' && $index === 0) {
                $normalized[] = '';
                continue;
            }

            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if (count($normalized) > 1 || (count($normalized) === 1 && $normalized[0] !== '')) {
                    array_pop($normalized);
                }
                continue;
            }

            $normalized[] = $segment;
        }

        if ($normalized === ['']) {
            return '/';
        }

        return implode('/', $normalized);
    }

    private static function deleteTempZip(string $tempZipPath): void {
        // nosemgrep: php.lang.security.unlink-use.unlink-use
        @unlink($tempZipPath);
    }
}
