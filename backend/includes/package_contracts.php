<?php
/**
 * Package contract disclosure helpers.
 */

/**
 * @param array<string|int, mixed> $row
 */
function bdta_package_contracts_array_string_value(array $row, string|int $key, string $default = ''): string {
    $value = $row[$key] ?? null;
    if (is_string($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value) || is_bool($value)) {
        return (string)$value;
    }

    return $default;
}

/**
 * @param array<string|int, mixed> $row
 */
function bdta_package_contracts_array_int_value(array $row, string|int $key, int $default = 0): int {
    $value = $row[$key] ?? null;
    if (is_int($value)) {
        return $value;
    }
    if (is_string($value)) {
        if ($value === '') {
            return $default;
        }

        return (int)$value;
    }
    if (is_float($value) || is_bool($value)) {
        return (int)$value;
    }

    return $default;
}

/**
 * @param list<int|string> $package_ids
 * @return array<int, list<array{
 *     id: int,
 *     name: string,
 *     template_text: string,
 *     renewal_period_months: int,
 *     appointment_types: list<string>
 * }>>
 */
function bdta_get_package_contract_summaries(PDO $conn, array $package_ids): array {
    $normalized_package_ids = [];
    foreach ($package_ids as $package_id) {
        $package_id = (int)$package_id;
        if ($package_id > 0) {
            $normalized_package_ids[$package_id] = $package_id;
        }
    }
    $normalized_package_ids = array_values($normalized_package_ids);

    if (empty($normalized_package_ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($normalized_package_ids), '?'));
    // Placeholder count is generated from normalized package IDs and the values remain parameterized.
    // nosemgrep: php.lang.security.sql-injection -- dynamic IN-clause placeholder list is constructed from normalized integers and all values remain parameterized.
    $stmt = $conn->prepare("
        SELECT pi.package_id,
               ct.id AS contract_template_id,
               ct.name AS contract_name,
               ct.template_text,
               ct.renewal_period_months,
               at.name AS appointment_type_name
        FROM package_items pi
        JOIN appointment_types at ON pi.appointment_type_id = at.id
        JOIN contract_templates ct ON at.contract_template_id = ct.id
        WHERE pi.package_id IN ($placeholders)
          AND ct.is_active = 1
        ORDER BY pi.package_id, ct.name, at.name
    ");
    $stmt->execute($normalized_package_ids);

    /** @var array<int, array<int, array{id: int, name: string, template_text: string, renewal_period_months: int, appointment_types: list<string>}>> $grouped */
    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $package_id = bdta_package_contracts_array_int_value($row, 'package_id');
        $contract_id = bdta_package_contracts_array_int_value($row, 'contract_template_id');
        if ($package_id <= 0 || $contract_id <= 0) {
            continue;
        }

        if (!isset($grouped[$package_id][$contract_id])) {
            $grouped[$package_id][$contract_id] = [
                'id' => $contract_id,
                'name' => bdta_package_contracts_array_string_value($row, 'contract_name'),
                'template_text' => bdta_package_contracts_array_string_value($row, 'template_text'),
                'renewal_period_months' => max(1, bdta_package_contracts_array_int_value($row, 'renewal_period_months', 12)),
                'appointment_types' => [],
            ];
        }

        $appointment_type_name = bdta_package_contracts_array_string_value($row, 'appointment_type_name');
        if ($appointment_type_name !== '' && !in_array($appointment_type_name, $grouped[$package_id][$contract_id]['appointment_types'], true)) {
            $grouped[$package_id][$contract_id]['appointment_types'][] = $appointment_type_name;
        }
    }

    $summaries = [];
    foreach ($normalized_package_ids as $package_id) {
        $summaries[$package_id] = array_values($grouped[$package_id] ?? []);
    }

    return $summaries;
}

/**
 * Build a grouped list of required contracts for a package.
 *
 * @return list<array{
 *     id: int,
 *     name: string,
 *     template_text: string,
 *     renewal_period_months: int,
 *     appointment_types: list<string>
 * }>
 */
function bdta_get_package_contract_summary(PDO $conn, int $package_id): array {
    $package_id = (int)$package_id;
    if ($package_id <= 0) {
        return [];
    }

    $summaries = bdta_get_package_contract_summaries($conn, [$package_id]);
    return $summaries[$package_id] ?? [];
}

/**
 * @param array<string, mixed> $post_data
 * @param list<array{id: int, name: string, template_text: string, renewal_period_months: int, appointment_types: list<string>}> $package_contracts
 */
function bdta_package_purchase_acknowledged(array $post_data, array $package_contracts): bool {
    if (empty($package_contracts)) {
        return true;
    }

    return !empty($post_data['contract_disclosure_acknowledged']);
}
