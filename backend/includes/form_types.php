<?php
/**
 * Helpers for classifying form templates by their funnel/category.
 *
 * Canonical form types are the issue-defined categories. Legacy values are
 * retained here so older records can still be displayed consistently.
 *
 * @return array<string, array<string, mixed>>
 */
function bdta_get_form_type_definitions(): array
{
    return [
        'booking_form' => [
            'label' => 'Booking Form',
            'description' => 'Completed by clients during the booking flow and stored on the client profile.',
            'badge' => 'bg-info text-dark',
            'direct_link' => false,
            'public_submission' => false,
            'force_internal' => 0,
            'legacy' => false,
        ],
        'follow_up_note' => [
            'label' => 'Follow-up Note Form',
            'description' => 'Completed by admin after an appointment and stored with the appointment for reference.',
            'badge' => 'bg-secondary',
            'direct_link' => true,
            'public_submission' => false,
            'force_internal' => 1,
            'legacy' => false,
        ],
        'client_form' => [
            'label' => 'Client Form',
            'description' => 'Sent to an existing client to complete and stored on the client profile for both admin and client.',
            'badge' => 'bg-primary',
            'direct_link' => true,
            'public_submission' => true,
            'force_internal' => 0,
            'legacy' => false,
        ],
        'pet_form' => [
            'label' => 'Pet Form',
            'description' => 'Admin-only notes form intended for pet-specific documentation.',
            'badge' => 'bg-success',
            'direct_link' => true,
            'public_submission' => false,
            'force_internal' => 1,
            'legacy' => false,
        ],
        'survey_form' => [
            'label' => 'Survey Form',
            'description' => 'Client-facing survey that can be shared by link or surfaced in the client portal.',
            'badge' => 'bg-warning text-dark',
            'direct_link' => true,
            'public_submission' => true,
            'force_internal' => 0,
            'legacy' => false,
        ],
        'session_note' => [
            'label' => 'Follow-up Note Form',
            'description' => 'Legacy session note template. Treated as a follow-up note form.',
            'badge' => 'bg-secondary',
            'direct_link' => true,
            'public_submission' => false,
            'force_internal' => 1,
            'legacy' => true,
            'canonical' => 'follow_up_note',
        ],
        'behavior_assessment' => [
            'label' => 'Client Form',
            'description' => 'Legacy behavior assessment template. Treated as a client form.',
            'badge' => 'bg-primary',
            'direct_link' => true,
            'public_submission' => true,
            'force_internal' => 0,
            'legacy' => true,
            'canonical' => 'client_form',
        ],
        'training_plan' => [
            'label' => 'Client Form',
            'description' => 'Legacy training plan template. Treated as a client form.',
            'badge' => 'bg-primary',
            'direct_link' => true,
            'public_submission' => true,
            'force_internal' => 0,
            'legacy' => true,
            'canonical' => 'client_form',
        ],
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function bdta_get_form_type_options(): array
{
    return array_filter(
        bdta_get_form_type_definitions(),
        static fn (array $definition): bool => empty($definition['legacy'])
    );
}

/**
 * @return array<string, mixed>
 */
function bdta_get_form_type_meta(string $form_type): array
{
    $definitions = bdta_get_form_type_definitions();
    if (isset($definitions[$form_type])) {
        return $definitions[$form_type];
    }

    return [
        'label' => ucwords(str_replace('_', ' ', $form_type)),
        'description' => '',
        'badge' => 'bg-secondary',
        'direct_link' => true,
        'public_submission' => true,
        'force_internal' => 0,
        'legacy' => true,
    ];
}

function bdta_normalize_form_type(string $form_type, string $default = 'client_form'): string
{
    $form_type = trim($form_type);
    if ($form_type === '') {
        return $default;
    }

    $meta = bdta_get_form_type_meta($form_type);
    $canonical = isset($meta['canonical']) ? (string) $meta['canonical'] : $form_type;
    $options = bdta_get_form_type_options();

    return isset($options[$canonical]) ? $canonical : $default;
}

function bdta_get_form_type_label(string $form_type): string
{
    return (string) (bdta_get_form_type_meta($form_type)['label'] ?? ucwords(str_replace('_', ' ', $form_type)));
}

function bdta_get_form_type_description(string $form_type): string
{
    return (string) (bdta_get_form_type_meta($form_type)['description'] ?? '');
}

function bdta_get_form_type_badge_class(string $form_type): string
{
    return (string) (bdta_get_form_type_meta($form_type)['badge'] ?? 'bg-secondary');
}

function bdta_form_type_allows_direct_link(string $form_type): bool
{
    return !empty(bdta_get_form_type_meta($form_type)['direct_link']);
}

function bdta_form_type_allows_public_submission(string $form_type): bool
{
    return !empty(bdta_get_form_type_meta($form_type)['public_submission']);
}

function bdta_form_type_forced_internal(string $form_type): int
{
    return !empty(bdta_get_form_type_meta($form_type)['force_internal']) ? 1 : 0;
}
