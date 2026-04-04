<?php
/**
 * Backward-compatible booking reminder task handler alias.
 */

require_once __DIR__ . '/booking_reminder.php';

/**
 * Legacy alias for scheduled tasks still using task_type = "reminder".
 * New task registrations should use BookingReminderTask via booking_reminder.php.
 */
class ReminderTask extends BookingReminderTask {
}
