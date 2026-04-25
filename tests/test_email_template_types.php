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

/**
 * @return array<string, array<string, string>>
 */
function bdta_extract_template_types(string $templates_list): array
{
    if (preg_match('/\\$template_types\\s*=\\s*(\\[(?:.|\\n)*?\\n\\s*\\]);/', $templates_list, $matches) !== 1) {
        fwrite(STDERR, "Unable to locate template type metadata.\n");
        exit(1);
    }

    $template_types = eval('return ' . $matches[1] . ';');
    if (!is_array($template_types)) {
        fwrite(STDERR, "Template type metadata did not evaluate to an array.\n");
        exit(1);
    }

    return $template_types;
}

function bdta_render_type_line(array $template_types, string $template_type): string
{
    $type_info = $template_types[$template_type] ?? ['icon' => 'envelope', 'color' => 'secondary'];

    ob_start();
    ?>
<p class="text-muted small mb-2">
    <strong>Type:</strong> <?php echo htmlspecialchars($type_info['label'] ?? ucwords(str_replace('_', ' ', $template_type))); ?>
</p>
<?php

    return trim((string) ob_get_clean());
}

$templates_edit = bdta_read_fixture(dirname(__DIR__) . '/client/email_templates_edit.php');
$templates_list = bdta_read_fixture(dirname(__DIR__) . '/client/email_templates_list.php');
$template_types = bdta_extract_template_types($templates_list);

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
    str_contains(bdta_render_type_line($template_types, 'workflow'), '<strong>Type:</strong> Workflow Emails'),
    'Email template list should render the workflow label as Workflow Emails.'
);

bdta_assert(
    str_contains(bdta_render_type_line($template_types, 'other'), '<strong>Type:</strong> Other'),
    'Email template list should render the other label as Other.'
);

echo "Email template type option checks passed.\n";
