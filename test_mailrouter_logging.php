<?php

require_once __DIR__ . '/backend/includes/email_service.php';

$log_path = __DIR__ . '/backend/logs/mailrouter.log';
$error_log_path = sys_get_temp_dir() . '/test-mailrouter-error-' . uniqid('', true) . '.log';
$unique_token = 'mailrouter-test-' . uniqid('', true);
$log_previously_existed = file_exists($log_path);
$original_log_contents = $log_previously_existed ? file_get_contents($log_path) : null;

ini_set('log_errors', '1');
ini_set('error_log', $error_log_path);

/** @var array<string, scalar|null> $original_settings */
$original_settings = [
    'email_service' => Settings::get('email_service', 'mail'),
    'smtp_host' => Settings::get('smtp_host', ''),
    'smtp_port' => Settings::get('smtp_port', 587),
    'smtp_encryption' => Settings::get('smtp_encryption', 'tls'),
    'smtp_username' => Settings::get('smtp_username', ''),
    'smtp_password' => Settings::get('smtp_password', ''),
];

try {
    Settings::set('email_service', 'smtp');
    Settings::set('smtp_host', 'invalid.invalid');
    Settings::set('smtp_port', '587');
    Settings::set('smtp_encryption', 'tls');
    Settings::set('smtp_username', '');
    Settings::set('smtp_password', '');

    $email_service = new EmailService();
    $result = $email_service->sendGenericEmail(
        'client@example.com',
        'MailRouter Logging Test ' . $unique_token,
        '<p>Hello</p>',
        'Hello',
        EmailService::MAIL_TYPE_GENERIC
    );

    if ($result['success']) {
        fwrite(STDERR, "Expected the email send to fail due to the invalid SMTP host configured by the test.\n");
        exit(1);
    }

    if (!file_exists($log_path)) {
        fwrite(STDERR, "MailRouter log file was not created.\n");
        exit(1);
    }

    $mailrouter_log = file_get_contents($log_path);
    if ($mailrouter_log === false) {
        fwrite(STDERR, "MailRouter log file could not be read.\n");
        exit(1);
    }

    if (strpos($mailrouter_log, $unique_token) === false) {
        fwrite(STDERR, "MailRouter log file does not contain the expected routing entry.\n");
        exit(1);
    }

    if (strpos($mailrouter_log, '[MailRouter] FAILED') === false) {
        fwrite(STDERR, "MailRouter log file does not contain the failed delivery entry.\n");
        exit(1);
    }

    $error_log_contents = file_exists($error_log_path) ? file_get_contents($error_log_path) : '';
    if ($error_log_contents !== false && strpos($error_log_contents, '[MailRouter] ROUTING') !== false) {
        fwrite(STDERR, "MailRouter routing entries still reached the PHP error log.\n");
        exit(1);
    }

    if ($error_log_contents !== false && strpos($error_log_contents, '[MailRouter] SENT') !== false) {
        fwrite(STDERR, "MailRouter sent entries still reached the PHP error log.\n");
        exit(1);
    }

    if ($error_log_contents !== false && strpos($error_log_contents, '[MailRouter] FAILED') !== false) {
        fwrite(STDERR, "MailRouter failed entries still reached the PHP error log.\n");
        exit(1);
    }
} finally {
    foreach ($original_settings as $key => $value) {
        Settings::set($key, $value);
    }

    if (file_exists($error_log_path)) {
        unlink($error_log_path);
    }

    if (!$log_previously_existed && file_exists($log_path)) {
        unlink($log_path);
    } elseif ($log_previously_existed && is_string($original_log_contents)) {
        file_put_contents($log_path, $original_log_contents, LOCK_EX);
    }
}

echo "MailRouter logging test passed.\n";
