#!/usr/bin/env php
<?php
/**
 * Focused test for timezone-aware timestamp helpers.
 */

require_once __DIR__ . '/backend/includes/database.php';

$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'timezone'");
$stmt->execute();
$original_timezone = scalar_string($stmt->fetchColumn() ?: 'America/New_York');

$conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'timezone'")->execute(['America/New_York']);

require_once __DIR__ . '/backend/includes/config.php';
require_once __DIR__ . '/backend/cron/cron.php';

echo "=== Time Standardization Test ===\n\n";

/**
 * @throws Exception
 */
function assertSameString(string $label, string $expected, string $actual): void {
    if ($expected !== $actual) {
        throw new Exception($label . " expected '{$expected}' but got '{$actual}'");
    }
    echo "✓ {$label}: {$actual}\n";
}

/**
 * @throws Exception
 */
function assertCronLocalTime(string $label, string $utcDatetime, int $expectedHour, int $expectedMinute): void {
    $local = (new DateTimeImmutable($utcDatetime, bdta_get_utc_timezone()))->setTimezone(bdta_get_display_timezone());
    $actualHour = (int) $local->format('G');
    $actualMinute = (int) $local->format('i');

    if ($actualHour !== $expectedHour || $actualMinute !== $expectedMinute) {
        throw new Exception($label . " expected local time {$expectedHour}:" . str_pad((string) $expectedMinute, 2, '0', STR_PAD_LEFT) . " but got " . $local->format('Y-m-d H:i:s'));
    }

    echo "✓ {$label}: " . $local->format('Y-m-d H:i:s') . "\n";
}

try {
    assertSameString('Configured timezone', 'America/New_York', getSystemTimezone());
    assertSameString('UTC winter datetime converts to EST', 'Jan 15, 2026 11:15 PM', formatDateTime('2026-01-16 04:15:00'));
    assertSameString('UTC summer datetime converts to EDT', 'Jul 4, 2026 12:00 PM', formatDateTime('2026-07-04 16:00:00'));
    assertSameString('Date-only values do not shift days', 'March 5, 2026', formatDate('2026-03-05'));
    assertSameString('Datetime date portion uses configured timezone', 'March 4, 2026', formatDate('2026-03-05 02:30:00'));
    assertSameString('Local winter datetime converts to UTC for storage', '2026-01-16 04:15:00', localDateTimeToUtcString('2026-01-15T23:15'));
    assertSameString('Local summer datetime converts to UTC for storage', '2026-07-04 16:00:00', localDateTimeToUtcString('2026-07-04T12:00'));
    assertSameString('Invalid local datetime returns empty string', '', localDateTimeToUtcString('not-a-date'));

    $cron_reflection = new ReflectionClass('CronRunner');
    $cron_instance = $cron_reflection->newInstanceWithoutConstructor();
    $parse_cron_expression = $cron_reflection->getMethod('parseCronExpression');
    $parse_cron_expression->setAccessible(true);
    $calculate_next_run = $cron_reflection->getMethod('calculateNextRun');
    $calculate_next_run->setAccessible(true);

    $custom_daily_run = $parse_cron_expression->invoke($cron_instance, '0 9 * * *');
    if (!is_string($custom_daily_run) || $custom_daily_run === '') {
        throw new Exception('Custom cron expression did not return a next run');
    }
    assertCronLocalTime('Custom cron daily expression keeps local 9AM', $custom_daily_run, 9, 0);

    $daily_task_run = $calculate_next_run->invoke($cron_instance, [
        'schedule_type' => 'daily',
        'schedule_value' => '09:00',
        'task_name' => 'Timezone test task',
    ]);
    if (!is_string($daily_task_run) || $daily_task_run === '') {
        throw new Exception('Daily task schedule did not return a next run');
    }
    assertCronLocalTime('Daily task schedule keeps local 9AM', $daily_task_run, 9, 0);

    echo "\n=== All Time Standardization Tests Passed! ===\n";
} catch (Exception $e) {
    echo "\n✗ TIME STANDARDIZATION TEST FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    $conn->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'timezone'")->execute([$original_timezone]);
}
