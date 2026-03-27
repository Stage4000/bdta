<?php

require_once __DIR__ . '/backend/includes/sitebuilder_manual_pages.php';

function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function makeTempDir(string $prefix): string {
    $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create temp dir.');
    }
    return $dir;
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
            // nosemgrep: php.lang.security.unlink-use.unlink-use
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
    $pdo->exec("INSERT INTO site_pages (slug, title, html_content, css_content, is_homepage, is_published, sort_order) VALUES ('legacy-directory', 'Directory', '<p>legacy</p>', '', 0, 1, 99)");
    return $pdo;
}

function createAssetOnlySiteBuilderArchive(string $archivePath): void {
    $zipPath = $archivePath . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create temp zip.');
    }

    $assets = [
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
        'gallery/242159751_135250202159875_240629272138812113_n.png',
        'gallery/464713509_122115682856547478_6511436599214494982_n.jpg',
        'gallery/Heading (2).png',
        'gallery/Your business here.png',
        'gallery/highlands hammock entrance.jpg',
    ];

    foreach ($assets as $asset) {
        $zip->addFromString($asset, 'asset:' . $asset);
    }

    $zip->close();
    file_put_contents($archivePath, 'sitebuilder-custom-header' . file_get_contents($zipPath));
    // nosemgrep: php.lang.security.unlink-use.unlink-use
    @unlink($zipPath);
}

$tmpDir = makeTempDir('bdta-manual-sitebuilder-pages');

try {
    mkdir($tmpDir . '/backend/uploads', 0777, true);
    $dbPath = $tmpDir . '/target.db';
    $archivePath = $tmpDir . '/source.sitebuilder';
    $pdo = createTargetDb($dbPath);
    createAssetOnlySiteBuilderArchive($archivePath);

    assertTrue(SiteBuilderManualPageSeeder::needsSeeding($pdo), 'Expected manual pages to need seeding before import.');

    SiteBuilderManualPageSeeder::seed($pdo, $tmpDir, $archivePath);
    SiteBuilderManualPageSeeder::seed($pdo, $tmpDir, $archivePath);

    assertTrue(!SiteBuilderManualPageSeeder::needsSeeding($pdo), 'Expected manual pages not to need reseeding after import.');

    $pages = $pdo->query("SELECT slug, title, is_published, og_image, length(html_content) AS html_len, length(css_content) AS css_len FROM site_pages WHERE is_homepage = 0 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    assertTrue(count($pages) === 3, 'Expected the two manually requested pages plus the unrelated pre-existing page.');
    assertTrue($pages[0]['slug'] === 'dog-training-fact-sheet', 'Expected fact sheet page to be seeded first.');
    assertTrue($pages[1]['slug'] === 'directory', 'Expected directory page to be seeded second.');
    assertTrue($pages[2]['slug'] === 'legacy-directory', 'Expected unrelated legacy page to remain untouched.');
    assertTrue((int) $pages[0]['is_published'] === 1 && (int) $pages[1]['is_published'] === 1, 'Expected both seeded pages to be published.');
    assertTrue((int) $pages[0]['html_len'] > 1000 && (int) $pages[1]['html_len'] > 1000, 'Expected imported HTML content for both seeded pages.');
    assertTrue((int) $pages[0]['css_len'] > 1000 && (int) $pages[1]['css_len'] > 1000, 'Expected imported CSS content for both seeded pages.');
    assertTrue((int) $pages[2]['html_len'] < 1000, 'Expected the unrelated legacy page not to be overwritten by the manual seed.');
    assertTrue($pages[0]['og_image'] === '/backend/uploads/sitebuilder/gallery/Dog-Training-Fact-Sheet-1.png', 'Expected fact sheet OG image path to be preserved.');

    $factSheetHtml = $pdo->query("SELECT html_content FROM site_pages WHERE slug = 'dog-training-fact-sheet'")->fetchColumn();
    $directoryHtml = $pdo->query("SELECT html_content FROM site_pages WHERE slug = 'directory'")->fetchColumn();
    assertTrue(is_string($factSheetHtml) && str_contains($factSheetHtml, '/backend/uploads/sitebuilder/gallery/Dog-Training-Fact-Sheet-1.png'), 'Expected fact sheet page body to be present.');
    assertTrue(is_string($directoryHtml) && str_contains($directoryHtml, '/backend/uploads/sitebuilder/gallery/Your%20business%20here.png'), 'Expected directory page body to be present.');

    assertTrue(is_file($tmpDir . '/backend/uploads/sitebuilder/gallery/Dog-Training-Fact-Sheet-1.png'), 'Expected fact sheet assets to be extracted.');
    assertTrue(is_file($tmpDir . '/backend/uploads/sitebuilder/gallery/Your business here.png'), 'Expected directory assets to be extracted.');

    echo "Manual SiteBuilder page seed test passed.\n";
} finally {
    rrmdir($tmpDir);
}
