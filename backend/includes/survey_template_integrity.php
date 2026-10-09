<?php
require_once __DIR__ . '/form_types.php';

/**
 * Positional survey answers depend on the original question order and meaning.
 * Keep used definitions intact; a duplicate starts a separate, editable survey.
 *
 * @param array<string, mixed> $template
 */
function bdta_survey_template_structure_is_locked(array $template, bool $has_submissions): bool
{
    return $has_submissions
        && bdta_normalize_form_type(array_string_value($template, 'form_type')) === 'survey_form';
}

/**
 * @param array<string, mixed> $template
 * @param list<array<string, mixed>> $proposed_fields
 */
function bdta_validate_survey_template_edit(
    array $template,
    string $proposed_form_type,
    array $proposed_fields,
    bool $has_submissions
): void {
    if (!$has_submissions) {
        return;
    }

    $current_type = bdta_normalize_form_type(array_string_value($template, 'form_type'));
    $proposed_type = bdta_normalize_form_type($proposed_form_type);
    if ($current_type !== 'survey_form' && $proposed_type !== 'survey_form') {
        return;
    }

    $current_fields = decode_json_assoc_list(array_string_value($template, 'fields'));
    if ($current_type !== $proposed_type || $current_fields !== $proposed_fields) {
        throw new RuntimeException(
            'This template has survey requests or responses. Its form type and questions cannot be changed because existing answers use the original question order. Duplicate the template to create a revised survey; existing requests and answers will stay with this version.'
        );
    }
}

function bdta_form_template_has_submissions(PDO $conn, int $template_id): bool
{
    $stmt = $conn->prepare('SELECT id FROM form_submissions WHERE template_id = ? LIMIT 1');
    $stmt->execute([$template_id]);
    return $stmt->fetchColumn() !== false;
}
