#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once dirname(__DIR__) . '/backend/includes/social_links.php';

function assertTrue(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$links = bdta_collect_social_links([
    'facebook_url' => 'https://facebook.example/business',
    'instagram_url' => '',
    'nextdoor_url' => 'https://nextdoor.com/pages/bdta',
    'bluesky_url' => 'https://bsky.app/profile/bdta.example',
    'linktree_url' => 'javascript:alert(1)',
    'youtube_url' => 'https://youtube.com/@bdta',
    'custom_social_link_1_label' => 'Podcast',
    'custom_social_link_1_url' => 'https://example.com/podcast',
    'custom_social_link_2_url' => 'https://www.books.example/store',
]);

assertTrue(count($links) === 6, 'Expected only valid configured social links to be collected.');
$linksByName = [];
foreach ($links as $link) {
    $linksByName[$link['name']] = $link;
}

assertTrue(isset($linksByName['Facebook']), 'Expected Facebook label to be normalized.');
assertTrue(($linksByName['Nextdoor']['icon'] ?? '') === 'fas fa-house', 'Expected Nextdoor to use the house icon.');
assertTrue(($linksByName['Bluesky']['icon'] ?? '') === 'custom:bluesky-butterfly', 'Expected Bluesky to use the custom butterfly icon.');
assertTrue(isset($linksByName['YouTube']), 'Expected YouTube link to be included.');
assertTrue(isset($linksByName['Podcast']), 'Expected custom link label to be used.');
assertTrue(isset($linksByName['books.example']), 'Expected unlabeled custom links to fall back to their host name.');

$html = <<<HTML
<!-- BDTA_SOCIAL_LINKS:events -->
old events markup
<!-- /BDTA_SOCIAL_LINKS:events -->
<!-- BDTA_SOCIAL_LINKS:contact -->
old contact markup
<!-- /BDTA_SOCIAL_LINKS:contact -->
<!-- BDTA_SOCIAL_LINKS:footer -->
old footer markup
<!-- /BDTA_SOCIAL_LINKS:footer -->
HTML;

$rendered = bdta_apply_public_social_links($html, $links);

assertTrue(!str_contains($rendered, 'old events markup'), 'Expected events placeholder content to be replaced.');
assertTrue(str_contains($rendered, 'https://youtube.com/@bdta'), 'Expected configured built-in links to render.');
assertTrue(str_contains($rendered, 'fas fa-house'), 'Expected Nextdoor house icon markup to render.');
assertTrue(str_contains($rendered, '<svg viewBox="0 0 24 24"'), 'Expected Bluesky butterfly SVG markup to render.');
assertTrue(str_contains($rendered, 'Podcast'), 'Expected custom link labels to render.');
assertTrue(!str_contains($rendered, 'javascript:alert(1)'), 'Expected invalid URLs to be excluded from rendered markup.');

$empty = bdta_apply_public_social_links($html, []);
assertTrue(!str_contains($empty, 'old contact markup'), 'Expected placeholders to still be replaced when no links exist.');
assertTrue(!str_contains($empty, 'Follow Us'), 'Expected empty social sections to be hidden.');

echo "Social links helper test passed.\n";
