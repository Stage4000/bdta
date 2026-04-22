#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once dirname(__DIR__) . '/backend/includes/database.php';

function resetGoogleCalendarDeletionState(SafePDO $conn): void
{
    $database_reflection = new ReflectionClass(Database::class);
    $shared_connection = $database_reflection->getProperty('sharedConnection');
    $shared_connection->setAccessible(true);
    $shared_connection->setValue(null, $conn);

    require_once dirname(__DIR__) . '/backend/includes/settings.php';

    $settings_reflection = new ReflectionClass(Settings::class);
    $db_property = $settings_reflection->getProperty('db');
    $db_property->setAccessible(true);
    $db_property->setValue(null, null);

    $cache_property = $settings_reflection->getProperty('cache');
    $cache_property->setAccessible(true);
    $cache_property->setValue(null, []);
}

function assertGoogleCalendarDeletion(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertPortalActionBlockDoesNotRequireOAuth(string $portal_api, string $action_name, string $calendar_call, string $message): void
{
    $action_marker = "if (\$action === '{$action_name}')";
    $action_offset = strpos($portal_api, $action_marker);
    assertGoogleCalendarDeletion($action_offset !== false, 'Unable to locate the ' . $action_name . ' action block in portal/api_appointments.php.');

    $next_action_offset = strpos($portal_api, "if (\$action === '", $action_offset + strlen($action_marker));
    $action_block = $next_action_offset === false
        ? substr($portal_api, $action_offset)
        : substr($portal_api, $action_offset, $next_action_offset - $action_offset);

    assertGoogleCalendarDeletion(is_string($action_block) && $action_block !== '', 'Unable to isolate the ' . $action_name . ' action block in portal/api_appointments.php.');
    assertGoogleCalendarDeletion(
        str_contains($action_block, $calendar_call),
        'Expected the ' . $action_name . ' action block to call ' . $calendar_call . '.'
    );
    assertGoogleCalendarDeletion(
        !str_contains($action_block, 'GoogleCalendarIntegration::isOAuthConfigured()'),
        $message
    );
}

class Google_Client
{
    public array $scopes = [];
    public string $auth_config = '';

    public function setAuthConfig(string $path): void
    {
        $this->auth_config = $path;
    }

    public function addScope(string $scope): void
    {
        $this->scopes[] = $scope;
    }
}

class GoogleCalendarDeletionEventsStub
{
    /** @var list<array{calendar_id: string, event_id: string}> */
    public static array $delete_calls = [];

    public function delete(string $calendar_id, string $event_id): void
    {
        self::$delete_calls[] = [
            'calendar_id' => $calendar_id,
            'event_id' => $event_id,
        ];
    }
}

class Google_Service_Calendar
{
    public const CALENDAR = 'https://www.googleapis.com/auth/calendar';

    public GoogleCalendarDeletionEventsStub $events;

    public function __construct(Google_Client $client)
    {
        $this->events = new GoogleCalendarDeletionEventsStub();
    }
}

$conn = new SafePDO('sqlite::memory:');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);
$conn->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, setting_type TEXT)');
$conn->exec('CREATE TABLE google_oauth_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_user_id INTEGER NOT NULL,
    access_token TEXT,
    refresh_token TEXT,
    expires_at TEXT,
    google_email TEXT,
    calendar_id TEXT,
    updated_at TEXT
)');

$credentials_file = tempnam(sys_get_temp_dir(), 'gcal-test-credentials-');
if ($credentials_file === false) {
    throw new RuntimeException('Unable to create temporary Google Calendar credentials fixture.');
}
file_put_contents($credentials_file, '{}');

$insert_setting = $conn->prepare('INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, ?)');
foreach ([
    'google_calendar_enabled' => ['1', 'boolean'],
    'google_calendar_id' => ['service-account-calendar@example.com', 'text'],
    'google_calendar_credentials_file' => [$credentials_file, 'text'],
    'google_oauth_client_id' => ['', 'text'],
    'google_oauth_client_secret' => ['', 'text'],
] as $key => [$value, $type]) {
    $insert_setting->execute([$key, $value, $type]);
}

resetGoogleCalendarDeletionState($conn);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/backend/includes/google_calendar.php';

$exit_code = 0;

try {
    GoogleCalendarDeletionEventsStub::$delete_calls = [];

    $deleted = GoogleCalendarIntegration::deleteEventForBooking('booking-event-123', [
        'admin_user_id' => 0,
        'appointment_type_id' => 0,
    ]);
    assertGoogleCalendarDeletion($deleted, 'Expected booking deletion to fall back to the legacy Google Calendar integration.');
    assertGoogleCalendarDeletion(
        GoogleCalendarDeletionEventsStub::$delete_calls === [[
            'calendar_id' => 'service-account-calendar@example.com',
            'event_id' => 'booking-event-123',
        ]],
        'Expected the legacy Google Calendar delete request to target the configured calendar and event id.'
    );

    $portal_api = file_get_contents(dirname(__DIR__) . '/portal/api_appointments.php');
    assertGoogleCalendarDeletion(is_string($portal_api) && $portal_api !== '', 'Unable to read portal appointment API fixture.');
    assertPortalActionBlockDoesNotRequireOAuth(
        $portal_api,
        'cancel',
        'GoogleCalendarIntegration::deleteEventForBooking',
        'Portal appointment cancellations should not gate Google Calendar cleanup behind OAuth-only configuration checks.'
    );
    assertPortalActionBlockDoesNotRequireOAuth(
        $portal_api,
        'reschedule',
        'GoogleCalendarIntegration::updateEventForBooking',
        'Portal appointment reschedules should not gate Google Calendar updates behind OAuth-only configuration checks.'
    );

    echo "Google Calendar booking deletion regression test passed.\n";
} catch (Throwable $e) {
    $exit_code = 1;
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
} finally {
    if (is_string($credentials_file) && $credentials_file !== '' && file_exists($credentials_file)) {
        unlink($credentials_file);
    }
    resetGoogleCalendarDeletionState($conn);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

exit($exit_code);
