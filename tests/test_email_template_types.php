#!/usr/bin/env php
<?php

function bdta_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function bdta_read_fixture(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        fwrite(STDERR, 'Unable to read fixture: ' . $path . PHP_EOL);
        exit(1);
    }

    return $contents;
}

$templates_edit = bdta_read_fixture(dirname(__DIR__) . '/client/email_templates_edit.php');
$templates_list = bdta_read_fixture(dirname(__DIR__) . '/client/email_templates_list.php');

bdta_assert(
    str_contains($templates_edit, '<option value="workflow"') &&
    str_contains($templates_edit, '>Workflow Emails</option>'),
    'Email template editor should allow categorizing templates as workflow emails.'
);

bdta_assert(
    str_contains($templates_edit, '<option value="other"') &&
    str_contains($templates_edit, '>Other</option>'),
    'Email template editor should allow categorizing templates as other.'
);

bdta_assert(
    str_contains($templates_list, "'workflow' => ['label' => 'Workflow Emails', 'icon' => 'sitemap', 'color' => 'dark']"),
    'Email template list should define display metadata and the correct label for workflow templates.'
);

bdta_assert(
    str_contains($templates_list, "'other' => ['label' => 'Other', 'icon' => 'folder-open', 'color' => 'secondary']"),
    'Email template list should define display metadata and the correct label for other templates.'
);

bdta_assert(
    str_contains($templates_list, "htmlspecialchars(\$type_info['label'] ?? ucwords(str_replace('_', ' ', \$template['template_type'])))"),
    'Email template list should render the explicit mapped label when one is defined.'
);

echo "Email template type option checks passed.\n";
