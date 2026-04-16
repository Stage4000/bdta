#!/usr/bin/env php
<?php

const CLIENT_FOLLOW_UP_THRESHOLD_SANDBOX_FILE_MODE = 0100444;

function assertClientFollowUpThreshold(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class ClientFollowUpThresholdSandboxStream
{
    /** @var array<string, string> */
    public static array $code_by_path = [];
    private string $code = '';
    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        if (!isset(self::$code_by_path[$path])) {
            return false;
        }

        $this->code = self::$code_by_path[$path];
        $this->position = 0;
        return true;
    }

    public function stream_read(int $count): string
    {
        $result = substr($this->code, $this->position, $count);
        $this->position += strlen($result);
        return $result;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->code);
    }

    /**
     * @return array<int|string, int>
     */
    public function stream_stat(): array
    {
        return self::buildStat($this->code);
    }

    /**
     * @return array<int|string, int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        if (!isset(self::$code_by_path[$path])) {
            return false;
        }

        return self::buildStat(self::$code_by_path[$path]);
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    /**
     * @return array<int|string, int>
     */
    private static function buildStat(string $code): array
    {
        $size = strlen($code);
        $time = time();

        return [
            0,
            0,
            CLIENT_FOLLOW_UP_THRESHOLD_SANDBOX_FILE_MODE,
            0,
            0,
            0,
            0,
            $size,
            $time,
            $time,
            $time,
            -1,
            -1,
            'dev' => 0,
            'ino' => 0,
            'mode' => CLIENT_FOLLOW_UP_THRESHOLD_SANDBOX_FILE_MODE,
            'nlink' => 0,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => $size,
            'atime' => $time,
            'mtime' => $time,
            'ctime' => $time,
            'blksize' => -1,
            'blocks' => -1,
        ];
    }
}

$clients_view = file_get_contents(dirname(__DIR__) . '/client/clients_view.php');
if (!is_string($clients_view)) {
    throw new RuntimeException('Expected to read the client view page.');
}

$helper_start = strpos($clients_view, 'function bdta_client_view_appointment_is_past');
$helper_end = strpos($clients_view, '$db = new Database();');
if ($helper_start === false || $helper_end === false || $helper_end <= $helper_start) {
    throw new RuntimeException('Expected to locate the client follow-up appointment threshold helper.');
}

$helper = substr($clients_view, $helper_start, $helper_end - $helper_start);
if ($helper === '') {
    throw new RuntimeException('Expected to extract the client follow-up appointment threshold helper.');
}

$sandbox_code = <<<PHP
<?php
namespace BdtaClientFollowUpThresholdSandbox;

use DateTimeImmutable;
use DateTimeZone;

function array_string_value(array \$row, string|int \$key, string \$default = ''): string
{
    \$value = \$row[\$key] ?? \$default;
    return is_string(\$value) ? \$value : \$default;
}

function bdta_get_display_timezone(): DateTimeZone
{
    return new DateTimeZone('UTC');
}

{$helper}

return static function (array \$appointment, string \$reference_time): bool {
    return bdta_client_view_appointment_is_past(
        \$appointment,
        new DateTimeImmutable(\$reference_time, bdta_get_display_timezone())
    );
};
PHP;

$sandbox_scheme = '';
do {
    $sandbox_scheme = 'bdta-client-follow-up-threshold-' . getmypid() . '-' . bin2hex(random_bytes(4));
} while (in_array($sandbox_scheme, stream_get_wrappers(), true));

if (!stream_wrapper_register($sandbox_scheme, ClientFollowUpThresholdSandboxStream::class)) {
    throw new RuntimeException('Expected to register the client follow-up threshold sandbox stream.');
}

$sandbox_path = $sandbox_scheme . '://threshold-helper';
ClientFollowUpThresholdSandboxStream::$code_by_path[$sandbox_path] = $sandbox_code;

try {
    $is_past = require $sandbox_path;
} finally {
    unset(ClientFollowUpThresholdSandboxStream::$code_by_path[$sandbox_path]);
    if (in_array($sandbox_scheme, stream_get_wrappers(), true)) {
        stream_wrapper_unregister($sandbox_scheme);
    }
}

if (!$is_past instanceof Closure) {
    throw new RuntimeException('Expected the client follow-up threshold sandbox to return a closure.');
}

assertClientFollowUpThreshold(
    $is_past(['appointment_date' => '2026-04-15', 'appointment_time' => '10:00:00'], '2026-04-16 09:00:00') === true,
    'Appointments from earlier dates should remain in past appointments.'
);
assertClientFollowUpThreshold(
    $is_past(['appointment_date' => '2026-04-16', 'appointment_time' => '10:00:00'], '2026-04-16 10:30:00') === false,
    'Appointments should stay upcoming during the first hour after the start time.'
);
assertClientFollowUpThreshold(
    $is_past(['appointment_date' => '2026-04-16', 'appointment_time' => '10:00:00'], '2026-04-16 11:00:00') === true,
    'Appointments should move to past appointments once one hour has elapsed after the start time.'
);
assertClientFollowUpThreshold(
    $is_past(['appointment_date' => '2026-04-16', 'appointment_time' => '10:00'], '2026-04-16 11:00:00') === true,
    'Appointments stored without seconds should still honor the one-hour follow-up threshold.'
);
assertClientFollowUpThreshold(
    $is_past(['appointment_date' => '2026-04-16', 'appointment_time' => '14:00:00'], '2026-04-16 11:00:00') === false,
    'Future same-day appointments should remain upcoming.'
);
assertClientFollowUpThreshold(
    $is_past(['appointment_date' => '2026-04-16', 'appointment_time' => ''], '2026-04-16 11:00:00') === false,
    'Appointments with an empty time should fail closed and remain upcoming.'
);
assertClientFollowUpThreshold(
    $is_past(['appointment_date' => '2026-04-16', 'appointment_time' => '25:00:00xyz'], '2026-04-16 11:00:00') === false,
    'Appointments with a malformed time should fail closed and remain upcoming.'
);

echo "Client follow-up appointment threshold checks passed.\n";
