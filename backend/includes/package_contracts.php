<?php
/**
 * Package contract disclosure helpers.
 */

require_once __DIR__ . '/config.php';

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
    if ($package_id <= 0) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT ct.id AS contract_template_id,
               ct.name AS contract_name,
               ct.template_text,
               ct.renewal_period_months,
               at.name AS appointment_type_name
        FROM package_items pi
        JOIN appointment_types at ON pi.appointment_type_id = at.id
        JOIN contract_templates ct ON at.contract_template_id = ct.id
        WHERE pi.package_id = ?
          AND ct.is_active = 1
        ORDER BY ct.name, at.name
    ");
    $stmt->execute([$package_id]);

    /** @var array<int, array{id: int, name: string, template_text: string, renewal_period_months: int, appointment_types: list<string>}> $grouped */
    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $contract_id = array_int_value($row, 'contract_template_id');
        if ($contract_id <= 0) {
            continue;
        }

        if (!isset($grouped[$contract_id])) {
            $grouped[$contract_id] = [
                'id' => $contract_id,
                'name' => array_string_value($row, 'contract_name'),
                'template_text' => array_string_value($row, 'template_text'),
                'renewal_period_months' => max(1, array_int_value($row, 'renewal_period_months', 12)),
                'appointment_types' => [],
            ];
        }

        $appointment_type_name = array_string_value($row, 'appointment_type_name');
        if ($appointment_type_name !== '' && !in_array($appointment_type_name, $grouped[$contract_id]['appointment_types'], true)) {
            $grouped[$contract_id]['appointment_types'][] = $appointment_type_name;
        }
    }

    return array_values($grouped);
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
