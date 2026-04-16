#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once dirname(__DIR__) . '/backend/includes/database.php';

function resetGoogleCalendarOAuthState(SafePDO $conn): void
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

function assertGoogleCalendarOAuth(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
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

$insert_setting = $conn->prepare('INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, ?)');
foreach ([
    'timezone' => 'UTC',
    'google_oauth_client_id' => '',
    'google_oauth_client_secret' => '',
] as $key => $value) {
    $insert_setting->execute([$key, $value, 'text']);
}

resetGoogleCalendarOAuthState($conn);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/backend/includes/google_calendar.php';

$exit_code = 0;

try {
    $insert_token = $conn->prepare('
        INSERT INTO google_oauth_tokens (
            admin_user_id, access_token, refresh_token, expires_at, google_email, calendar_id, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ');
    $insert_token->execute([1, 'old-access-token', 'persisted-refresh-token', '2026-01-01 00:00:00', 'trainer@example.com', 'calendar-a']);

    GoogleCalendarIntegration::saveOAuthToken(
        1,
        'new-access-token',
        '',
        3600,
        'trainer@example.com',
        'calendar-a'
    );

    $token_row = $conn->query('SELECT access_token, refresh_token, google_email, calendar_id FROM google_oauth_tokens WHERE admin_user_id = 1')->fetch(PDO::FETCH_ASSOC);
    assertGoogleCalendarOAuth(is_array($token_row), 'Expected OAuth token row to exist after updating.');
    assertGoogleCalendarOAuth(
        scalar_string($token_row['access_token'] ?? '') === 'new-access-token',
        'Expected access token to be updated during OAuth token save.'
    );
    assertGoogleCalendarOAuth(
        scalar_string($token_row['refresh_token'] ?? '') === 'persisted-refresh-token',
        'Expected existing refresh token to be preserved when Google omits refresh_token on re-auth.'
    );

    GoogleCalendarIntegration::saveOAuthToken(
        1,
        'latest-access-token',
        'replacement-refresh-token',
        3600,
        'trainer@example.com',
        'calendar-b'
    );

    $updated_row = $conn->query('SELECT access_token, refresh_token, calendar_id FROM google_oauth_tokens WHERE admin_user_id = 1')->fetch(PDO::FETCH_ASSOC);
    assertGoogleCalendarOAuth(is_array($updated_row), 'Expected OAuth token row to remain available after replacing refresh token.');
    assertGoogleCalendarOAuth(
        scalar_string($updated_row['access_token'] ?? '') === 'latest-access-token',
        'Expected later OAuth updates to continue replacing the access token.'
    );
    assertGoogleCalendarOAuth(
        scalar_string($updated_row['refresh_token'] ?? '') === 'replacement-refresh-token',
        'Expected a newly supplied refresh token to replace the stored refresh token.'
    );
    assertGoogleCalendarOAuth(
        scalar_string($updated_row['calendar_id'] ?? '') === 'calendar-b',
        'Expected other token metadata to keep updating normally.'
    );

    echo "Google Calendar OAuth refresh-token preservation regression test passed.\n";
} catch (Throwable $e) {
    $exit_code = 1;
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
} finally {
    resetGoogleCalendarOAuthState($conn);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

exit($exit_code);
