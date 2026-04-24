<?php
/**
 * Google Calendar Integration
 *
 * Supports two modes:
 *  1. Service Account  – pre-configured JSON credentials (legacy/shared calendar)
 *  2. OAuth 2.0        – per-user authorisation via "Connect Google Calendar" flow
 *
 * Configuration is managed through Admin Panel > Settings > Calendar.
 */
// Depends on config.php for settings/database bootstrap and the shared getSystemTimezone() helper
require_once __DIR__ . '/config.php';

/**
 * @phpstan-type BookingRow array<string, mixed>
 * @phpstan-type CalendarResult array<string, mixed>
 */
class GoogleCalendarIntegration {
    private const HTTP_ERROR_CODE_CURL = 'curl';
    private const OAUTH_NOTIFICATION_ENTITY_TYPE = 'google_calendar_oauth';
    private const OAUTH_NOTIFICATION_TITLE = 'Google Calendar connection needs attention';
    private static string $last_http_error_message = '';
    private string $credentials_file;
    private string $calendar_id;

    public function __construct() {
        $this->calendar_id       = scalar_string(Settings::get('google_calendar_id', 'primary'));
        $credentials_path        = scalar_string(Settings::get('google_calendar_credentials_file', __DIR__ . '/google-calendar-credentials.json'));
        $this->credentials_file  = $credentials_path;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rowString(array $row, string $key, string $default = ''): string {
        return scalar_string($row[$key] ?? $default);
    }

    private static function getOAuthNotificationUrl(): string {
        return '/client/settings.php?category=calendar';
    }

    /**
     * @return array{error: array{message: string, code: string}}
     */
    private static function curlErrorResponse(string $message): array {
        return ['error' => ['message' => $message, 'code' => self::HTTP_ERROR_CODE_CURL]];
    }

    /**
     * @return array{error?: array{message?: string, code?: string}}
     */
    private static function consumeLastHttpErrorResponse(): array {
        if (self::$last_http_error_message === '') {
            return [];
        }

        $response = self::curlErrorResponse(self::$last_http_error_message);
        self::$last_http_error_message = '';

        return $response;
    }

    /**
     * @param array<string, mixed>|null $token
     */
    private static function createOAuthFailureNotification(int $admin_user_id, ?array $token = null): void {
        if ($admin_user_id <= 0) {
            return;
        }
        // google_calendar.php boots through config.php, which loads notifications.php.
        if (!function_exists('bdta_create_admin_notifications')) {
            return;
        }

        $db = new Database();
        $conn = $db->getConnection();
        if ($token === null) {
            $token = self::getOAuthToken($admin_user_id);
        }

        $connected_account = is_array($token) ? trim(self::rowString($token, 'google_email')) : '';
        $connected_account = filter_var($connected_account, FILTER_VALIDATE_EMAIL) ? $connected_account : '';
        $message = sprintf(
            'Google Calendar OAuth%s needs attention. Booking availability and sync may be inaccurate until it is reconnected.',
            $connected_account !== '' ? ' for ' . $connected_account : ''
        );

        bdta_create_admin_notifications(
            $conn,
            self::OAUTH_NOTIFICATION_ENTITY_TYPE,
            $admin_user_id,
            self::OAUTH_NOTIFICATION_TITLE,
            $message,
            self::getOAuthNotificationUrl()
        );
    }

    private static function clearOAuthFailureNotifications(int $admin_user_id): void {
        if ($admin_user_id <= 0) {
            return;
        }

        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("
            UPDATE notifications
            SET deleted_at = CURRENT_TIMESTAMP
            WHERE audience = 'admin'
              AND entity_type = ?
              AND entity_id = ?
              AND deleted_at IS NULL
        ");
        $stmt->execute([self::OAUTH_NOTIFICATION_ENTITY_TYPE, $admin_user_id]);
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /**
     * Build the Google Calendar event body array from a booking row.
     *
     * @param BookingRow $booking
     * @return array<string, mixed>
     */
    private function buildEventBody(array $booking): array {
        $timezone = getSystemTimezone();
        // Normalise to HH:MM – MySQL TIME columns return HH:MM:SS which would
        // produce an invalid RFC3339 string like "2026-03-02T14:30:00:00".
        $appointment_time = self::rowString($booking, 'appointment_time');
        $appointment_date = self::rowString($booking, 'appointment_date');
        $time_hhmm = substr($appointment_time, 0, 5);
        $start_dt  = $appointment_date . 'T' . $time_hhmm . ':00';
        $duration  = safe_int($booking['duration_minutes'] ?? 60);
        // Use the full date+time so end-time is correct even when crossing midnight
        $start_ts  = strtotime($appointment_date . ' ' . $time_hhmm);
        if ($start_ts === false || $start_ts === 0) {
            // Fallback: parse time only (today's date) – end date will match start date
            $start_ts = strtotime($time_hhmm) ?: time();
        }
        $end_ts = $start_ts + $duration * 60;
        $end_dt = date('Y-m-d', $end_ts) . 'T' . date('H:i:s', $end_ts);

        $location      = trim(self::rowString($booking, 'location'));
        $location_type = trim(self::rowString($booking, 'location_type'));

        // Physical address types get added to the event's Location field so
        // Google Calendar can display them on a map.  Phone and webcall entries
        // are only surfaced in the description to avoid spurious geocode lookups.
        // If location_type is unset (legacy booking), fall back to showing the
        // location value in the description only, to avoid misclassifying a
        // non-physical value as a mappable address.
        $physical_types   = ['client_address', 'custom_address', 'fixed'];
        $is_physical_addr = !empty($location_type) && in_array($location_type, $physical_types, true);
        $event_location   = ($is_physical_addr && $location !== '') ? $location : '';

        $description_lines = array_filter([
            'Client: '   . self::rowString($booking, 'client_name'),
            'Email: '    . self::rowString($booking, 'client_email'),
            'Phone: '    . self::rowString($booking, 'client_phone'),
            $location !== '' ? 'Address: ' . $location : '',
            'Notes: '    . self::rowString($booking, 'notes'),
        ]);

        $event_body = [
            'summary'     => self::rowString($booking, 'service_type', 'Appointment') . ' - ' . self::rowString($booking, 'client_name'),
            'description' => implode("\n", $description_lines),
            'start'       => ['dateTime' => $start_dt, 'timeZone' => $timezone],
            'end'         => ['dateTime' => $end_dt,   'timeZone' => $timezone],
            'reminders'   => [
                'useDefault' => false,
                // Only 'popup' is universally supported; 'email' overrides are not
                // available on personal Gmail accounts and return HTTP 400.
                'overrides'  => [
                    ['method' => 'popup', 'minutes' => 60],
                ],
            ],
        ];

        if ($event_location !== '') {
            $event_body['location'] = $event_location;
        }

        return $event_body;
    }

    // -------------------------------------------------------------------------
    // Service-account method (legacy)
    // -------------------------------------------------------------------------

    /**
     * Check if the legacy service-account integration is configured.
     */
    public function isConfigured(): bool {
        return (bool)Settings::get('google_calendar_enabled', false) && file_exists($this->credentials_file);
    }

    /**
     * Add an event using a Google service account.
     * Requires google/apiclient: composer require google/apiclient
     *
     * @param BookingRow $booking
     * @return CalendarResult
     */
    public function addEvent(array $booking): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Google Calendar not configured'];
        }

        try {
            if (!class_exists('Google_Client')
                || !class_exists('Google_Service_Calendar')
                || !class_exists('Google_Service_Calendar_Event')) {
                return ['success' => false, 'message' => 'Google API client library not installed. Run: composer require google/apiclient'];
            }

            $client = new Google_Client();
            $client->setAuthConfig($this->credentials_file);
            $client->addScope(Google_Service_Calendar::CALENDAR);

            $service = new Google_Service_Calendar($client);
            $event   = new Google_Service_Calendar_Event($this->buildEventBody($booking));
            $events_resource = $service->events ?? null;
            if (!is_object($events_resource) || !is_callable([$events_resource, 'insert'])) {
                return ['success' => false, 'message' => 'Google Calendar events resource unavailable'];
            }
            $created = call_user_func([$events_resource, 'insert'], $this->calendar_id, $event);
            $event_id = is_object($created) && is_callable([$created, 'getId'])
                ? scalar_string(call_user_func([$created, 'getId']))
                : '';
            $link = is_object($created) && is_callable([$created, 'getHtmlLink'])
                ? scalar_string(call_user_func([$created, 'getHtmlLink']))
                : '';

            return ['success' => true, 'event_id' => $event_id, 'link' => $link];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Update an existing event using a Google service account.
     * $event_id is the Google event ID stored in bookings.google_event_id.
     *
     * @param BookingRow $booking
     * @return CalendarResult
     */
    public function updateEvent(array $booking, string $event_id): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Google Calendar not configured'];
        }

        try {
            if (!class_exists('Google_Client')
                || !class_exists('Google_Service_Calendar')
                || !class_exists('Google_Service_Calendar_Event')) {
                return ['success' => false, 'message' => 'Google API client library not installed. Run: composer require google/apiclient'];
            }

            $client = new Google_Client();
            $client->setAuthConfig($this->credentials_file);
            $client->addScope(Google_Service_Calendar::CALENDAR);

            $service = new Google_Service_Calendar($client);
            $event   = new Google_Service_Calendar_Event($this->buildEventBody($booking));
            $events_resource = $service->events ?? null;
            if (!is_object($events_resource) || !is_callable([$events_resource, 'update'])) {
                return ['success' => false, 'message' => 'Google Calendar events resource unavailable'];
            }
            $updated = call_user_func([$events_resource, 'update'], $this->calendar_id, $event_id, $event);
            $updated_id = is_object($updated) && is_callable([$updated, 'getId'])
                ? scalar_string(call_user_func([$updated, 'getId']))
                : '';
            $link = is_object($updated) && is_callable([$updated, 'getHtmlLink'])
                ? scalar_string(call_user_func([$updated, 'getHtmlLink']))
                : '';

            return ['success' => true, 'event_id' => $updated_id, 'link' => $link];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Delete an existing event using a Google service account.
     * Returns true when the event is removed successfully or when Google reports
     * that it has already been deleted (HTTP 404/410).
     *
     * @param string $event_id The Google event ID stored in bookings.google_event_id.
     * @return bool True on successful deletion or when the event no longer exists remotely.
     */
    private function deleteEvent(string $event_id): bool {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            if (!class_exists('Google_Client')
                || !class_exists('Google_Service_Calendar')) {
                return false;
            }

            $client = new Google_Client();
            $client->setAuthConfig($this->credentials_file);
            $client->addScope(Google_Service_Calendar::CALENDAR);

            $service = new Google_Service_Calendar($client);
            $events_resource = $service->events ?? null;
            if (!is_object($events_resource)) {
                return false;
            }

            try {
                $events_resource->delete($this->calendar_id, $event_id);
            } catch (Error $e) {
                return false;
            }
            return true;
        } catch (Exception $e) {
            $error_code = safe_int($e->getCode());
            return $error_code === 404 || $error_code === 410;
        }
    }

    // -------------------------------------------------------------------------
    // OAuth 2.0 method (per-user)
    // -------------------------------------------------------------------------

    /**
     * Check whether an admin user has a connected OAuth token.
     */
    public static function isOAuthConfigured(): bool {
        $client_id     = Settings::get('google_oauth_client_id', '');
        $client_secret = Settings::get('google_oauth_client_secret', '');
        return !empty($client_id) && !empty($client_secret);
    }

    /**
     * Retrieve the stored OAuth token for a given admin user.
     * Returns the token row or null.
     *
     * @return array<string, mixed>|null
     */
    public static function getOAuthToken(int $admin_user_id): ?array {
        $db   = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("SELECT * FROM google_oauth_tokens WHERE admin_user_id = ? LIMIT 1");
        $stmt->execute([$admin_user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public static function getAnyConnectedOAuthAdminUserId(): int {
        $db   = new Database();
        $conn = $db->getConnection();
        // Use a prepared statement here to keep read paths consistent with the rest
        // of the OAuth token helpers even though this query currently has no inputs.
        $stmt = $conn->prepare("SELECT admin_user_id FROM google_oauth_tokens ORDER BY admin_user_id LIMIT 1");
        $stmt->execute();
        return safe_int($stmt->fetchColumn());
    }

    /**
     * @return list<int>
     */
    private static function getConnectedOAuthAdminUserIds(?int $preferred_admin_user_id = null): array {
        $db   = new Database();
        $conn = $db->getConnection();
        // Use a prepared statement here to keep read paths consistent with the rest
        // of the OAuth token helpers even though this query currently has no inputs.
        $stmt = $conn->prepare("SELECT admin_user_id FROM google_oauth_tokens ORDER BY admin_user_id");
        $stmt->execute();

        $admin_user_ids = [];
        $preferred_present = false;
        while (($admin_row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $admin_user_id = safe_int($admin_row['admin_user_id'] ?? 0);
            if ($admin_user_id <= 0) {
                continue;
            }
            if ($preferred_admin_user_id !== null && $admin_user_id === $preferred_admin_user_id) {
                $preferred_present = true;
                continue;
            }
            $admin_user_ids[] = $admin_user_id;
        }

        $admin_user_ids = array_values(array_unique($admin_user_ids));
        if ($preferred_admin_user_id !== null && $preferred_admin_user_id > 0 && $preferred_present) {
            array_unshift($admin_user_ids, $preferred_admin_user_id);
        }

        return $admin_user_ids;
    }

    public static function getAppointmentTypeAdminUserId(int $appointment_type_id): int {
        if ($appointment_type_id <= 0) {
            return 0;
        }

        $db   = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("SELECT admin_user_id FROM appointment_types WHERE id = ? LIMIT 1");
        $stmt->execute([$appointment_type_id]);
        return safe_int($stmt->fetchColumn());
    }

    /**
     * @param BookingRow $booking
     */
    public static function getBookingAdminUserId(array $booking): int {
        $booking_admin_user_id = safe_int($booking['admin_user_id'] ?? 0);
        if ($booking_admin_user_id > 0) {
            return $booking_admin_user_id;
        }

        return self::getAppointmentTypeAdminUserId(safe_int($booking['appointment_type_id'] ?? 0));
    }

    /**
     * @param BookingRow $booking
     * @return CalendarResult
     */
    public static function addEventForBooking(array $booking): array {
        $google_result = ['success' => false, 'message' => 'Google Calendar integration not configured'];

        if (self::isOAuthConfigured()) {
            $target_admin_user_id = self::getBookingAdminUserId($booking);
            foreach (self::getConnectedOAuthAdminUserIds($target_admin_user_id > 0 ? $target_admin_user_id : null) as $admin_user_id) {
                $google_result = self::addEventOAuth($booking, $admin_user_id);
                if (!empty($google_result['success'])) {
                    break;
                }
            }
        }

        if (empty($google_result['success'])) {
            $google_calendar = new self();
            if ($google_calendar->isConfigured()) {
                $google_result = $google_calendar->addEvent($booking);
            }
        }

        return $google_result;
    }

    /**
     * @param BookingRow $booking
     * @return CalendarResult
     */
    public static function updateEventForBooking(array $booking, string $event_id): array {
        $google_result = ['success' => false, 'message' => 'Google Calendar integration not configured'];

        if (self::isOAuthConfigured()) {
            $target_admin_user_id = self::getBookingAdminUserId($booking);
            foreach (self::getConnectedOAuthAdminUserIds($target_admin_user_id > 0 ? $target_admin_user_id : null) as $admin_user_id) {
                $google_result = self::updateEventOAuth($booking, $event_id, $admin_user_id);
                if (!empty($google_result['success'])) {
                    break;
                }
            }
        }

        if (empty($google_result['success'])) {
            $google_calendar = new self();
            if ($google_calendar->isConfigured()) {
                $google_result = $google_calendar->updateEvent($booking, $event_id);
            }
        }

        return $google_result;
    }

    /**
     * @param BookingRow $booking
     */
    public static function deleteEventForBooking(string $event_id, array $booking): bool {
        if (self::isOAuthConfigured()) {
            $target_admin_user_id = self::getBookingAdminUserId($booking);
            foreach (self::getConnectedOAuthAdminUserIds($target_admin_user_id > 0 ? $target_admin_user_id : null) as $admin_user_id) {
                if (self::deleteEventOAuth($event_id, $admin_user_id)) {
                    return true;
                }
            }
        }

        $google_calendar = new self();
        if ($google_calendar->isConfigured()) {
            return $google_calendar->deleteEvent($event_id);
        }

        return false;
    }

    /**
     * Get a valid access token for the given admin user, refreshing if necessary.
     * Returns the access token string or null on failure.
     */
    public static function getValidAccessToken(int $admin_user_id): ?string {
        $token = self::getOAuthToken($admin_user_id);
        if (!$token) {
            return null;
        }

        // Check if expired (with 60-second buffer)
        $expires_at = self::rowString($token, 'expires_at');
        if ($expires_at !== '' && safe_timestamp(strtotime($expires_at)) < (time() + 60)) {
            $refresh_token = self::rowString($token, 'refresh_token');
            if ($refresh_token === '') {
                error_log('GoogleCalendarIntegration: token for admin_user_id=' . $admin_user_id . ' is expired and no refresh_token stored');
                self::createOAuthFailureNotification($admin_user_id, $token);
                return null;
            }
            $refreshed = self::refreshAccessToken($refresh_token, $admin_user_id);
            if (!$refreshed) {
                error_log('GoogleCalendarIntegration: failed to refresh token for admin_user_id=' . $admin_user_id);
            }
            return $refreshed;
        }

        return self::rowString($token, 'access_token');
    }

    /**
     * Refresh the access token using the stored refresh token.
     * Saves the new token to the database and returns the new access token, or null on failure.
     */
    private static function refreshAccessToken(string $refresh_token, int $admin_user_id): ?string {
        $client_id     = scalar_string(Settings::get('google_oauth_client_id', ''));
        $client_secret = scalar_string(Settings::get('google_oauth_client_secret', ''));

        $response = self::httpPost('https://oauth2.googleapis.com/token', [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
        ]);
        $http_error_response = self::consumeLastHttpErrorResponse();
        if ($http_error_response !== []) {
            self::createOAuthFailureNotification($admin_user_id);
            return null;
        }

        if (empty($response['access_token'])) {
            self::createOAuthFailureNotification($admin_user_id);
            return null;
        }

        self::persistRefreshedOAuthToken(
            $admin_user_id,
            self::rowString($response, 'access_token'),
            safe_int($response['expires_in'] ?? 3600),
            self::rowString($response, 'refresh_token', $refresh_token)
        );

        self::clearOAuthFailureNotifications($admin_user_id);

        return self::rowString($response, 'access_token');
    }

    /**
     * Persist a refreshed OAuth token, keeping the existing refresh token when Google omits it
     * and replacing it when Google rotates it.
     */
    private static function persistRefreshedOAuthToken(
        int $admin_user_id,
        string $access_token,
        int $expires_in,
        string $refresh_token
    ): void {
        $expires_at = date('Y-m-d H:i:s', time() + $expires_in);
        $db   = new Database();
        $conn = $db->getConnection();
        $conn->prepare("
            UPDATE google_oauth_tokens
            SET access_token = ?, refresh_token = ?, expires_at = ?, updated_at = CURRENT_TIMESTAMP
            WHERE admin_user_id = ?
        ")->execute([$access_token, $refresh_token, $expires_at, $admin_user_id]);
    }

    /**
     * Save (insert or update) an OAuth token for an admin user.
     */
    public static function saveOAuthToken(
        int    $admin_user_id,
        string $access_token,
        string $refresh_token,
        int    $expires_in,
        string $google_email = '',
        string $calendar_id  = 'primary'
    ): void {
        $expires_at = date('Y-m-d H:i:s', time() + $expires_in);
        $db         = new Database();
        $conn       = $db->getConnection();

        // Upsert – replace any existing row for this user
        $existing = $conn->prepare("SELECT id, refresh_token FROM google_oauth_tokens WHERE admin_user_id = ?");
        $existing->execute([$admin_user_id]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $stored_refresh_token = self::rowString($row, 'refresh_token');
            if ($refresh_token === '' && $stored_refresh_token !== '') {
                $refresh_token = $stored_refresh_token;
            }
            $conn->prepare("
                UPDATE google_oauth_tokens
                SET access_token = ?, refresh_token = ?, expires_at = ?,
                    google_email = ?, calendar_id = ?, updated_at = CURRENT_TIMESTAMP
                WHERE admin_user_id = ?
            ")->execute([$access_token, $refresh_token, $expires_at, $google_email, $calendar_id, $admin_user_id]);
        } else {
            $conn->prepare("
                INSERT INTO google_oauth_tokens
                    (admin_user_id, access_token, refresh_token, expires_at, google_email, calendar_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$admin_user_id, $access_token, $refresh_token, $expires_at, $google_email, $calendar_id]);
        }

        self::clearOAuthFailureNotifications($admin_user_id);
    }

    /**
     * Delete the OAuth token for an admin user (disconnect).
     * Also attempts to revoke the token with Google.
     */
    public static function revokeOAuthToken(int $admin_user_id): bool {
        $token = self::getOAuthToken($admin_user_id);
        if ($token) {
            // Attempt to revoke with Google (best-effort)
            $revoke_token = self::rowString($token, 'refresh_token', self::rowString($token, 'access_token'));
            $result = self::httpPost('https://oauth2.googleapis.com/revoke', ['token' => $revoke_token]);
            if (!empty($result['error'])) {
                error_log('GoogleCalendarIntegration: token revocation returned error: ' . json_encode($result['error']));
            }
        }

        $db   = new Database();
        $conn = $db->getConnection();
        $conn->prepare("DELETE FROM google_oauth_tokens WHERE admin_user_id = ?")->execute([$admin_user_id]);
        self::clearOAuthFailureNotifications($admin_user_id);
        return true;
    }

    /**
     * Delete a calendar event from the connected OAuth calendar.
     * $event_id is the Google event ID stored in bookings.google_event_id.
     */
    public static function deleteEventOAuth(string $event_id, int $admin_user_id): bool {
        $access_token = self::getValidAccessToken($admin_user_id);
        if (!$access_token) {
            error_log('GoogleCalendarIntegration: deleteEventOAuth – no valid token for admin_user_id=' . $admin_user_id);
            return false;
        }

        $token_row   = self::getOAuthToken($admin_user_id);
        $calendar_id = is_array($token_row) ? self::rowString($token_row, 'calendar_id', 'primary') : 'primary';

        $url = 'https://www.googleapis.com/calendar/v3/calendars/'
            . urlencode($calendar_id) . '/events/' . urlencode($event_id)
            . '?sendUpdates=none';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
        $result   = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            error_log('GoogleCalendarIntegration: deleteEventOAuth cURL error: ' . $curl_err);
            self::createOAuthFailureNotification($admin_user_id, $token_row);
            return false;
        }
        // 204 No Content = success; 410 Gone = already deleted – both are acceptable
        if ($http_code === 204 || $http_code === 410) {
            self::clearOAuthFailureNotifications($admin_user_id);
            return true;
        }
        $body = json_decode(scalar_string($result ?: '{}'), true);
        $error = is_array($body) && isset($body['error']) && is_array($body['error'])
            ? scalar_string($body['error']['message'] ?? ('HTTP ' . $http_code))
            : 'HTTP ' . $http_code;
        error_log('GoogleCalendarIntegration: deleteEventOAuth failed for event_id=' . $event_id . ', admin_user_id=' . $admin_user_id . ', error=' . $error);
        self::createOAuthFailureNotification($admin_user_id, $token_row);
        return false;
    }

    /**
     * Add a booking event to the connected calendar for a given admin user using OAuth.
     *
     * @param BookingRow $booking
     * @return CalendarResult
     */
    public static function addEventOAuth(array $booking, int $admin_user_id): array {
        $access_token = self::getValidAccessToken($admin_user_id);
        if (!$access_token) {
            error_log('GoogleCalendarIntegration: no valid access token for admin_user_id=' . $admin_user_id);
            return ['success' => false, 'message' => 'No valid OAuth token for this user'];
        }

        $token_row   = self::getOAuthToken($admin_user_id);
        $calendar_id = is_array($token_row) ? self::rowString($token_row, 'calendar_id', 'primary') : 'primary';

        $instance   = new self();
        $event_body = $instance->buildEventBody($booking);

        // sendUpdates=none prevents Google from emailing attendees (avoids scope/policy issues)
        $url      = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($calendar_id) . '/events?sendUpdates=none';
        $response = self::httpPost($url, $event_body, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ], true);
        $http_error_response = self::consumeLastHttpErrorResponse();
        if ($http_error_response !== []) {
            self::createOAuthFailureNotification($admin_user_id, $token_row);
            $http_error = $http_error_response['error'];
            return ['success' => false, 'message' => scalar_string($http_error['message'] ?? 'Unknown error inserting event')];
        }

        if (!empty($response['id'])) {
            self::clearOAuthFailureNotifications($admin_user_id);
            return ['success' => true, 'event_id' => array_string_value($response, 'id'), 'link' => array_string_value($response, 'htmlLink')];
        }

        $response_error = isset($response['error']) && is_array($response['error']) ? $response['error'] : [];
        $error = scalar_string($response_error['message'] ?? 'Unknown error inserting event');
        $error_code = scalar_string($response_error['code'] ?? 'unknown');
        error_log('GoogleCalendarIntegration: addEventOAuth failed for admin_user_id=' . $admin_user_id . ', calendar=' . $calendar_id . ', http_error=' . $error_code . ': ' . $error);
        self::createOAuthFailureNotification($admin_user_id, $token_row);
        return ['success' => false, 'message' => $error];
    }

    /**
     * Update an existing Google Calendar event for the given admin user using OAuth.
     * $event_id is the Google event ID stored in bookings.google_event_id.
     * Uses a PUT (full update) request so all event fields – including location – are refreshed.
     *
     * @param BookingRow $booking
     * @return CalendarResult
     */
    public static function updateEventOAuth(array $booking, string $event_id, int $admin_user_id): array {
        $access_token = self::getValidAccessToken($admin_user_id);
        if (!$access_token) {
            error_log('GoogleCalendarIntegration: updateEventOAuth – no valid token for admin_user_id=' . $admin_user_id);
            return ['success' => false, 'message' => 'No valid OAuth token for this user'];
        }

        $token_row   = self::getOAuthToken($admin_user_id);
        $calendar_id = is_array($token_row) ? self::rowString($token_row, 'calendar_id', 'primary') : 'primary';

        $instance   = new self();
        $event_body = $instance->buildEventBody($booking);

        // PUT replaces the entire event resource; sendUpdates=none suppresses attendee emails
        $url = 'https://www.googleapis.com/calendar/v3/calendars/'
            . urlencode($calendar_id) . '/events/' . urlencode($event_id)
            . '?sendUpdates=none';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        $body = scalar_string(json_encode($event_body));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
        ]);
        $result    = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            error_log('GoogleCalendarIntegration: updateEventOAuth cURL error: ' . $curl_err);
            self::createOAuthFailureNotification($admin_user_id, $token_row);
            return ['success' => false, 'message' => $curl_err];
        }

        /** @var array<string, mixed> $response */
        $response = json_decode(scalar_string($result ?: '{}'), true) ?: [];

        if (!empty($response['id'])) {
            self::clearOAuthFailureNotifications($admin_user_id);
            return ['success' => true, 'event_id' => array_string_value($response, 'id'), 'link' => array_string_value($response, 'htmlLink')];
        }

        $response_error = isset($response['error']) && is_array($response['error']) ? $response['error'] : [];
        $error      = scalar_string($response_error['message'] ?? 'Unknown error updating event');
        $error_code = scalar_string($response_error['code'] ?? 'unknown');
        error_log('GoogleCalendarIntegration: updateEventOAuth failed for event_id=' . $event_id . ', admin_user_id=' . $admin_user_id . ', http_error=' . $error_code . ': ' . $error);
        self::createOAuthFailureNotification($admin_user_id, $token_row);
        return ['success' => false, 'message' => $error];
    }

    /**
     * Query Google Calendar for busy periods on a given date for a specific admin user.
     *
     * Uses the freebusy API to find any events marked as "busy" on the calendar
     * for the full day. Returns an array of busy windows in the form:
     *   [['start' => '<RFC3339>', 'end' => '<RFC3339>'], ...]
     * Returns an empty array on any failure (network error, expired token, etc.)
     * so that callers can degrade gracefully.
     *
     * @param  string $date          Date string in Y-m-d format.
     * @param  int    $admin_user_id The admin user whose connected calendar is queried.
     * @return array<int, array{start: string, end: string}>
     */
    public static function getFreeBusy(string $date, int $admin_user_id): array {
        $access_token = self::getValidAccessToken($admin_user_id);
        if (!$access_token) {
            return [];
        }

        $token_row   = self::getOAuthToken($admin_user_id);
        $calendar_id = is_array($token_row) ? self::rowString($token_row, 'calendar_id', 'primary') : 'primary';
        $timezone    = getSystemTimezone();

        try {
            $tz_obj    = new DateTimeZone($timezone);
            $day_start = new DateTime($date . 'T00:00:00', $tz_obj);
            $day_end   = new DateTime($date . 'T23:59:59', $tz_obj);
        } catch (Exception $e) {
            error_log('GoogleCalendarIntegration: getFreeBusy – invalid date "' . $date . '": ' . $e->getMessage());
            return [];
        }

        $request_body = [
            'timeMin'  => $day_start->format(DateTime::RFC3339),
            'timeMax'  => $day_end->format(DateTime::RFC3339),
            'timeZone' => $timezone,
            'items'    => [['id' => $calendar_id]],
        ];

        $response = self::httpPost(
            'https://www.googleapis.com/calendar/v3/freeBusy',
            $request_body,
            [
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/json',
            ],
            true
        );
        $http_error_response = self::consumeLastHttpErrorResponse();
        if ($http_error_response !== []) {
            self::createOAuthFailureNotification($admin_user_id, $token_row);
            return [];
        }

        if (!empty($response['error'])) {
            error_log('GoogleCalendarIntegration: getFreeBusy error for admin_user_id=' . $admin_user_id . ': ' . json_encode($response['error']));
            self::createOAuthFailureNotification($admin_user_id, $token_row);
            return [];
        }

        $calendars = $response['calendars'] ?? null;
        if (!is_array($calendars)) {
            return [];
        }
        $calendar = $calendars[$calendar_id] ?? null;
        if (!is_array($calendar) || !isset($calendar['busy']) || !is_array($calendar['busy'])) {
            return [];
        }
        self::clearOAuthFailureNotifications($admin_user_id);
        /** @var array<int, array{start: string, end: string}> $busy */
        $busy = $calendar['busy'];
        return $busy;
    }

    /**
     * Query Google Calendar free/busy for a DATE RANGE in a single API call.
     *
     * Returns all busy windows across the range as a flat array:
     *   [['start' => '<RFC3339>', 'end' => '<RFC3339>'], ...]
     *
     * This avoids making one API call per day when checking many dates (e.g., the
     * available_dates endpoint scans 60+ days at once).
     *
     * @param  string $start_date     First date of the range (Y-m-d).
     * @param  string $end_date       Last date of the range  (Y-m-d, inclusive).
     * @param  int    $admin_user_id  The admin whose connected calendar is queried.
     * @return array<int, array{start: string, end: string}>
     */
    public static function getFreeBusyRange(string $start_date, string $end_date, int $admin_user_id): array {
        $access_token = self::getValidAccessToken($admin_user_id);
        if (!$access_token) {
            return [];
        }

        $token_row   = self::getOAuthToken($admin_user_id);
        $calendar_id = is_array($token_row) ? self::rowString($token_row, 'calendar_id', 'primary') : 'primary';
        $timezone    = getSystemTimezone();

        try {
            $tz_obj      = new DateTimeZone($timezone);
            $range_start = new DateTime($start_date . 'T00:00:00', $tz_obj);
            $range_end   = new DateTime($end_date   . 'T23:59:59', $tz_obj);
        } catch (Exception $e) {
            error_log('GoogleCalendarIntegration: getFreeBusyRange – invalid dates "' . $start_date . '"/"' . $end_date . '": ' . $e->getMessage());
            return [];
        }

        $request_body = [
            'timeMin'  => $range_start->format(DateTime::RFC3339),
            'timeMax'  => $range_end->format(DateTime::RFC3339),
            'timeZone' => $timezone,
            'items'    => [['id' => $calendar_id]],
        ];

        $response = self::httpPost(
            'https://www.googleapis.com/calendar/v3/freeBusy',
            $request_body,
            [
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/json',
            ],
            true
        );
        $http_error_response = self::consumeLastHttpErrorResponse();
        if ($http_error_response !== []) {
            self::createOAuthFailureNotification($admin_user_id, $token_row);
            return [];
        }

        if (!empty($response['error'])) {
            error_log('GoogleCalendarIntegration: getFreeBusyRange error for admin_user_id=' . $admin_user_id . ': ' . json_encode($response['error']));
            self::createOAuthFailureNotification($admin_user_id, $token_row);
            return [];
        }

        $calendars = $response['calendars'] ?? null;
        if (!is_array($calendars)) {
            return [];
        }
        $calendar = $calendars[$calendar_id] ?? null;
        if (!is_array($calendar) || !isset($calendar['busy']) || !is_array($calendar['busy'])) {
            return [];
        }
        self::clearOAuthFailureNotifications($admin_user_id);
        /** @var array<int, array{start: string, end: string}> $busy */
        $busy = $calendar['busy'];
        return $busy;
    }

    /**
     * List available calendars for the given admin user.
     * Returns array of ['id' => ..., 'summary' => ...] or empty array on failure.
     *
     * @return list<array{id: string, summary: string}>
     */
    public static function listCalendars(int $admin_user_id): array {
        $access_token = self::getValidAccessToken($admin_user_id);
        if (!$access_token) {
            return [];
        }

        $response = self::httpGet(
            'https://www.googleapis.com/calendar/v3/users/me/calendarList',
            ['Authorization: Bearer ' . $access_token]
        );
        $http_error_response = self::consumeLastHttpErrorResponse();
        if ($http_error_response !== []) {
            self::createOAuthFailureNotification($admin_user_id);
            return [];
        }

        $items = $response['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return [];
        }

        self::clearOAuthFailureNotifications($admin_user_id);

        /** @var list<array<string, mixed>> $items */
        return array_map(
            fn(array $calendar): array => [
                'id' => scalar_string($calendar['id'] ?? ''),
                'summary' => scalar_string($calendar['summary'] ?? ($calendar['id'] ?? '')),
            ],
            $items
        );
    }

    // -------------------------------------------------------------------------
    // Internal HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * POST to a URL. Supports both form-encoded and JSON payloads.
     *
     * @param array<string, mixed> $data
     * @param list<string> $headers Extra HTTP headers.
     * @param bool $json Send body as JSON instead of form-encoded.
     * @return array<string, mixed>
     */
    private static function httpPost(string $url, array $data, array $headers = [], bool $json = false): array {
        self::$last_http_error_message = '';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        if ($json) {
            $body = scalar_string(json_encode($data));
            $headers[] = 'Content-Length: ' . strlen($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $result   = curl_exec($ch);
        $curl_err = curl_error($ch);
        curl_close($ch);
        if ($curl_err) {
            error_log('GoogleCalendarIntegration cURL POST error: ' . $curl_err);
            self::$last_http_error_message = $curl_err;
            return [];
        }
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(scalar_string($result ?: '{}'), true) ?: [];
        return $decoded;
    }

    /**
     * GET a URL with optional extra headers, returning decoded JSON.
     *
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private static function httpGet(string $url, array $headers = []): array {
        self::$last_http_error_message = '';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $result   = curl_exec($ch);
        $curl_err = curl_error($ch);
        curl_close($ch);
        if ($curl_err) {
            error_log('GoogleCalendarIntegration cURL GET error: ' . $curl_err);
            self::$last_http_error_message = $curl_err;
            return [];
        }
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(scalar_string($result ?: '{}'), true) ?: [];
        return $decoded;
    }
}
?>
