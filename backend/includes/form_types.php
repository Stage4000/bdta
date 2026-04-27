<?php
/**
 * Helpers for classifying form templates by their funnel/category.
 *
 * Canonical form types are the application-defined categories. Legacy values are
 * retained here so older records can still be displayed consistently.
 */
/**
 * @param array<string, mixed> $meta
 */
function bdta_form_type_meta_string(array $meta, string $key, string $default = ''): string
{
    $value = $meta[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

/**
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
 * @return list<string>
 */
function bdta_get_form_type_db_values(string $form_type): array
{
    $normalized = bdta_normalize_form_type($form_type);
    $values = [$normalized];

    foreach (bdta_get_form_type_definitions() as $type_key => $definition) {
        if (empty($definition['legacy'])) {
            continue;
        }

        $canonical = bdta_form_type_meta_string($definition, 'canonical');
        if ($canonical === $normalized) {
            $values[] = $type_key;
        }
    }

    return array_values(array_unique(array_map('scalar_string', $values)));
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
    $options = bdta_get_form_type_options();
    if (!isset($options[$default])) {
        $default = 'client_form';
    }

    if ($form_type === '') {
        return $default;
    }

    $meta = bdta_get_form_type_meta($form_type);
    $canonical = bdta_form_type_meta_string($meta, 'canonical', $form_type);

    return isset($options[$canonical]) ? $canonical : $default;
}

function bdta_get_form_type_label(string $form_type): string
{
    return bdta_form_type_meta_string(
        bdta_get_form_type_meta($form_type),
        'label',
        ucwords(str_replace('_', ' ', $form_type))
    );
}

/**
 * @param array<string, mixed> $field
 */
function bdta_form_field_is_display_only(array $field): bool
{
    return array_string_value($field, 'type', 'text') === 'text_block';
}

/**
 * @param array<string, mixed> $field
 */
function bdta_form_field_text_block_body(array $field): string
{
    $description = trim(array_string_value($field, 'description'));
    if ($description !== '') {
        return $description;
    }

    return '';
}

function bdta_normalize_form_required_frequency(string $frequency): string
{
    $normalized = strtolower(trim($frequency));

    return match ($normalized) {
        'annual' => 'yearly',
        'once',
        'yearly',
        'semi_annual',
        'monthly',
        'per_appointment',
        'per_booking',
        'once_per_pet' => $normalized,
        default => '',
    };
}

function bdta_get_form_required_frequency_label(string $frequency): string
{
    return match (bdta_normalize_form_required_frequency($frequency)) {
        'once' => 'Once (ever)',
        'yearly' => 'Once per year',
        'semi_annual' => 'Twice per year',
        'monthly' => 'Monthly',
        'per_appointment' => 'Per appointment type',
        'per_booking' => 'Per booking',
        'once_per_pet' => 'Once per pet',
        default => 'Optional',
    };
}

function bdta_form_submissions_support_pet_id(PDO $conn): bool
{
    static $cache = [];

    $cache_key = spl_object_id($conn);
    if (array_key_exists($cache_key, $cache)) {
        return $cache[$cache_key];
    }

    try {
        $stmt = $conn->query("SELECT pet_id FROM form_submissions WHERE 1 = 0");
        if ($stmt instanceof PDOStatement) {
            $stmt->closeCursor();
        }
        $cache[$cache_key] = true;
    } catch (PDOException $e) {
        $cache[$cache_key] = false;
    }

    return $cache[$cache_key];
}

function bdta_form_submission_matches_context(
    PDO $conn,
    int $client_id,
    int $template_id,
    int $appointment_type_id = 0,
    int $pet_id = 0,
    ?int $submitted_after = null
): bool {
    $client_id = safe_int($client_id);
    $template_id = safe_int($template_id);
    $appointment_type_id = safe_int($appointment_type_id);
    $pet_id = safe_int($pet_id);

    if ($client_id <= 0 || $template_id <= 0) {
        return false;
    }

    $requires_pet_filter = $pet_id > 0;
    if ($requires_pet_filter && !bdta_form_submissions_support_pet_id($conn)) {
        return false;
    }

    $query = "
        SELECT 1
        FROM form_submissions fs
        LEFT JOIN bookings b ON b.id = fs.booking_id
        LEFT JOIN form_templates ft ON ft.id = fs.template_id
        WHERE fs.client_id = :client_id AND fs.template_id = :template_id AND fs.status = 'submitted'
    ";
    if ($appointment_type_id > 0) {
        $query .= "
            AND (
                b.appointment_type_id = :appointment_type_id
                OR (fs.booking_id IS NULL AND COALESCE(ft.appointment_type_id, 0) = :template_appointment_type_id)
            )
        ";
    }

    if ($requires_pet_filter) {
        $query .= " AND COALESCE(fs.pet_id, 0) = :pet_id ";
    }

    if ($submitted_after !== null) {
        $query .= " AND fs.submitted_at IS NOT NULL AND fs.submitted_at >= :submitted_after ";
    }

    $query .= " LIMIT 1";

    $stmt = $conn->prepare($query);
    $stmt->bindValue(':client_id', $client_id, PDO::PARAM_INT);
    $stmt->bindValue(':template_id', $template_id, PDO::PARAM_INT);
    if ($appointment_type_id > 0) {
        $stmt->bindValue(':appointment_type_id', $appointment_type_id, PDO::PARAM_INT);
        $stmt->bindValue(':template_appointment_type_id', $appointment_type_id, PDO::PARAM_INT);
    }
    if ($requires_pet_filter) {
        $stmt->bindValue(':pet_id', $pet_id, PDO::PARAM_INT);
    }
    if ($submitted_after !== null) {
        $stmt->bindValue(':submitted_after', date('Y-m-d H:i:s', $submitted_after), PDO::PARAM_STR);
    }
    $stmt->execute();

    return $stmt->fetchColumn() !== false;
}

/**
 * @return list<int>
 */
function bdta_get_form_template_completed_pet_ids(PDO $conn, int $client_id, int $template_id, int $appointment_type_id = 0): array
{
    $client_id = safe_int($client_id);
    $template_id = safe_int($template_id);
    $appointment_type_id = safe_int($appointment_type_id);

    if ($client_id <= 0 || $template_id <= 0) {
        return [];
    }

    if (!bdta_form_submissions_support_pet_id($conn)) {
        return [];
    }

    $query = "
        SELECT DISTINCT fs.pet_id
        FROM form_submissions fs
        LEFT JOIN bookings b ON b.id = fs.booking_id
        LEFT JOIN form_templates ft ON ft.id = fs.template_id
        WHERE fs.client_id = :client_id AND fs.template_id = :template_id AND fs.status = 'submitted' AND fs.pet_id IS NOT NULL
    ";
    if ($appointment_type_id > 0) {
        $query .= "
            AND (
                b.appointment_type_id = :appointment_type_id
                OR (fs.booking_id IS NULL AND COALESCE(ft.appointment_type_id, 0) = :template_appointment_type_id)
            )
        ";
    }

    $stmt = $conn->prepare($query);
    $stmt->bindValue(':client_id', $client_id, PDO::PARAM_INT);
    $stmt->bindValue(':template_id', $template_id, PDO::PARAM_INT);
    if ($appointment_type_id > 0) {
        $stmt->bindValue(':appointment_type_id', $appointment_type_id, PDO::PARAM_INT);
        $stmt->bindValue(':template_appointment_type_id', $appointment_type_id, PDO::PARAM_INT);
    }
    $stmt->execute();

    return array_values(array_unique(array_map('safe_int', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

/**
 * @param array<string, mixed> $template
 * @param list<int|string> $pet_ids
 */
function bdta_form_template_needs_completion(
    PDO $conn,
    array $template,
    int $client_id,
    int $appointment_type_id = 0,
    array $pet_ids = []
): bool {
    $template_id = array_int_value($template, 'id');
    $frequency = bdta_normalize_form_required_frequency(array_string_value($template, 'required_frequency'));

    if ($template_id <= 0) {
        return true;
    }

    if ($frequency === '' || $frequency === 'per_booking') {
        return true;
    }

    if ($frequency === 'per_appointment') {
        return !bdta_form_submission_matches_context($conn, $client_id, $template_id, $appointment_type_id);
    }

    if ($frequency === 'once_per_pet') {
        $normalized_pet_ids = array_values(array_filter(array_map('safe_int', $pet_ids), static fn (int $pet_id): bool => $pet_id > 0));
        if ($normalized_pet_ids === []) {
            return true;
        }

        foreach ($normalized_pet_ids as $pet_id) {
            if (!bdta_form_submission_matches_context($conn, $client_id, $template_id, $appointment_type_id, $pet_id)) {
                return true;
            }
        }

        return false;
    }

    $submitted_after = match ($frequency) {
        'yearly' => strtotime('-1 year'),
        'semi_annual' => strtotime('-6 months'),
        'monthly' => strtotime('-1 month'),
        default => null,
    };

    return !bdta_form_submission_matches_context($conn, $client_id, $template_id, 0, 0, $submitted_after);
}

/**
 * @param list<int|string> $pet_ids
 * @return list<int|null>
 */
function bdta_get_form_submission_pet_ids(string $frequency, array $pet_ids = []): array
{
    if (bdta_normalize_form_required_frequency($frequency) !== 'once_per_pet') {
        return [null];
    }

    $normalized_pet_ids = array_values(array_filter(array_map('safe_int', $pet_ids), static fn (int $pet_id): bool => $pet_id > 0));

    return $normalized_pet_ids !== [] ? $normalized_pet_ids : [null];
}

function bdta_get_form_type_description(string $form_type): string
{
    return bdta_form_type_meta_string(bdta_get_form_type_meta($form_type), 'description');
}

function bdta_get_form_type_badge_class(string $form_type): string
{
    return bdta_form_type_meta_string(bdta_get_form_type_meta($form_type), 'badge', 'bg-secondary');
}

function bdta_get_form_access_label(string $form_type): string
{
    return bdta_form_type_forced_internal($form_type) === 1 ? 'Admin only' : 'Client facing';
}

function bdta_get_form_access_help(string $form_type): string
{
    return bdta_form_type_forced_internal($form_type) === 1
        ? 'This form type is completed by admin/staff users only.'
        : 'This form type is completed by clients, either during booking or via a shared link.';
}

/**
 * @return array{
 *   forced_internal: bool,
 *   requested_internal: bool,
 *   effective_internal: bool,
 *   can_toggle_internal: bool,
 *   label: string,
 *   help: string,
 *   toggle_help: string
 * }
 */
function bdta_get_form_template_access_state(string $form_type, int $is_internal = 0): array
{
    $forced_internal = bdta_form_type_forced_internal($form_type) === 1;
    $requested_internal = $is_internal !== 0;
    $effective_internal = $forced_internal || $requested_internal;

    return [
        'forced_internal' => $forced_internal,
        'requested_internal' => $requested_internal,
        'effective_internal' => $effective_internal,
        'can_toggle_internal' => !$forced_internal,
        'label' => $effective_internal ? 'Admin only' : 'Client facing',
        'help' => $effective_internal
            ? 'This template currently requires an admin/staff login to complete.'
            : 'This template can be completed by clients, either during booking or via a shared link.',
        'toggle_help' => $forced_internal
            ? 'This form type is always internal and cannot be shared with clients.'
            : (
                $requested_internal
                    ? 'This template will only be available to admin/staff users.'
                    : 'Leave this off to allow clients to complete the form.'
            ),
    ];
}

function bdta_form_template_defaults_to_client_portal_visible(string $form_type, int $is_internal = 0): bool
{
    $normalized_form_type = bdta_normalize_form_type($form_type);
    if ($normalized_form_type === 'follow_up_note') {
        return true;
    }

    return $is_internal === 0 && bdta_form_type_forced_internal($normalized_form_type) === 0;
}

/**
 * @param array<string, mixed> $template
 *
 * Portal visibility precedence:
 * 1. An explicit show_in_client_portal/template_show_in_client_portal flag wins.
 * 2. Follow-up note templates default to visible when no explicit flag is stored.
 * 3. Other templates fall back to their internal/template_is_internal flags.
 * 4. If no relevant fields are present, fail closed.
 */
function bdta_form_template_is_client_portal_visible(array $template): bool
{
    foreach (['show_in_client_portal', 'template_show_in_client_portal'] as $visibility_key) {
        if (array_key_exists($visibility_key, $template) && $template[$visibility_key] !== null && $template[$visibility_key] !== '') {
            return array_int_value($template, $visibility_key) !== 0;
        }
    }

    $form_type = array_string_value($template, 'form_type');
    $normalized_form_type = bdta_normalize_form_type($form_type);
    if ($normalized_form_type === 'follow_up_note') {
        return true;
    }

    foreach (['is_internal', 'template_is_internal'] as $internal_key) {
        if (array_key_exists($internal_key, $template)) {
            return bdta_form_template_defaults_to_client_portal_visible(
                $form_type,
                array_int_value($template, $internal_key)
            );
        }
    }

    return false;
}

/**
 * @return array{
 *   requested_visible: bool|null,
 *   effective_visible: bool,
 *   default_visible: bool,
 *   label: string,
 *   help: string,
 *   toggle_help: string
 * }
 */
function bdta_get_form_template_client_portal_state(string $form_type, int $is_internal = 0, ?int $show_in_client_portal = null): array
{
    $default_visible = bdta_form_template_defaults_to_client_portal_visible($form_type, $is_internal);
    $requested_visible = $show_in_client_portal === null ? null : $show_in_client_portal !== 0;
    $effective_visible = $requested_visible ?? $default_visible;

    return [
        'requested_visible' => $requested_visible,
        'effective_visible' => $effective_visible,
        'default_visible' => $default_visible,
        'label' => $effective_visible ? 'Shown in client portal' : 'Hidden from client portal',
        'help' => $effective_visible
            ? 'Submitted responses for this template will be visible to clients in the client portal.'
            : 'Submitted responses for this template will stay hidden from clients in the client portal.',
        'toggle_help' => $effective_visible
            ? 'Turn this off to keep submissions out of the client portal.'
            : 'Turn this on to let clients review submitted responses in the client portal.',
    ];
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
