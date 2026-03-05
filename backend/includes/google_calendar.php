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

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/database.php';

class GoogleCalendarIntegration {
    private $credentials_file;
    private $calendar_id;

    public function __construct() {
        $this->calendar_id       = Settings::get('google_calendar_id', 'primary');
        $credentials_path        = Settings::get('google_calendar_credentials_file', __DIR__ . '/google-calendar-credentials.json');
        $this->credentials_file  = $credentials_path;
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /**
     * Build the Google Calendar event body array from a booking row.
     */
    private function buildEventBody(array $booking): array {
        $timezone = 'America/New_York';
        // Normalise to HH:MM – MySQL TIME columns return HH:MM:SS which would
        // produce an invalid RFC3339 string like "2026-03-02T14:30:00:00".
        $time_hhmm = substr($booking['appointment_time'], 0, 5);
        $start_dt  = $booking['appointment_date'] . 'T' . $time_hhmm . ':00';
        $duration  = (int)($booking['duration_minutes'] ?? 60);
        // Use the full date+time so end-time is correct even when crossing midnight
        $start_ts  = strtotime($booking['appointment_date'] . ' ' . $time_hhmm);
        if ($start_ts === false || $start_ts === 0) {
            // Fallback: parse time only (today's date) – end date will match start date
            $start_ts = strtotime($time_hhmm) ?: time();
        }
        $end_ts = $start_ts + $duration * 60;
        $end_dt = date('Y-m-d', $end_ts) . 'T' . date('H:i:s', $end_ts);

        return [
            'summary'     => ($booking['service_type'] ?? 'Appointment') . ' - ' . ($booking['client_name'] ?? ''),
            'description' => implode("\n", array_filter([
                'Client: '  . ($booking['client_name']  ?? ''),
                'Email: '   . ($booking['client_email'] ?? ''),
                'Phone: '   . ($booking['client_phone'] ?? ''),
                'Notes: '   . ($booking['notes']        ?? ''),
            ])),
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
     */
    public function addEvent(array $booking): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'Google Calendar not configured'];
        }

        try {
            if (!class_exists('Google_Client')) {
                return ['success' => false, 'message' => 'Google API client library not installed. Run: composer require google/apiclient'];
            }

            $client = new Google_Client();
            $client->setAuthConfig($this->credentials_file);
            $client->addScope(Google_Service_Calendar::CALENDAR);

            $service = new Google_Service_Calendar($client);
            $event   = new Google_Service_Calendar_Event($this->buildEventBody($booking));
            $created = $service->events->insert($this->calendar_id, $event);

            return ['success' => true, 'event_id' => $created->getId(), 'link' => $created->getHtmlLink()];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
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
     */
    public static function getOAuthToken(int $admin_user_id): ?array {
        $db   = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("SELECT * FROM google_oauth_tokens WHERE admin_user_id = ? LIMIT 1");
        $stmt->execute([$admin_user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
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
        if (!empty($token['expires_at']) && strtotime($token['expires_at']) < (time() + 60)) {
            if (empty($token['refresh_token'])) {
                error_log('GoogleCalendarIntegration: token for admin_user_id=' . $admin_user_id . ' is expired and no refresh_token stored');
                return null;
            }
            $refreshed = self::refreshAccessToken($token['refresh_token'], $admin_user_id);
            if (!$refreshed) {
                error_log('GoogleCalendarIntegration: failed to refresh token for admin_user_id=' . $admin_user_id);
            }
            return $refreshed;
        }

        return $token['access_token'];
    }

    /**
     * Refresh the access token using the stored refresh token.
     * Saves the new token to the database and returns the new access token, or null on failure.
     */
    private static function refreshAccessToken(string $refresh_token, int $admin_user_id): ?string {
        $client_id     = Settings::get('google_oauth_client_id', '');
        $client_secret = Settings::get('google_oauth_client_secret', '');

        $response = self::httpPost('https://oauth2.googleapis.com/token', [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
        ]);

        if (empty($response['access_token'])) {
            return null;
        }

        $expires_at = date('Y-m-d H:i:s', time() + (int)($response['expires_in'] ?? 3600));

        $db   = new Database();
        $conn = $db->getConnection();
        $conn->prepare("
            UPDATE google_oauth_tokens
            SET access_token = ?, expires_at = ?, updated_at = CURRENT_TIMESTAMP
            WHERE admin_user_id = ?
        ")->execute([$response['access_token'], $expires_at, $admin_user_id]);

        return $response['access_token'];
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
        $existing = $conn->prepare("SELECT id FROM google_oauth_tokens WHERE admin_user_id = ?");
        $existing->execute([$admin_user_id]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row) {
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
    }

    /**
     * Delete the OAuth token for an admin user (disconnect).
     * Also attempts to revoke the token with Google.
     */
    public static function revokeOAuthToken(int $admin_user_id): bool {
        $token = self::getOAuthToken($admin_user_id);
        if ($token) {
            // Attempt to revoke with Google (best-effort)
            $revoke_token = $token['refresh_token'] ?: $token['access_token'];
            $result = self::httpPost('https://oauth2.googleapis.com/revoke', ['token' => $revoke_token]);
            if (!empty($result['error'])) {
                error_log('GoogleCalendarIntegration: token revocation returned error: ' . json_encode($result['error']));
            }
        }

        $db   = new Database();
        $conn = $db->getConnection();
        $conn->prepare("DELETE FROM google_oauth_tokens WHERE admin_user_id = ?")->execute([$admin_user_id]);
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
        $calendar_id = $token_row['calendar_id'] ?? 'primary';

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
            return false;
        }
        // 204 No Content = success; 410 Gone = already deleted – both are acceptable
        if ($http_code === 204 || $http_code === 410) {
            return true;
        }
        $body = json_decode($result ?: '{}', true);
        $error = $body['error']['message'] ?? ('HTTP ' . $http_code);
        error_log('GoogleCalendarIntegration: deleteEventOAuth failed for event_id=' . $event_id . ', admin_user_id=' . $admin_user_id . ', error=' . $error);
        return false;
    }

    /**
     * Add a booking event to the connected calendar for a given admin user using OAuth.
     */
    public static function addEventOAuth(array $booking, int $admin_user_id): array {
        $access_token = self::getValidAccessToken($admin_user_id);
        if (!$access_token) {
            error_log('GoogleCalendarIntegration: no valid access token for admin_user_id=' . $admin_user_id);
            return ['success' => false, 'message' => 'No valid OAuth token for this user'];
        }

        $token_row   = self::getOAuthToken($admin_user_id);
        $calendar_id = $token_row['calendar_id'] ?? 'primary';

        $instance   = new self();
        $event_body = $instance->buildEventBody($booking);

        // sendUpdates=none prevents Google from emailing attendees (avoids scope/policy issues)
        $url      = 'https://www.googleapis.com/calendar/v3/calendars/' . urlencode($calendar_id) . '/events?sendUpdates=none';
        $response = self::httpPost($url, $event_body, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ], true);

        if (!empty($response['id'])) {
            return ['success' => true, 'event_id' => $response['id'], 'link' => $response['htmlLink'] ?? ''];
        }

        $error = $response['error']['message'] ?? 'Unknown error inserting event';
        $error_code = $response['error']['code'] ?? 'unknown';
        error_log('GoogleCalendarIntegration: addEventOAuth failed for admin_user_id=' . $admin_user_id . ', calendar=' . $calendar_id . ', http_error=' . $error_code . ': ' . $error);
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
        $calendar_id = $token_row['calendar_id'] ?? 'primary';
        $timezone    = 'America/New_York';

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

        if (!empty($response['error'])) {
            error_log('GoogleCalendarIntegration: getFreeBusy error for admin_user_id=' . $admin_user_id . ': ' . json_encode($response['error']));
            return [];
        }

        return $response['calendars'][$calendar_id]['busy'] ?? [];
    }

    /**
     * List available calendars for the given admin user.
     * Returns array of ['id' => ..., 'summary' => ...] or empty array on failure.
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

        if (empty($response['items'])) {
            return [];
        }

        return array_map(fn($c) => ['id' => $c['id'], 'summary' => $c['summary'] ?? $c['id']], $response['items']);
    }

    // -------------------------------------------------------------------------
    // Internal HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * POST to a URL. Supports both form-encoded and JSON payloads.
     * @param array|string $headers  Extra HTTP headers.
     * @param bool         $json     Send body as JSON instead of form-encoded.
     */
    private static function httpPost(string $url, array $data, array $headers = [], bool $json = false): array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        if ($json) {
            $body = json_encode($data);
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
            return [];
        }
        return json_decode($result ?: '{}', true) ?: [];
    }

    /**
     * GET a URL with optional extra headers, returning decoded JSON.
     */
    private static function httpGet(string $url, array $headers = []): array {
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
            return [];
        }
        return json_decode($result ?: '{}', true) ?: [];
    }
}
?>
