#!/usr/bin/env php
<?php

define('DEFAULT_LOCALHOST_URL', 'http://localhost:8000');

require_once dirname(__DIR__) . '/backend/includes/blog_cover_photo.php';

function assertBlogCoverPhotoTest(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

assertBlogCoverPhotoTest(
    bdta_is_valid_blog_cover_photo_path('/backend/uploads/blog/example.jpg') === true,
    'Expected root-relative blog cover photo paths to be accepted.'
);
assertBlogCoverPhotoTest(
    bdta_is_valid_blog_cover_photo_path('https://example.com/example.jpg') === true,
    'Expected HTTPS blog cover photo URLs to be accepted.'
);
assertBlogCoverPhotoTest(
    bdta_is_valid_blog_cover_photo_path('javascript:alert(1)') === false,
    'Expected non-HTTP blog cover photo URLs to be rejected.'
);
assertBlogCoverPhotoTest(
    bdta_is_valid_blog_cover_photo_path('//evil.example/image.jpg') === false,
    'Expected protocol-relative blog cover photo paths to be rejected.'
);
assertBlogCoverPhotoTest(
    bdta_is_valid_blog_cover_photo_path('/backend/uploads/blog/../../secret.jpg') === false,
    'Expected parent-relative blog cover photo paths to be rejected.'
);
assertBlogCoverPhotoTest(
    bdta_normalize_blog_cover_photo_path(' /backend/uploads/blog/example.jpg ') === '/backend/uploads/blog/example.jpg',
    'Expected valid blog cover photo paths to be trimmed and preserved.'
);
assertBlogCoverPhotoTest(
    bdta_normalize_blog_cover_photo_path('javascript:alert(1)') === '',
    'Expected invalid blog cover photo paths to normalize to an empty string.'
);
assertBlogCoverPhotoTest(
    bdta_get_blog_cover_photo_absolute_url('/backend/uploads/blog/example.jpg', 'https://bdta.test/') === 'https://bdta.test/backend/uploads/blog/example.jpg',
    'Expected root-relative blog cover photo paths to expand to absolute URLs.'
);
assertBlogCoverPhotoTest(
    bdta_get_blog_cover_photo_absolute_url('https://cdn.example.com/cover.jpg', 'https://bdta.test/') === 'https://cdn.example.com/cover.jpg',
    'Expected absolute blog cover photo URLs to remain unchanged.'
);
assertBlogCoverPhotoTest(
    bdta_get_blog_cover_photo_absolute_url('/backend/uploads/blog/example.jpg') === 'http://localhost:8000/backend/uploads/blog/example.jpg',
    'Expected helper to fall back to the default localhost base URL when none is provided.'
);

echo "Blog cover photo helper tests passed.\n";
