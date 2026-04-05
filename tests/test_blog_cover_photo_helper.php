<?php

require_once dirname(__DIR__) . '/backend/includes/base_url_helper.php';
require_once dirname(__DIR__) . '/backend/includes/blog_cover_photo.php';

function assertSameValue(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . $expected . "\nActual: " . $actual);
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$upload_path = '/backend/uploads/blog_covers/cover-example.webp';
$upload_dir = dirname(__DIR__) . '/backend/uploads/blog_covers';
$upload_file_path = $upload_dir . '/cover-example.webp';

if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
    throw new RuntimeException('Unable to create the temporary blog cover upload directory.');
}

file_put_contents($upload_file_path, 'cover-photo-test');

assertTrue(
    bdta_is_blog_cover_photo_upload_path($upload_path),
    'Expected the blog cover upload path to be accepted.'
);

assertTrue(
    !bdta_is_blog_cover_photo_upload_path('/backend/uploads/seo/cover-example.webp'),
    'Expected non-blog upload directories to be rejected.'
);

assertTrue(
    !bdta_is_blog_cover_photo_upload_path('/backend/uploads/blog_covers/../../etc/passwd'),
    'Expected traversal attempts to be rejected.'
);

assertSameValue(
    'https://example.com/backend/uploads/blog_covers/cover-example.webp',
    bdta_get_blog_cover_photo_meta_url($upload_path, 'https://example.com/site/page'),
    'Expected relative blog cover paths to be resolved to an absolute base URL.'
);

assertSameValue(
    'https://cdn.example.com/cover.jpg',
    bdta_get_blog_cover_photo_meta_url('https://cdn.example.com/cover.jpg', 'https://example.com'),
    'Expected absolute cover photo URLs to be preserved.'
);

assertSameValue(
    '',
    bdta_get_blog_cover_photo_meta_url('javascript:alert(1)', 'https://example.com'),
    'Expected non-http URL schemes to be rejected for blog cover metadata.'
);

assertSameValue(
    '',
    bdta_get_blog_cover_photo_meta_url('/backend/uploads/seo/cover.jpg', 'https://example.com'),
    'Expected unsupported local image paths to be rejected for blog cover metadata.'
);

assertTrue(
    str_ends_with(
        bdta_get_blog_cover_photo_filesystem_path($upload_path),
        '/backend/uploads/blog_covers/cover-example.webp'
    ),
    'Expected the filesystem helper to map the public upload path to the repo-local file path.'
);

unlink($upload_file_path);

echo "Blog cover photo helper tests passed.\n";
