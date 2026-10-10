#!/usr/bin/env php
<?php
if (PHP_SAPI !== 'cli') { exit(1); }
$mode = $argv[1] ?? '';
if ($mode === 'worker') {
    require __DIR__ . '/fixtures/invoice_payment_worker.inc';
    exit;
}
$mysql = getenv('BDTA_INVOICE_TEST_MYSQL') === '1';
$temporary_file = '';
if ($mysql) {
    $schema = getenv('DB_NAME');
    if (getenv('DB_HOST') !== '127.0.0.1' || !is_string($schema) || preg_match('/^bdta_invoice_test_concurrency_[a-z0-9_]+$/', $schema) !== 1) {
        throw new RuntimeException('Concurrency tests require a dedicated disposable loopback schema.');
    }
    $invoice_fixture_dsn = 'mysql:host=127.0.0.1;port=' . scalar_string_for_fixture(getenv('DB_PORT')) . ';dbname=' . $schema;
} else {
    $temporary_file = tempnam(sys_get_temp_dir(), 'bdta-invoice-');
    if ($temporary_file === false) { throw new RuntimeException('Unable to create isolated concurrency fixture.'); }
    $invoice_fixture_dsn = 'sqlite:' . $temporary_file;
}
function scalar_string_for_fixture(string|false $value): string { return $value === false ? '' : $value; }
define('BDTA_INVOICE_FIXTURE_ONLY', true);
$case = 'concurrency';
require __DIR__ . '/fixtures/invoice_settlement_request.inc';
/** @var SafePDO $conn */
require_once dirname(__DIR__) . '/backend/includes/invoice_payment.php';
$conn->exec($mysql ? 'SET innodb_lock_wait_timeout = 10' : 'PRAGMA busy_timeout = 10000');

/** @return array{resource, array<int, resource>} */
function startInvoicePaymentWorker(string $dsn, string $operation): array
{
    $command = [PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'display_errors=stderr',
        '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,exec,shell_exec,system,passthru,popen,proc_open,imap_open'];
    if (str_starts_with($dsn, 'mysql:')) {
        $command[] = '-d';
        $command[] = 'extension=' . ini_get('extension_dir') . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php_pdo_mysql.dll' : 'pdo_mysql.so');
    }
    $command = array_merge($command, [__FILE__, 'worker', $dsn, $operation]);
    // Fixed executable/script and operations; pipe synchronizes real independent transactions.
    // nosemgrep: php.lang.security.exec-use.exec-use
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Unable to start payment worker.'); }
    return [$process, $pipes];
}
try {
    foreach ([['same', 'same'], ['first', 'second'], ['manual', 'online'], ['manual', 'manual']] as [$first, $second]) {
        $expected_received = $first === 'same' ? 10000 : ($first === 'first' ? 20000 : ($second === 'online' ? 10000 : 0));
        foreach (['invoice_package_fulfillments', 'package_credit_transactions', 'client_package_credits', 'client_packages', 'invoice_checkout_receipts', 'invoice_payments', 'notifications'] as $table) { $conn->exec('DELETE FROM ' . $table); }
        $conn->exec("UPDATE invoices SET status = 'sent', stripe_payment_intent_id = NULL");
        $a = startInvoicePaymentWorker($invoice_fixture_dsn, $first);
        $b = startInvoicePaymentWorker($invoice_fixture_dsn, $second);
        foreach ([$a, $b] as [$process, $pipes]) {
            if (trim(scalar_string(fgets($pipes[1]))) !== 'ready') { throw new RuntimeException('Payment worker did not reach the concurrency barrier.'); }
        }
        foreach ([$a, $b] as [$process, $pipes]) { fwrite($pipes[0], "go\n"); fclose($pipes[0]); }
        foreach ([$a, $b] as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0) { throw new RuntimeException('Concurrent worker failed: ' . $output); }
        }
        $total = safe_float($conn->query('SELECT SUM(amount) FROM invoice_payments')->fetchColumn());
        $packages = safe_int($conn->query('SELECT COUNT(*) FROM client_packages')->fetchColumn());
        $credits = safe_int($conn->query('SELECT SUM(total_credits) FROM client_package_credits')->fetchColumn());
        $expected_total = $second === 'manual' ? 60.0 : 100.0;
        if ($total !== $expected_total || $packages !== ($total === 100.0 ? 1 : 0) || $credits !== ($total === 100.0 ? 5 : 0)) {
            throw new RuntimeException('Concurrent payment exceeded balance or duplicated/lost fulfillment.');
        }
        $received = safe_int($conn->query('SELECT COALESCE(SUM(received_cents), 0) FROM invoice_checkout_receipts')->fetchColumn());
        $applied = safe_int($conn->query('SELECT COALESCE(SUM(applied_cents), 0) FROM invoice_checkout_receipts')->fetchColumn());
        $excess = safe_int($conn->query('SELECT COALESCE(SUM(excess_cents), 0) FROM invoice_checkout_receipts')->fetchColumn());
        if ($received !== $expected_received || $received !== $applied + $excess) { throw new RuntimeException('Concurrent receipts must conserve all received funds exactly once.'); }
        echo 'PASS concurrent ' . $first . '/' . $second . ' (' . ($mysql ? 'MySQL' : 'SQLite') . ")\n";
    }
} finally {
    session_destroy();
    if ($temporary_file !== '') {
        (new ReflectionProperty(Database::class, 'sharedConnection'))->setValue(null, null);
        (new ReflectionProperty(Settings::class, 'db'))->setValue(null, null);
        unset($conn);
        unlink($temporary_file);
    }
}
