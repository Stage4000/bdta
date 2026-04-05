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

$active_content = '<iframe srcdoc="<script>alert(1)</script>"></iframe><svg><a xlink:href="javascript:alert(1)">Bad</a></svg><math><mi>x</mi></math><p>Safe text</p>';
$sanitized_active_content = bdta_sanitize_blog_post_content($active_content);
assertBlogContentTest(strpos($sanitized_active_content, '<iframe') === false, 'Expected iframe elements to be removed.');
assertBlogContentTest(strpos($sanitized_active_content, '<svg') === false, 'Expected svg elements to be removed.');
assertBlogContentTest(strpos($sanitized_active_content, '<math') === false, 'Expected math elements to be removed.');
assertBlogContentTest(strpos($sanitized_active_content, 'xlink:href') === false, 'Expected namespaced javascript URLs to be removed with active content containers.');
assertBlogContentTest(strpos($sanitized_active_content, '<p>Safe text</p>') !== false, 'Expected safe neighboring content to be preserved.');

$relative_paths = '<a href="../private/asset.css">Bad path</a><a href="/blog/good">Good path</a><img src="images/photo.jpg">';
$sanitized_paths = bdta_sanitize_blog_post_content($relative_paths);
assertBlogContentTest(strpos($sanitized_paths, '../private/asset.css') === false, 'Expected parent-relative URLs to be removed.');
assertBlogContentTest(strpos($sanitized_paths, 'href="/blog/good"') !== false, 'Expected root-relative URLs to be preserved.');
assertBlogContentTest(strpos($sanitized_paths, 'src="images/photo.jpg"') !== false, 'Expected simple relative asset URLs to be preserved.');

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

$fallback_input = <<<HTML
<!-- comment -->
<html>
  <head><title>Ignored</title></head>
  <body>
    <p onclick="evil()">Keep me</p>
    <a href="javascript:alert(1)">Bad link</a>
    <img src="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">
    <iframe srcdoc="<script>alert(1)</script>"></iframe>
  </body>
</html>
HTML;

$fallback_output = bdta_sanitize_blog_post_content_fallback($fallback_input);
assertBlogContentTest(strpos($fallback_output, '<!--') === false, 'Expected fallback sanitizer to remove comments.');
assertBlogContentTest(strpos($fallback_output, '<html') === false, 'Expected fallback sanitizer to remove html wrappers.');
assertBlogContentTest(strpos($fallback_output, '<head') === false, 'Expected fallback sanitizer to remove head wrappers.');
assertBlogContentTest(strpos($fallback_output, 'onclick=') === false, 'Expected fallback sanitizer to remove inline event handlers.');
assertBlogContentTest(strpos($fallback_output, 'javascript:') === false, 'Expected fallback sanitizer to remove javascript URLs.');
assertBlogContentTest(strpos($fallback_output, 'data:text/html') === false, 'Expected fallback sanitizer to remove unsafe data URLs.');
assertBlogContentTest(strpos($fallback_output, '<iframe') === false, 'Expected fallback sanitizer to remove iframe elements.');
assertBlogContentTest(strpos($fallback_output, '<p>Keep me</p>') !== false, 'Expected fallback sanitizer to preserve safe markup.');

$fallback_safe_data_image = '<img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB">';
$fallback_safe_data_image_output = bdta_sanitize_blog_post_content_fallback($fallback_safe_data_image);
assertBlogContentTest(strpos($fallback_safe_data_image_output, 'data:image/png;base64') !== false, 'Expected fallback sanitizer to preserve safe inline image data URLs.');
assertBlogContentTest(strpos($fallback_safe_data_image_output, 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB') !== false, 'Expected fallback sanitizer to preserve the full inline image data payload.');

echo "Blog content helper tests passed.\n";
