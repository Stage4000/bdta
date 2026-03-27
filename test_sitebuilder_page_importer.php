<?php

require_once __DIR__ . '/backend/includes/sitebuilder_page_importer.php';

function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function makeTempDir(string $prefix): string {
    $base = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
    if (!mkdir($base, 0777, true) && !is_dir($base)) {
        throw new RuntimeException('Unable to create temporary directory.');
    }
    return $base;
}

function rrmdir(string $path): void {
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $itemPath = $path . '/' . $item;
        if (is_dir($itemPath)) {
            rrmdir($itemPath);
        } else {
            @unlink($itemPath);
        }
    }
    @rmdir($path);
}

function createTargetDb(string $dbPath): PDO {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        "CREATE TABLE site_pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT NOT NULL UNIQUE,
            title TEXT NOT NULL,
            html_content TEXT,
            css_content TEXT,
            meta_description TEXT,
            is_homepage INTEGER NOT NULL DEFAULT 0,
            is_published INTEGER NOT NULL DEFAULT 0,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_by INTEGER,
            meta_keywords TEXT,
            og_title TEXT,
            og_description TEXT,
            og_image TEXT
        )"
    );
    $pdo->exec("INSERT INTO site_pages (slug, title, is_homepage, is_published, sort_order) VALUES ('home', 'Home', 1, 0, 0)");
    return $pdo;
}

function createSourceDb(string $dbPath): void {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE pages (id VARCHAR(32) PRIMARY KEY, parentId VARCHAR(32), isFront INTEGER NOT NULL DEFAULT 0, type INTEGER NOT NULL DEFAULT 0, sortOrder INTEGER NOT NULL DEFAULT 0, data TEXT)");
    $pdo->exec("CREATE TABLE elements (id VARCHAR(32) PRIMARY KEY, pageId VARCHAR(32), parentId VARCHAR(32), referenceTo VARCHAR(32), isBackground INTEGER NOT NULL DEFAULT 0, class VARCHAR(64) NOT NULL, rootBlockType VARCHAR(8), sortOrder INTEGER NOT NULL DEFAULT 0, data TEXT)");
    $pdo->exec("CREATE TABLE options (key VARCHAR(32) PRIMARY KEY, value TEXT)");

    $siteOptions = [
        'class' => 'Site',
        'content' => [
            'styles' => [
                [
                    'selector' => '.wb-stl-normal',
                    'sys' => [
                        'text' => [
                            'css' => [
                                'font-family' => "'Poppins', Arial, sans-serif",
                                'font-size' => '18px',
                                'line-height' => '28px',
                                'color' => '#333333',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
    $stmt = $pdo->prepare("INSERT INTO options (key, value) VALUES ('__Site__', ?)");
    $stmt->execute([json_encode($siteOptions, JSON_THROW_ON_ERROR)]);

    $pages = [
        ['id' => 'page-home', 'isFront' => 1, 'sortOrder' => 0, 'data' => ['enabled' => true, 'name' => 'Home', 'title' => 'Home']],
        ['id' => 'page-connect', 'isFront' => 0, 'sortOrder' => 1, 'data' => ['enabled' => true, 'name' => 'Connect', 'title' => 'Connect SEO Title', 'description' => 'Connect description', 'keywords' => 'connect,newsletter', 'image' => 'gallery/hero image.png']],
        ['id' => 'page-pet-sitting', 'isFront' => 0, 'sortOrder' => 2, 'data' => ['enabled' => true, 'name' => 'Pet Sitting', 'title' => 'Pet Sitting SEO Title', 'description' => 'Pet sitting description']],
    ];

    $insertPage = $pdo->prepare("INSERT INTO pages (id, parentId, isFront, type, sortOrder, data) VALUES (?, NULL, ?, 0, ?, ?)");
    foreach ($pages as $page) {
        $insertPage->execute([
            $page['id'],
            $page['isFront'],
            $page['sortOrder'],
            json_encode($page['data'], JSON_THROW_ON_ERROR),
        ]);
    }

    $insertElement = $pdo->prepare(
        "INSERT INTO elements (id, pageId, parentId, referenceTo, isBackground, class, rootBlockType, sortOrder, data)
         VALUES (?, ?, ?, NULL, 0, ?, NULL, ?, ?)"
    );

    $layoutData = [
        'width' => '100%',
        'height' => 'auto',
        'minWidth' => 100,
        'minHeight' => 100,
        'maxWidth' => 1200,
        'maxHeight' => 600,
        'flexGrow' => 1,
        'flexShrink' => 1,
        'content' => [
            'layout' => [
                'type' => 'vertical',
                'wrap' => 'nowrap',
                'wrapRes' => '',
                'hAlign' => 'center',
                'vAlign' => 'stretch',
                'hSpacing' => 'center',
                'vSpacing' => 'flex-start',
            ],
        ],
    ];

    $insertElement->execute(['connect-layout', 'page-connect', null, 'LayoutElement', 0, json_encode($layoutData, JSON_THROW_ON_ERROR)]);
    $insertElement->execute([
        'connect-text',
        'page-connect',
        'connect-layout',
        'TextArea',
        0,
        json_encode([
            'width' => 'auto',
            'height' => 'auto',
            'minWidth' => 24,
            'minHeight' => 24,
            'maxWidth' => 1200,
            'maxHeight' => 600,
            'content' => ['text' => '<p class="wb-stl-normal">Connect content imported from SiteBuilder.</p>'],
        ], JSON_THROW_ON_ERROR),
    ]);
    $insertElement->execute([
        'connect-image',
        'page-connect',
        'connect-layout',
        'Picture',
        1,
        json_encode([
            'width' => '100%',
            'height' => 'auto',
            'minWidth' => 100,
            'minHeight' => 100,
            'maxWidth' => 500,
            'maxHeight' => 500,
            'content' => ['src' => 'gallery/hero image.png'],
        ], JSON_THROW_ON_ERROR),
    ]);
    $insertElement->execute([
        'connect-button',
        'page-connect',
        'connect-layout',
        'Button',
        2,
        json_encode([
            'width' => 'auto',
            'height' => 'auto',
            'minWidth' => 24,
            'minHeight' => 24,
            'maxWidth' => 1200,
            'maxHeight' => 600,
            'content' => [
                'text' => 'Book Pet Sitting',
                'link' => ['type' => 'page', 'url' => 'page-pet-sitting'],
            ],
        ], JSON_THROW_ON_ERROR),
    ]);
    $insertElement->execute([
        'connect-html',
        'page-connect',
        'connect-layout',
        'CustomHtml',
        3,
        json_encode([
            'width' => 'auto',
            'height' => 'auto',
            'minWidth' => 24,
            'minHeight' => 24,
            'maxWidth' => 1200,
            'maxHeight' => 600,
            'content' => ['html' => '<div class="promo"><img src="gallery/custom badge.png" alt="Custom badge"></div>'],
        ], JSON_THROW_ON_ERROR),
    ]);

    $insertElement->execute(['pet-layout', 'page-pet-sitting', null, 'LayoutElement', 0, json_encode($layoutData, JSON_THROW_ON_ERROR)]);
    $insertElement->execute([
        'pet-text',
        'page-pet-sitting',
        'pet-layout',
        'TextArea',
        0,
        json_encode([
            'width' => 'auto',
            'height' => 'auto',
            'minWidth' => 24,
            'minHeight' => 24,
            'maxWidth' => 1200,
            'maxHeight' => 600,
            'content' => ['text' => '<p class="wb-stl-normal">Pet sitting details imported from SiteBuilder.</p>'],
        ], JSON_THROW_ON_ERROR),
    ]);
}

function createSiteBuilderArchive(string $archivePath, string $sourceDbPath): void {
    $zipPath = $archivePath . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create temporary ZIP archive.');
    }
    $zip->addFile($sourceDbPath, 'dat/project.db');
    $zip->addFromString('gallery/hero image.png', 'hero image bytes');
    $zip->addFromString('gallery/custom badge.png', 'custom badge bytes');
    $zip->close();

    $header = 'sitebuilder-custom-header-with-padding-bytes';
    file_put_contents($archivePath, $header . file_get_contents($zipPath));
    @unlink($zipPath);
}

$tmpDir = makeTempDir('bdta-sitebuilder-import-test');

try {
    mkdir($tmpDir . '/backend/uploads', 0777, true);
    file_put_contents(
        $tmpDir . '/index.html',
        <<<HTML
<!DOCTYPE html>
<html lang="en">
<body>
<nav><a href="#home">Home</a><a href="blog/index.php">Blog</a></nav>
<footer><a href="#contact">Contact</a></footer>
</body>
</html>
HTML
    );

    $sourceDbPath = $tmpDir . '/project.db';
    $targetDbPath = $tmpDir . '/target.db';
    $siteBuilderPath = $tmpDir . '/source.sitebuilder';

    createSourceDb($sourceDbPath);
    createSiteBuilderArchive($siteBuilderPath, $sourceDbPath);
    $targetPdo = createTargetDb($targetDbPath);

    $importer = new SiteBuilderPageImporter($targetPdo, $siteBuilderPath, $tmpDir);
    $importer->import();
    $firstPassPages = $targetPdo->query("SELECT slug, html_content FROM site_pages WHERE is_homepage = 0 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    assertTrue(count($firstPassPages) === 2, 'Expected two imported non-home pages after the first import.');
    $importer->import();

    $pages = $targetPdo->query("SELECT slug, title, html_content, css_content, og_image FROM site_pages WHERE is_homepage = 0 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    assertTrue(count($pages) === 2, 'Expected exactly two imported non-home pages.');
    assertTrue($pages[0]['html_content'] === $firstPassPages[0]['html_content'], 'Expected repeated imports to leave existing imported content unchanged.');
    $totalPages = (int) $targetPdo->query("SELECT COUNT(*) FROM site_pages")->fetchColumn();
    assertTrue($totalPages === 3, 'Expected the builder home page to be excluded from import.');

    $connectPage = $pages[0];
    assertTrue($connectPage['slug'] === 'connect', 'Expected Connect page slug to be imported.');
    assertTrue(str_contains($connectPage['html_content'], 'Connect content imported from SiteBuilder.'), 'Expected Connect body content to be imported.');
    assertTrue(str_contains($connectPage['html_content'], 'href="/#home"'), 'Expected imported shell nav links to point back to the homepage.');
    assertTrue(str_contains($connectPage['html_content'], 'href="/page.php?slug=pet-sitting"'), 'Expected button link to target the imported Pet Sitting page.');
    assertTrue(str_contains($connectPage['html_content'], '/backend/uploads/sitebuilder/gallery/hero%20image.png'), 'Expected image paths to be rewritten to extracted asset URLs.');
    assertTrue(str_contains($connectPage['html_content'], '/backend/uploads/sitebuilder/gallery/custom%20badge.png'), 'Expected custom HTML asset paths to be rewritten.');
    assertTrue(str_contains($connectPage['css_content'], '.wb-stl-normal'), 'Expected builder text styles to be carried into imported CSS.');
    assertTrue($connectPage['og_image'] === '/backend/uploads/sitebuilder/gallery/hero%20image.png', 'Expected OG image path to be rewritten.');

    assertTrue(is_file($tmpDir . '/backend/uploads/sitebuilder/gallery/hero image.png'), 'Expected gallery asset to be extracted for serving.');
    assertTrue(is_file($tmpDir . '/backend/uploads/sitebuilder/gallery/custom badge.png'), 'Expected custom HTML asset to be extracted for serving.');

    echo "SiteBuilder page import test passed.\n";
} finally {
    rrmdir($tmpDir);
}
