<?php

function bdta_sync_public_navigation_links(string $html): string {
    $directoryLink = '/page.php?slug=directory';
    if (!str_contains($html, 'href="' . $directoryLink . '"')) {
        $directoryItem = '<li class="nav-item">' . "\n"
            . '                        <a class="nav-link" href="' . $directoryLink . '">Directory</a>' . "\n"
            . '                    </li>' . "\n"
            . '                    ';
        $html = preg_replace(
            '~<li class="nav-item">\s*<a class="nav-link" href="(?:/)?blog/index\.php">Blog</a>\s*</li>\s*~',
            $directoryItem . '$0',
            $html,
            1
        ) ?? $html;
    }

    $html = preg_replace(
        '~<li class="nav-item">\s*<a class="nav-link" href="(?:/)?(?:page\.php\?slug=dog-training-fact-sheet|facts/?(?:index\.php)?)">Dog Training Fact Sheet</a>\s*</li>\s*~',
        '',
        $html
    ) ?? $html;

    return $html;
}
