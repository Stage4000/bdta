<?php
/**
 * Brook's Dog Training Academy - Public Services API
 * Returns active single-booking appointment types for the public front-end.
 * No authentication required.
 */

ob_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/bullet_points.php';

/**
 * @return array<string, mixed>
 */
function public_service_row(mixed $row): array {
    return assoc_row($row);
}

$api_result = json_encode(['services' => []]);

register_shutdown_function(function () use (&$api_result) {
    ob_end_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=60');
    }
    echo $api_result;
});

try {
    $db = new Database();
    $conn = $db->getConnection();
    $base_url = getDynamicBaseUrl();

    $stmt = $conn->prepare("
        SELECT id, name, description, bullet_points, default_amount, duration_minutes,
               unique_link, is_field_rental, field_rental_location
        FROM appointment_types
        WHERE is_active = 1
          AND public_available = 1
          AND is_group_class = 0
          AND is_mini_session = 0
        ORDER BY name ASC
    ");
    $stmt->execute();

    $services = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $service) {
        $service_row = public_service_row($service);
        $service_id = array_int_value($service_row, 'id');
        $unique_link = array_string_value($service_row, 'unique_link');
        $booking_url = $unique_link !== ''
            ? $base_url . '/backend/public/book.php?link=' . rawurlencode($unique_link)
            : $base_url . '/backend/public/book.php?type=' . $service_id;

        $services[] = [
            'id'               => $service_id,
            'name'             => array_string_value($service_row, 'name'),
            'description'      => array_string_value($service_row, 'description'),
            'bullet_points'    => bdta_parse_bullet_points(array_string_value($service_row, 'bullet_points')),
            'price'            => safe_float($service_row['default_amount'] ?? 0),
            'duration_minutes' => array_int_value($service_row, 'duration_minutes', 60),
            'location'         => array_string_value($service_row, 'field_rental_location'),
            'type_label'       => array_int_value($service_row, 'is_field_rental') === 1 ? 'Field Rental' : '',
            'booking_url'      => $booking_url,
        ];
    }

    $api_result = json_encode(['services' => $services]);
} catch (Throwable $e) {
    $api_result = json_encode(['services' => []]);
}
