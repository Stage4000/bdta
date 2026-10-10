#!/usr/bin/env php
<?php
/** Execute the real public controller against synthetic, in-memory fixtures. */
$cases = [
    'cross-form', 'forged-template', 'invitation-downgrade', 'direct-forged-submission',
    'direct-forged-template', 'valid-invited', 'valid-direct', 'portal-other-invite',
    'admin-id', 'owner-id', 'anonymous-id', 'repeated', 'stale-pending',
    'changed-client', 'changed-template', 'changed-token', 'changed-booking', 'changed-pet',
    'changed-fields', 'rollback', 'rollback-pet',
];
$case = $argv[1] ?? '';
if ($case === '') {
    $failed = false;
    foreach ($cases as $test_case) {
        // No shell is invoked: executable/file are fixed and cases come from the literal list above.
        // nosemgrep: php.lang.security.exec-use.exec-use
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'allow_url_fopen=0',
            '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_create,socket_connect,exec,shell_exec,system,passthru,popen,proc_open,imap_open',
            __FILE__, $test_case], [
            0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR,
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start isolated controller test.');
        }
        fclose($pipes[0]);
        $failed = proc_close($process) !== 0 || $failed;
    }
    exit($failed ? 1 : 0);
}
if (!in_array($case, $cases, true)) {
    throw new RuntimeException('Unknown controller fixture.');
}
require_once __DIR__ . '/support/public_form_submission_fixture.php';
