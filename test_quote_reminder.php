#!/usr/bin/env php
<?php
/**
 * Verify quote reminders skip expired quotes.
 */

require_once __DIR__ . '/backend/includes/database.php';
require_once __DIR__ . '/backend/includes/config.php';
require_once __DIR__ . '/backend/includes/settings.php';
require_once __DIR__ . '/backend/cron/tasks/quote_reminder.php';

function assertQuoteReminderTest(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new Database();
$conn = $db->getConnection();
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$unique_suffix = uniqid('quote_reminder_', false);

$log_path = __DIR__ . '/backend/logs/mailrouter.log';
$log_previously_existed = file_exists($log_path);
$original_log_contents = $log_previously_existed ? file_get_contents($log_path) : null;

/** @var array<string, scalar|null> $original_settings */
$original_settings = [
    'email_service' => Settings::get('email_service', 'mail'),
    'smtp_host' => Settings::get('smtp_host', ''),
    'smtp_port' => Settings::get('smtp_port', 587),
    'smtp_encryption' => Settings::get('smtp_encryption', 'tls'),
    'smtp_username' => Settings::get('smtp_username', ''),
    'smtp_password' => Settings::get('smtp_password', ''),
];

$client_ids = [];
$quote_ids = [];
$exit_code = 0;

try {
    Settings::set('email_service', 'smtp');
    Settings::set('smtp_host', '');
    Settings::set('smtp_port', '587');
    Settings::set('smtp_encryption', 'tls');
    Settings::set('smtp_username', '');
    Settings::set('smtp_password', '');

    $insert_client = $conn->prepare("
        INSERT INTO clients (name, email, phone, notes)
        VALUES (?, ?, ?, ?)
    ");
    $insert_quote = $conn->prepare("
        INSERT INTO quotes (
            quote_number, client_id, title, description, amount, expiration_date,
            status, created_at, updated_at, notes
        ) VALUES (?, ?, ?, ?, ?, ?, 'sent', ?, ?, ?)
    ");

    $active_email = "active.{$unique_suffix}@example.com";
    $expired_email = "expired.{$unique_suffix}@example.com";

    foreach ([
        ['name' => 'Active Quote Client', 'email' => $active_email],
        ['name' => 'Expired Quote Client', 'email' => $expired_email],
    ] as $client) {
        $insert_client->execute([
            $client['name'] . ' ' . $unique_suffix,
            $client['email'],
            '555-0000',
            'Quote reminder regression test',
        ]);
        $client_ids[] = (int) $conn->lastInsertId();
    }

    $created_at = $now->modify('-4 days')->format('Y-m-d H:i:s');
    $active_expiration = $now->modify('+2 days')->format('Y-m-d');
    $expired_expiration = $now->modify('-1 day')->format('Y-m-d');

    $insert_quote->execute([
        'QR-ACTIVE-' . $unique_suffix,
        $client_ids[0],
        'Active Quote ' . $unique_suffix,
        'Should receive reminder attempt',
        125.00,
        $active_expiration,
        $created_at,
        $created_at,
        'Active reminder candidate',
    ]);
    $quote_ids['active'] = (int) $conn->lastInsertId();

    $insert_quote->execute([
        'QR-EXPIRED-' . $unique_suffix,
        $client_ids[1],
        'Expired Quote ' . $unique_suffix,
        'Should be skipped because expired',
        95.00,
        $expired_expiration,
        $created_at,
        $created_at,
        'Expired reminder candidate',
    ]);
    $quote_ids['expired'] = (int) $conn->lastInsertId();

    $task = new QuoteReminderTask($conn);
    $result = $task->execute();

    assertQuoteReminderTest(($result['success'] ?? false) === true, 'Quote reminder task did not report success.');
    assertQuoteReminderTest(($result['items_processed'] ?? -1) === 0, 'Expected zero successful sends when SMTP host is blank.');

    $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
    $error_text = implode("\n", array_map('strval', $errors));

    assertQuoteReminderTest(
        str_contains($error_text, $active_email),
        'Expected active quote reminder attempt to appear in task errors.'
    );
    assertQuoteReminderTest(
        !str_contains($error_text, $expired_email),
        'Expired quote should not be selected for reminder processing.'
    );

    $quote_lookup = $conn->prepare("SELECT last_reminder_sent FROM quotes WHERE id = ?");
    $quote_lookup->execute([$quote_ids['active']]);
    $active_row = $quote_lookup->fetch(PDO::FETCH_ASSOC);
    assertQuoteReminderTest(is_array($active_row), 'Failed to load active quote row.');
    assertQuoteReminderTest(($active_row['last_reminder_sent'] ?? null) === null, 'Failed reminder attempt should not update last_reminder_sent.');

    $quote_lookup->execute([$quote_ids['expired']]);
    $expired_row = $quote_lookup->fetch(PDO::FETCH_ASSOC);
    assertQuoteReminderTest(is_array($expired_row), 'Failed to load expired quote row.');
    assertQuoteReminderTest(($expired_row['last_reminder_sent'] ?? null) === null, 'Expired quote should remain untouched.');

    echo "Quote reminder regression test passed.\n";
} catch (Throwable $e) {
    $exit_code = 1;
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
} finally {
    foreach ($original_settings as $key => $value) {
        Settings::set($key, $value);
    }

    if ($quote_ids !== []) {
        $delete_quote = $conn->prepare("DELETE FROM quotes WHERE id = ?");
        foreach ($quote_ids as $quote_id) {
            $delete_quote->execute([$quote_id]);
        }
    }

    if ($client_ids !== []) {
        $delete_client = $conn->prepare("DELETE FROM clients WHERE id = ?");
        foreach ($client_ids as $client_id) {
            $delete_client->execute([$client_id]);
        }
    }

    if (!$log_previously_existed && file_exists($log_path)) {
        unlink($log_path);
    } elseif ($log_previously_existed && is_string($original_log_contents)) {
        file_put_contents($log_path, $original_log_contents, LOCK_EX);
    }
}

exit($exit_code);
