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

function bdta_find_line_containing(string $contents, string $needle): string
{
    $normalized_contents = str_replace(["\r\n", "\r"], "\n", $contents);

    foreach (explode("\n", $normalized_contents) as $line) {
        if (str_contains($line, $needle)) {
            return $line;
        }
    }

    fwrite(STDERR, "Unable to find expected content: {$needle}. Verify the template type metadata or label rendering code in client/email_templates_list.php." . PHP_EOL);
    exit(1);
}

$templates_edit = bdta_read_fixture(dirname(__DIR__) . '/client/email_templates_edit.php');
$templates_list = bdta_read_fixture(dirname(__DIR__) . '/client/email_templates_list.php');
$workflow_line = bdta_find_line_containing($templates_list, "'workflow' =>");
$other_line = bdta_find_line_containing($templates_list, "'other' =>");

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
    str_contains($workflow_line, "'label' => 'Workflow Emails'") &&
    str_contains($workflow_line, "'icon' => 'sitemap'") &&
    str_contains($workflow_line, "'color' => 'dark'"),
    'Email template list should define display metadata and the correct label for workflow templates.'
);

bdta_assert(
    str_contains($other_line, "'label' => 'Other'") &&
    str_contains($other_line, "'icon' => 'folder-open'") &&
    str_contains($other_line, "'color' => 'secondary'"),
    'Email template list should define display metadata and the correct label for other templates.'
);

bdta_assert(
    str_contains($templates_list, "htmlspecialchars(\$type_info['label'] ??") &&
    str_contains($templates_list, "ucwords(str_replace('_', ' ', \$template['template_type']))"),
    'Email template list should render explicit mapped labels while keeping the fallback label formatting.'
);

echo "Email template type option checks passed.\n";
