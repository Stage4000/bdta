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

function bdta_extract_template_type_label(string $templates_list, string $template_type): string
{
    $pattern = "/'" . preg_quote($template_type, '/') . "'\\s*=>\\s*\\['label'\\s*=>\\s*'((?:\\\\'|[^'])+)'/";
    if (preg_match($pattern, $templates_list, $matches) !== 1) {
        fwrite(STDERR, "Unable to locate a 'label' entry for template type '{$template_type}' in the \$template_types map." . PHP_EOL);
        exit(1);
    }

    return str_replace("\\'", "'", $matches[1]);
}

function bdta_render_type_line(string $template_type, ?string $label = null): string
{
    $resolved_label = $label ?? ucwords(str_replace('_', ' ', $template_type));

    ob_start();
    ?>
<p class="text-muted small mb-2">
    <strong>Type:</strong> <?php echo htmlspecialchars($resolved_label); ?>
</p>
<?php

    return trim((string) ob_get_clean());
}

$templates_edit = bdta_read_fixture(dirname(__DIR__) . '/client/email_templates_edit.php');
$templates_list = bdta_read_fixture(dirname(__DIR__) . '/client/email_templates_list.php');
$workflow_label = bdta_extract_template_type_label($templates_list, 'workflow');
$other_label = bdta_extract_template_type_label($templates_list, 'other');

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
    str_contains(bdta_render_type_line('workflow', $workflow_label), '<strong>Type:</strong> Workflow Emails'),
    'Email template list should render the workflow label as Workflow Emails.'
);

bdta_assert(
    str_contains(bdta_render_type_line('other', $other_label), '<strong>Type:</strong> Other'),
    'Email template list should render the other label as Other.'
);

echo "Email template type option checks passed.\n";
