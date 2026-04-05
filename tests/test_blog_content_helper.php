#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/blog_content.php';

function assertBlogContentTest(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$html = <<<HTML
<link rel="stylesheet" href="bootstrap.bundle.min.css">
<link rel="stylesheet" href="boostrap.min.css">
<p class="lead">Hello world</p>
<script>alert('xss');</script>
HTML;

$sanitized = bdta_sanitize_blog_post_content($html);
assertBlogContentTest(strpos($sanitized, 'bootstrap.bundle.min.css') === false, 'Expected invalid Bootstrap bundle stylesheet link to be removed.');
assertBlogContentTest(strpos($sanitized, 'boostrap.min.css') === false, 'Expected misspelled Bootstrap stylesheet link to be removed.');
assertBlogContentTest(strpos($sanitized, '<script') === false, 'Expected script tags to be removed from blog content.');
assertBlogContentTest(strpos($sanitized, '<p class="lead">Hello world</p>') !== false, 'Expected safe blog markup to be preserved.');

$unsafe_attributes = '<a href="javascript:alert(1)" onclick="evil()" target="_blank">Read more</a><img src="javascript:alert(1)" onerror="evil()">';
$sanitized_attributes = bdta_sanitize_blog_post_content($unsafe_attributes);
assertBlogContentTest(strpos($sanitized_attributes, 'javascript:') === false, 'Expected javascript: URLs to be removed.');
assertBlogContentTest(strpos($sanitized_attributes, 'onclick=') === false, 'Expected inline event handlers to be removed.');
assertBlogContentTest(strpos($sanitized_attributes, 'onerror=') === false, 'Expected image event handlers to be removed.');
assertBlogContentTest(strpos($sanitized_attributes, 'rel="noopener noreferrer"') !== false, 'Expected external target=_blank links to receive rel protection.');

$wrapped_document = <<<HTML
<html>
  <head>
    <title>Ignored</title>
    <link rel="stylesheet" href="/broken.css">
  </head>
  <body>
    <h2>Blog heading</h2>
    <p>Body copy</p>
  </body>
</html>
HTML;

$sanitized_document = bdta_sanitize_blog_post_content($wrapped_document);
assertBlogContentTest(strpos($sanitized_document, '<h2>Blog heading</h2>') !== false, 'Expected body content to be preserved when a full HTML document is pasted.');
assertBlogContentTest(strpos($sanitized_document, '/broken.css') === false, 'Expected pasted document stylesheets to be removed.');
assertBlogContentTest(strpos($sanitized_document, '<head>') === false, 'Expected head wrappers to be removed.');

echo "Blog content helper tests passed.\n";
