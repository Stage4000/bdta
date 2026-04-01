#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

const EMAIL_TRANSPORT_WHITESPACE_ONLY = '   ';

/**
 * @param array<string, string> $settings
 */
function seedEmailServiceTransportSettings(SafePDO $conn, array $settings): void {
    $conn->exec('DELETE FROM settings');

    $insert = $conn->prepare('INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, ?)');
    foreach ($settings as $key => $value) {
        $insert->execute([$key, $value, 'text']);
    }
}

function resetEmailServiceTransportState(SafePDO $conn): void {
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

function assertEmailServiceTransport(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function deleteEmailTransportTestFile(string $path, string $allowed_directory, string $required_basename = ''): void {
    if (str_contains($path, "\0") || str_contains($allowed_directory, "\0")) {
        return;
    }

    $real_path = realpath($path);
    $real_allowed_directory = realpath($allowed_directory);

    if ($real_path === false || $real_allowed_directory === false) {
        return;
    }

    if (!str_starts_with($real_path, $real_allowed_directory . DIRECTORY_SEPARATOR)) {
        return;
    }

    if (dirname($real_path) !== $real_allowed_directory) {
        return;
    }

    if ($required_basename !== '' && basename($real_path) !== $required_basename) {
        return;
    }

    // nosemgrep: php.lang.security.unlink-use.unlink-use
    unlink($real_path);
}

$conn = new SafePDO('sqlite::memory:');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);
$conn->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, setting_type TEXT)');

resetEmailServiceTransportState($conn);

require_once dirname(__DIR__) . '/backend/includes/email_service.php';

$defaults = [
    'timezone' => 'UTC',
    'enable_email_signatures' => '0',
    'smtp_debug' => '0',
    'email_from_address' => 'bookings@example.com',
    'email_from_name' => 'BDTA Test',
    'smtp_host' => '',
    'smtp_username' => '',
    'smtp_password' => '',
    'smtp_port' => '587',
    'smtp_encryption' => 'tls',
];

$log_path = dirname(__DIR__) . '/backend/logs/mailrouter.log';
$log_previously_existed = file_exists($log_path);
$original_log_contents = $log_previously_existed ? file_get_contents($log_path) : null;
$exit_code = 0;

try {
    seedEmailServiceTransportSettings($conn, array_merge($defaults, [
        'email_service' => 'sendgrid',
    ]));
    resetEmailServiceTransportState($conn);

    $email_service = new EmailService();
    $sendgrid_result = $email_service->sendGenericEmail(
        'client@example.com',
        'Transport selection regression',
        '<p>Hello</p>',
        'Hello',
        EmailService::MAIL_TYPE_GENERIC
    );

    assertEmailServiceTransport($sendgrid_result['success'] === false, 'Expected SendGrid transport regression case to fail without an SMTP host.');
    assertEmailServiceTransport(
        str_contains($sendgrid_result['message'], 'SMTP host is not configured'),
        'Expected SendGrid transport option to use the SMTP code path.'
    );

    seedEmailServiceTransportSettings($conn, array_merge($defaults, [
        'email_service' => ' SMTP ',
        'smtp_host' => EMAIL_TRANSPORT_WHITESPACE_ONLY,
        'smtp_username' => EMAIL_TRANSPORT_WHITESPACE_ONLY,
        'smtp_password' => EMAIL_TRANSPORT_WHITESPACE_ONLY,
        'smtp_encryption' => 'invalid',
    ]));
    resetEmailServiceTransportState($conn);

    $email_service = new EmailService();
    $trimmed_smtp_result = $email_service->sendGenericEmail(
        'client@example.com',
        'Trimmed SMTP transport regression',
        '<p>Hello</p>',
        'Hello',
        EmailService::MAIL_TYPE_GENERIC
    );

    assertEmailServiceTransport($trimmed_smtp_result['success'] === false, 'Expected trimmed SMTP regression case to fail without an SMTP host.');
    assertEmailServiceTransport(
        str_contains($trimmed_smtp_result['message'], 'SMTP host is not configured'),
        'Expected whitespace-padded SMTP selection to use the SMTP code path.'
    );

    $email_service_reflection = new ReflectionClass(EmailService::class);
    $trimmed_setting = $email_service_reflection->getMethod('trimmedSettingString');
    $trimmed_setting->setAccessible(true);
    $uses_smtp_transport = $email_service_reflection->getMethod('usesSmtpTransport');
    $uses_smtp_transport->setAccessible(true);
    $normalize_smtp_encryption = $email_service_reflection->getMethod('normalizeSmtpEncryption');
    $normalize_smtp_encryption->setAccessible(true);

    seedEmailServiceTransportSettings($conn, array_merge($defaults, [
        'email_service' => ' SMTP ',
        'smtp_host' => ' smtp.example.test ',
        'smtp_username' => EMAIL_TRANSPORT_WHITESPACE_ONLY,
        'smtp_password' => EMAIL_TRANSPORT_WHITESPACE_ONLY,
        'smtp_encryption' => ' invalid ',
    ]));
    resetEmailServiceTransportState($conn);

    $trimmed_service = $trimmed_setting->invoke(null, 'email_service', 'mail');
    assertEmailServiceTransport(is_string($trimmed_service), 'Expected trimmed email service setting to be a string.');
    $normalized_service = strtolower($trimmed_service);

    assertEmailServiceTransport(
        $trimmed_setting->invoke(null, 'smtp_host', '') === 'smtp.example.test',
        'Expected SMTP host setting to be trimmed before transport configuration.'
    );
    assertEmailServiceTransport(
        $trimmed_setting->invoke(null, 'smtp_username', '') === '',
        'Expected whitespace-only SMTP username to be treated as empty.'
    );
    assertEmailServiceTransport(
        $trimmed_setting->invoke(null, 'smtp_password', '') === '',
        'Expected whitespace-only SMTP password to be treated as empty.'
    );
    assertEmailServiceTransport(
        $uses_smtp_transport->invoke(null, $normalized_service) === true,
        'Expected trimmed provider selection to resolve to the SMTP transport.'
    );
    assertEmailServiceTransport(
        $normalize_smtp_encryption->invoke(null, ' invalid ') === 'tls',
        'Expected invalid SMTP encryption values to fall back to tls.'
    );

    echo "Email service transport regression test passed.\n";
} catch (Throwable $e) {
    $exit_code = 1;
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
} finally {
    resetEmailServiceTransportState($conn);

    if (!$log_previously_existed && file_exists($log_path)) {
        deleteEmailTransportTestFile($log_path, dirname($log_path), 'mailrouter.log');
    } elseif ($log_previously_existed && is_string($original_log_contents)) {
        file_put_contents($log_path, $original_log_contents, LOCK_EX);
    }
}

exit($exit_code);
