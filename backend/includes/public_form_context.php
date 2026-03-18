<?php
/**
 * Resolve optional existing-client / appointment context for public form links.
 *
 * @return array{client_id:int, booking_id:int, contact_name:string, contact_email:string, contact_phone:string, errors:string[]}
 */
function bdta_resolve_public_form_context(PDO $conn, int $client_id = 0, int $booking_id = 0): array
{
    $context = [
        'client_id' => 0,
        'booking_id' => 0,
        'contact_name' => '',
        'contact_email' => '',
        'contact_phone' => '',
        'errors' => [],
    ];

    $booking_row = null;
    if ($booking_id > 0) {
        $stmt = $conn->prepare("
            SELECT id, client_id, client_name, client_email, client_phone
            FROM bookings
            WHERE id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking_row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($booking_row)) {
            $context['errors'][] = 'The requested appointment could not be found.';
            return $context;
        }

        $booking_client_id = (int) ($booking_row['client_id'] ?? 0);
        if ($client_id > 0 && $booking_client_id > 0 && $booking_client_id !== $client_id) {
            $context['errors'][] = 'The requested client does not match the selected appointment.';
            return $context;
        }

        $context['booking_id'] = (int) ($booking_row['id'] ?? 0);
        if ($client_id === 0) {
            $client_id = $booking_client_id;
        }

        $context['contact_name'] = (string) ($booking_row['client_name'] ?? '');
        $context['contact_email'] = (string) ($booking_row['client_email'] ?? '');
        $context['contact_phone'] = (string) ($booking_row['client_phone'] ?? '');
    }

    if ($client_id > 0) {
        $stmt = $conn->prepare("
            SELECT id, name, email, phone
            FROM clients
            WHERE id = ?
        ");
        $stmt->execute([$client_id]);
        $client_row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($client_row)) {
            $context['errors'][] = 'The requested client could not be found.';
            return $context;
        }

        $context['client_id'] = (int) ($client_row['id'] ?? 0);
        // Prefer the canonical client profile details, but preserve booking values
        // as a fallback when legacy bookings carry contact data that is not yet on
        // the linked client record.
        $context['contact_name'] = (string) ($client_row['name'] ?? $context['contact_name']);
        $context['contact_email'] = (string) ($client_row['email'] ?? $context['contact_email']);
        $context['contact_phone'] = (string) ($client_row['phone'] ?? $context['contact_phone']);
    }

    return $context;
}
