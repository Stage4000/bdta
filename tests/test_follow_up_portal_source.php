#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/form_types.php';

const FOLLOW_UP_PORTAL_SOURCE_SANDBOX_FILE_MODE = 0100444;

function assertFollowUpPortalSource(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class FollowUpPortalSourceSandboxStream
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
        if (!is_string($result)) {
            return '';
        }

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
            FOLLOW_UP_PORTAL_SOURCE_SANDBOX_FILE_MODE,
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
            'mode' => FOLLOW_UP_PORTAL_SOURCE_SANDBOX_FILE_MODE,
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

$agreements_page = file_get_contents(dirname(__DIR__) . '/portal/agreements.php');
if (!is_string($agreements_page)) {
    throw new RuntimeException('Expected to read the portal agreements page.');
}

$follow_up_notes = file_get_contents(dirname(__DIR__) . '/backend/includes/follow_up_notes.php');
if (!is_string($follow_up_notes)) {
    throw new RuntimeException('Expected to read the follow-up notes helper.');
}

$visibility_helper_start = strpos($follow_up_notes, 'function bdta_form_submission_is_client_portal_visible');
$visibility_helper_end = strpos($follow_up_notes, 'function bdta_get_client_portal_form_submission_url');
if ($visibility_helper_start === false || $visibility_helper_end === false || $visibility_helper_end <= $visibility_helper_start) {
    throw new RuntimeException('Expected to locate the portal visibility helper in follow_up_notes.php.');
}

$visibility_helper = substr($follow_up_notes, $visibility_helper_start, $visibility_helper_end - $visibility_helper_start);
if ($visibility_helper === '') {
    throw new RuntimeException('Expected to extract the portal visibility helper.');
}

$sandbox_code = <<<PHP
<?php
namespace BdtaFollowUpPortalSourceSandbox;

function array_string_value(array \$array, string \$key, string \$default = ''): string
{
    \$value = \$array[\$key] ?? \$default;
    return is_string(\$value) ? \$value : \$default;
}

function array_int_value(array \$array, string \$key, int \$default = 0): int
{
    \$value = \$array[\$key] ?? \$default;
    return is_numeric(\$value) ? (int) \$value : \$default;
}

function bdta_form_submission_requires_client_review(string \$form_type): bool
{
    return \$form_type === 'follow_up_note';
}

function bdta_form_type_forced_internal(string \$form_type): int
{
    return \$form_type === 'pet_form' ? 1 : 0;
}

{$visibility_helper}

return static function (array \$submission): bool {
    return bdta_form_submission_is_client_portal_visible(\$submission);
};
PHP;

$sandbox_scheme = '';
do {
    $sandbox_scheme = 'bdta-follow-up-portal-source-' . getmypid() . '-' . bin2hex(random_bytes(4));
} while (in_array($sandbox_scheme, stream_get_wrappers(), true));

if (!stream_wrapper_register($sandbox_scheme, FollowUpPortalSourceSandboxStream::class)) {
    throw new RuntimeException('Expected to register the portal visibility sandbox stream with scheme: ' . $sandbox_scheme);
}
$sandbox_path = $sandbox_scheme . '://visibility-helper';
FollowUpPortalSourceSandboxStream::$code_by_path[$sandbox_path] = $sandbox_code;

try {
    $portal_visibility = require $sandbox_path;
} finally {
    unset(FollowUpPortalSourceSandboxStream::$code_by_path[$sandbox_path]);
    if (in_array($sandbox_scheme, stream_get_wrappers(), true)) {
        stream_wrapper_unregister($sandbox_scheme);
    }
}

if (!$portal_visibility instanceof Closure) {
    throw new RuntimeException('Expected the portal visibility sandbox to return a closure.');
}

assertFollowUpPortalSource(
    bdta_get_form_template_access_state('follow_up_note', 0)['effective_internal'] === true,
    'Follow-up note templates should remain internal for admin/staff completion.'
);
assertFollowUpPortalSource(
    str_contains($agreements_page, 'bdta_form_submission_is_client_portal_visible($submission)'),
    'Portal agreements page should explicitly allow client-visible follow-up review submissions.'
);
assertFollowUpPortalSource(
    $portal_visibility(['form_type' => 'client_form']) === false,
    'Portal visibility helper should fail closed when template_is_internal is not provided.'
);
assertFollowUpPortalSource(
    $portal_visibility(['form_type' => 'client_form', 'template_is_internal' => 0]) === true,
    'Portal visibility helper should allow client-facing submissions when template_is_internal is provided as 0.'
);
assertFollowUpPortalSource(
    $portal_visibility(['form_type' => 'follow_up_note']) === true,
    'Portal visibility helper should still allow follow-up review submissions.'
);
assertFollowUpPortalSource(
    str_contains($agreements_page, 'bdta_get_client_portal_form_submission_url($fs)'),
    'Portal agreements page should route follow-up submissions to the portal review page.'
);
assertFollowUpPortalSource(
    str_contains($agreements_page, "\$client_review_submission ? 'Review' : 'View'"),
    'Portal agreements page should distinguish follow-up review actions from normal form viewing.'
);
assertFollowUpPortalSource(
    str_contains($follow_up_notes, 'function bdta_get_follow_up_note_email_details'),
    'Follow-up note helper should build email detail sections.'
);
assertFollowUpPortalSource(
    str_contains($follow_up_notes, 'Follow-up details'),
    'Follow-up notification emails should include the follow-up details heading.'
);

echo "Follow-up portal source checks passed.\n";
