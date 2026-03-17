<?php

/**
 * @param mixed $raw_ids
 * @return array<int>
 */
function bdta_parse_time_entry_ids(mixed $raw_ids): array
{
    if (is_string($raw_ids)) {
        $raw_ids = $raw_ids === '' ? [] : explode(',', $raw_ids);
    }

    if (!is_array($raw_ids)) {
        return [];
    }

    $parsed_ids = [];
    foreach ($raw_ids as $raw_id) {
        $time_entry_id = safe_int($raw_id);
        if ($time_entry_id > 0) {
            $parsed_ids[$time_entry_id] = $time_entry_id;
        }
    }

    return array_values($parsed_ids);
}

/**
 * @param array<int> $time_entry_ids
 * @return array<int, array<string, mixed>>
 */
function bdta_get_invoiceable_time_entries(PDO $conn, array $time_entry_ids, int $client_id = 0): array
{
    if ($time_entry_ids === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($time_entry_ids), '?'));
    $params = $time_entry_ids;
    $sql = "
        SELECT te.*, c.name AS client_name
        FROM time_entries te
        INNER JOIN clients c ON c.id = te.client_id
        WHERE te.id IN ($placeholders)
          AND te.billable = 1
          AND te.invoiced = 0
    ";

    if ($client_id > 0) {
        $sql .= " AND te.client_id = ?";
        $params[] = $client_id;
    }

    $sql .= " ORDER BY te.date DESC, te.start_time DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @param array<string, mixed> $time_entry
 */
function bdta_build_time_entry_invoice_description(array $time_entry): string
{
    $description = trim(scalar_string($time_entry['description'] ?? ''));
    $summary = trim(scalar_string($time_entry['service_type'] ?? 'Time Entry'));
    $date = trim(scalar_string($time_entry['date'] ?? ''));

    if ($date !== '') {
        $summary .= ' (' . $date . ')';
    }

    if ($description !== '') {
        $summary .= ' - ' . $description;
    }

    return $summary;
}

/**
 * @param array<int> $time_entry_ids
 */
function bdta_mark_time_entries_invoiced(PDO $conn, array $time_entry_ids, int $client_id): void
{
    if ($time_entry_ids === [] || $client_id <= 0) {
        return;
    }

    $placeholders = implode(', ', array_fill(0, count($time_entry_ids), '?'));
    $params = array_merge([$client_id], $time_entry_ids);
    $stmt = $conn->prepare("
        UPDATE time_entries
        SET invoiced = 1
        WHERE client_id = ?
          AND billable = 1
          AND invoiced = 0
          AND id IN ($placeholders)
    ");
    $stmt->execute($params);
}
