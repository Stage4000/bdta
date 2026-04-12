<?php

function bdta_build_portal_booking_link(string $base_url, int $booking_id = 0, string $action = ''): string
{
    $normalized_base = rtrim(trim($base_url), '/');
    $url = $normalized_base . '/portal/appointments.php';

    $query = [];
    if ($booking_id > 0) {
        $query['booking_id'] = $booking_id;
    }

    if (in_array($action, ['cancel', 'reschedule'], true)) {
        $query['action'] = $action;
    }

    if ($query === []) {
        return $url;
    }

    return $url . '?' . http_build_query($query);
}
