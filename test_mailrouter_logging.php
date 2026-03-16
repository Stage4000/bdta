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

function assertMailRouterTest(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return list<string>
 */
function mailRouterLogLinesContaining(string $log_contents, string $needle): array {
    $matching_lines = [];

    foreach (preg_split('/\r\n|\r|\n/', $log_contents) as $line) {
        if ($line !== '' && strpos($line, $needle) !== false) {
            $matching_lines[] = $line;
        }
    }

    return $matching_lines;
}

/**
 * Deletes only test/runtime files that resolve inside the expected directory.
 * Missing files are treated as a no-op so cleanup can safely run from finally blocks.
 * When both basename filters are provided, both conditions must match.
 */
function deleteMailRouterTestFile(
    string $path,
    string $allowed_directory,
    string $required_basename = '',
    string $required_basename_prefix = ''
): void {
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

    if ($required_basename_prefix !== '' && !str_starts_with(basename($real_path), $required_basename_prefix)) {
        return;
    }

    // nosemgrep: php.lang.security.unlink-use.unlink-use
    unlink($real_path);
}

$exit_code = 0;

try {
    Settings::set('email_service', 'smtp');
    Settings::set('smtp_host', '');
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
        throw new RuntimeException('Expected the email send to fail due to the missing SMTP host configured by the test.');
    }

    assertMailRouterTest(file_exists($log_path), 'MailRouter log file was not created.');

    $mailrouter_log = file_get_contents($log_path);
    assertMailRouterTest($mailrouter_log !== false, 'MailRouter log file could not be read.');
    assertMailRouterTest(strpos($mailrouter_log, '[MailRouter] ROUTING') !== false, 'MailRouter log file does not contain the routing entry.');
    assertMailRouterTest(strpos($mailrouter_log, $unique_token) !== false, 'MailRouter log file does not contain the expected routing entry.');
    assertMailRouterTest(strpos($mailrouter_log, '[MailRouter] FAILED') !== false, 'MailRouter log file does not contain the failed delivery entry.');

    $initial_matching_lines = mailRouterLogLinesContaining($mailrouter_log, $unique_token);
    foreach ($initial_matching_lines as $line) {
        assertMailRouterTest(strpos($line, '[MailRouter] ') === 0, 'MailRouter log entry did not keep the documented prefix.');
        assertMailRouterTest(!preg_match('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC\]/', $line), 'MailRouter log entry unexpectedly includes a timestamp prefix.');
    }

    $forged_subject_result = $email_service->sendGenericEmail(
        'client@example.com',
        "Forged Subject\r\nInjected-Header: " . $unique_token,
        '<p>Hello</p>',
        'Hello',
        EmailService::MAIL_TYPE_GENERIC
    );
    assertMailRouterTest(!$forged_subject_result['success'], 'Expected the forged-subject email send to fail due to the missing SMTP host configured by the test.');

    $mailrouter_log = file_get_contents($log_path);
    assertMailRouterTest($mailrouter_log !== false, 'MailRouter log file could not be re-read.');
    assertMailRouterTest(strpos($mailrouter_log, "Forged Subject\r") === false, 'MailRouter log contains a raw carriage return sequence.');
    assertMailRouterTest(strpos($mailrouter_log, "Forged Subject\n") === false, 'MailRouter log contains a raw newline sequence.');
    assertMailRouterTest(strpos($mailrouter_log, 'Injected-Header: ' . $unique_token) !== false, 'MailRouter log does not contain the sanitized forged-subject content.');
    $forged_lines = mailRouterLogLinesContaining($mailrouter_log, 'Injected-Header: ' . $unique_token);
    if ($forged_lines === []) {
        throw new RuntimeException('MailRouter log does not contain the forged-subject log line.');
    }
    assertMailRouterTest(strpos($forged_lines[0], 'subject="Forged Subject Injected-Header: ' . $unique_token . '"') !== false, 'MailRouter log did not collapse the forged subject into a single sanitized line.');

    $error_log_contents = file_exists($error_log_path) ? file_get_contents($error_log_path) : '';
    assertMailRouterTest($error_log_contents === false || strpos($error_log_contents, '[MailRouter] ROUTING') === false, 'MailRouter routing entries still reached the PHP error log.');
    assertMailRouterTest($error_log_contents === false || strpos($error_log_contents, '[MailRouter] SENT') === false, 'MailRouter sent entries still reached the PHP error log.');
    assertMailRouterTest($error_log_contents === false || strpos($error_log_contents, '[MailRouter] FAILED') === false, 'MailRouter failed entries still reached the PHP error log.');
} catch (Throwable $e) {
    $exit_code = 1;
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
} finally {
    foreach ($original_settings as $key => $value) {
        Settings::set($key, $value);
    }

    deleteMailRouterTestFile($error_log_path, sys_get_temp_dir(), required_basename_prefix: 'test-mailrouter-error-');

    if (!$log_previously_existed && file_exists($log_path)) {
        deleteMailRouterTestFile($log_path, dirname($log_path), 'mailrouter.log');
    } elseif ($log_previously_existed && is_string($original_log_contents)) {
        file_put_contents($log_path, $original_log_contents, LOCK_EX);
    }
}

if ($exit_code === 0) {
    echo "MailRouter logging test passed.\n";
}

exit($exit_code);
