#!/usr/bin/env php
<?php
/** Real invoice controllers, synthetic fixtures and strictly local provider responses. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$cases = ['unpaid', 'wrong-invoice', 'wrong-client', 'wrong-amount', 'success', 'stale-cash', 'partial', 'already-paid', 'rollback-credits', 'manual-final', 'manual-partial', 'installment-final'];
$case = $argv[1] ?? '';
if ($case === '') {
    $failed = false;
    foreach ($cases as $scenario) {
        // Fixed executable/script and literal case names; no shell or network transport.
        // nosemgrep: php.lang.security.exec-use.exec-use
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'allow_url_fopen=0',
            '-d', 'disable_functions=curl_init,curl_setopt,curl_setopt_array,curl_exec,curl_getinfo,curl_close,curl_error,curl_multi_exec,mail,fsockopen,pfsockopen,stream_socket_client,socket_connect,exec,shell_exec,system,passthru,popen,proc_open,imap_open',
            __FILE__, $scenario], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start invoice fixture.');
        }
        fclose($pipes[0]);
        $failed = proc_close($process) !== 0 || $failed;
    }
    exit($failed ? 1 : 0);
}
if (!in_array($case, $cases, true)) {
    throw new RuntimeException('Unknown invoice fixture.');
}
require __DIR__ . '/fixtures/invoice_settlement_request.inc';
