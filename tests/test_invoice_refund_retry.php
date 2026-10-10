#!/usr/bin/env php
<?php
// Use a disposable MySQL schema. Every provider request goes to the local fake in a
// separate process with a private ini; cURL is absent and network streams disabled.
if (getenv('BDTA_REFUND_TEST_DISPOSABLE') !== '1' || getenv('DB_HOST') !== '127.0.0.1'
    || preg_match('/^bdta_(?:p1|refund_test)_[a-z0-9_]+$/', scalar_string_test(getenv('DB_NAME'))) !== 1) {
    fwrite(STDERR, "Refund tests require explicit BDTA_REFUND_TEST_DISPOSABLE=1 and a disposable localhost bdta_p1_* or bdta_refund_test_* schema.\n");
    exit(1);
}
function scalar_string_test(string|false $value): string { return $value !== false ? $value : ''; }
require_once dirname(__DIR__) . '/backend/includes/config.php';
require_once dirname(__DIR__) . '/backend/includes/invoice_status.php';
$conn = (new Database())->getConnection();
$directory = sys_get_temp_dir() . '/bdta-refund-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
mkdir($directory . '/empty_ini', 0700);
copy(__DIR__ . '/fixtures/invoice_refund_request.inc', $directory . '/request.inc');
copy(__DIR__ . '/fixtures/invoice_refund_fake.inc', $directory . '/invoice_refund_fake.inc');
$extensions = PHP_OS_FAMILY === 'Windows' ? ['php_pdo_mysql.dll', 'php_mbstring.dll', 'php_openssl.dll', 'php_fileinfo.dll'] : ['pdo_mysql', 'mbstring', 'fileinfo'];
// Some Linux distributions ship PDO itself as a shared module. The isolated
// worker intentionally scans no host ini files, so load its dependency first.
if (PHP_OS_FAMILY !== 'Windows' && is_file(ini_get('extension_dir') . '/pdo.' . PHP_SHLIB_SUFFIX)) array_unshift($extensions, 'pdo');
$private_ini = 'extension_dir="' . ini_get('extension_dir') . '"' . PHP_EOL;
foreach ($extensions as $extension) $private_ini .= 'extension=' . $extension . PHP_EOL;
$private_ini .= "allow_url_fopen=0\ndisable_functions=mail,fsockopen,pfsockopen,stream_socket_client,socket_create,socket_connect,exec,shell_exec,system,passthru,popen,proc_open\n";
file_put_contents($directory . '/php.ini', $private_ini);
$store = $directory . '/provider.json';
putenv('BDTA_REFUND_FAKE_STORE=' . $store);
file_put_contents($store, '{"refunds":[],"calls":[]}');
$checks = 0;
$client_id = 0;
$original_settings = ['stripe_enabled' => Settings::get('stripe_enabled'), 'stripe_test_secret_key' => Settings::get('stripe_test_secret_key'), 'stripe_mode' => Settings::get('stripe_mode')];
/** @param mixed $actual @param mixed $expected */
function refundAssert(mixed $actual, mixed $expected, string $message): void {
    global $checks;
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . json_encode($actual));
    $checks++;
    echo "PASS $message\n";
}
/** @return array{refunds: array<string, array{id: string, status: string, amount: int, currency: string, payment_intent: string, metadata: array<string, string>}>, calls: list<array{post: bool, key: string, fields: array<string, mixed>}>, keys?: array<string, string>, params?: array<string, array<string, mixed>>, mode?: string, hold_file?: string} */
function refundProviderState(): array {
    global $store;
    /** @var array{refunds: array<string, array{id: string, status: string, amount: int, currency: string, payment_intent: string, metadata: array<string, string>}>, calls: list<array{post: bool, key: string, fields: array<string, mixed>}>, keys?: array<string, string>, params?: array<string, array<string, mixed>>, mode?: string, hold_file?: string} $state */
    $state = decode_json_assoc(scalar_string(file_get_contents($store)));
    return $state;
}
function refundFakeMode(string $mode): void {
    global $store;
    $state = refundProviderState(); $state['mode'] = $mode;
    file_put_contents($store, json_encode($state, JSON_THROW_ON_ERROR));
}
/**
 * @param array<string, string> $post
 * @return array{0: resource, 1: array<int, resource>}
 */
function refundStartRequest(int $id, array $post = [], string $role = 'main'): array {
    global $directory;
    // Literal command and private working directory: request data goes only to
    // stdin, never through command interpolation. Empty ini scan directory keeps
    // the provider transport absent even when the host normally enables cURL.
    $environment = getenv();
    $environment['PHP_INI_SCAN_DIR'] = $directory . '/empty_ini';
    $environment['BDTA_REFUND_TEST_ROOT'] = dirname(__DIR__);
    $process = proc_open('php -c php.ini request.inc', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, $environment);
    if (!is_resource($process)) throw new RuntimeException('Unable to start refund request');
    fwrite($pipes[0], json_encode(['id' => $id, 'post' => $post, 'admin_id' => 1, 'role' => $role], JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    return [$process, $pipes];
}
/** @param array{0: resource, 1: array<int, resource>} $request */
function refundFinishRequest(array $request): string {
    [$process, $pipes] = $request;
    $output = scalar_string(stream_get_contents($pipes[1]));
    $errors = scalar_string(stream_get_contents($pipes[2]));
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || $errors !== '') throw new RuntimeException('Request failed: ' . $errors . $output);
    return $output;
}
/** @param array<string, string> $post */
function refundRequest(int $id, array $post = [], string $role = 'main'): string { return refundFinishRequest(refundStartRequest($id, $post, $role)); }
/** @return array<string, string> */
function refundPost(float $amount, ?string $key = null): array {
    return ['refund_invoice' => '1', 'csrf_token' => 'synthetic-refund-csrf', 'refund_amount' => (string)$amount, 'refund_date' => '2026-10-10', 'refund_note' => 'Synthetic refund', 'refund_operation_key' => $key ?? bin2hex(random_bytes(32))];
}
function refundInvoice(): int {
    global $conn, $client_id;
    $token = bin2hex(random_bytes(8));
    $conn->prepare("INSERT INTO invoices(invoice_number,client_id,issue_date,due_date,subtotal,total_amount,status,payment_method,stripe_payment_intent_id) VALUES(?,?,CURRENT_DATE,CURRENT_DATE,100,100,'paid','stripe',?)")->execute(['REFUND-' . $token, $client_id, 'pi_fake_' . $token]);
    return safe_int($conn->lastInsertId());
}
function refundReleaseProviderHold(): void {
    global $directory;
    $previous_directory = getcwd();
    if ($previous_directory === false || !chdir($directory)) throw new RuntimeException('Unable to enter private fixture directory');
    try { if (file_exists('provider-hold')) unlink('provider-hold'); }
    finally { chdir($previous_directory); }
}
try {
    Settings::set('stripe_enabled', true); Settings::set('stripe_test_secret_key', 'sk_fake_local_only'); Settings::set('stripe_mode', 'test');
    $conn->prepare('INSERT INTO clients(name,email) VALUES(?,?)')->execute(['Synthetic refund test', 'refund-' . bin2hex(random_bytes(8)) . '@example.test']);
    $client_id = safe_int($conn->lastInsertId());
    $id = refundInvoice(); $post = refundPost(25);
    $conn->exec("CREATE TRIGGER p1_refund_fault BEFORE INSERT ON invoice_refunds FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic local persistence interruption'");
    $output = refundRequest($id, $post);
    refundAssert(count(refundProviderState()['refunds']), 1, 'provider accepts the first $25 refund before interrupted local insert');
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 0.0, 'interrupted ledger insert rolls back');
    $page = refundRequest($id);
    refundAssert(str_contains($page, 'name="refund_operation_key" value="' . $post['refund_operation_key'] . '"'), true, 'reload preserves unresolved operation identity');
    refundAssert(str_contains($page, 'value="25.00" readonly'), true, 'reload preserves the original partial amount');
    refundAssert(str_contains($page, '>Synthetic refund</textarea>'), true, 'reload preserves the original refund note');
    $conn->exec('DROP TRIGGER p1_refund_fault');
    refundRequest($id, $post);
    refundAssert(count(refundProviderState()['refunds']), 1, 'retry after interrupted insert does not duplicate the provider refund');
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 25.0, 'retry records the original provider refund once');
    refundRequest($id, $post);
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 25.0, 'completed partial-refund replay does not duplicate ledger');
    refundRequest($id, refundPost(25));
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 50.0, 'a separate equal-sized partial refund remains possible');
    $final = refundPost(50); refundRequest($id, $final); refundRequest($id, $final);
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 100.0, 'full-refund replay is safe');
    refundAssert(array_string_value(bdta_invoice_fetch_row($conn, $id), 'status'), 'refunded', 'full refund retains invoice lifecycle');

    $id = refundInvoice(); $post = refundPost(25); $before_loss = count(refundProviderState()['refunds']); refundFakeMode('drop_response');
    refundRequest($id, $post); refundRequest($id, $post);
    refundAssert(count(refundProviderState()['refunds']), $before_loss + 1, 'lost-response retry creates only one provider refund');
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 25.0, 'lost provider response recovers with the same operation');
    $before = count(refundProviderState()['refunds']);
    $changed = $post; $changed['refund_amount'] = '30'; refundRequest($id, $changed);
    refundAssert(count(refundProviderState()['refunds']), $before, 'changed retry parameters are rejected before provider call');
    $changed = $post; $changed['refund_date'] = '2026-10-11'; refundRequest($id, $changed);
    $changed = $post; $changed['refund_note'] = 'Changed'; refundRequest($id, $changed);
    refundRequest(refundInvoice(), $post);
    refundAssert(count(refundProviderState()['refunds']), $before, 'changed dates, notes and cross-invoice operation keys are rejected');

    $id = refundInvoice(); $post = refundPost(25);
    $hold = $directory . '/provider-hold'; file_put_contents($hold, 'hold');
    $state = refundProviderState(); $state['hold_file'] = $hold; file_put_contents($store, json_encode($state, JSON_THROW_ON_ERROR));
    $first = refundStartRequest($id, $post);
    $deadline = microtime(true) + 10;
    while (!file_exists($hold . '.entered') && microtime(true) < $deadline) { usleep(10000); clearstatcache(); }
    refundAssert(file_exists($hold . '.entered'), true, 'first worker holds the invoice lock after provider acceptance');
    $second = refundStartRequest($id, $post);
    $contending = false; $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $waiting = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.PROCESSLIST WHERE STATE = 'User lock' AND INFO LIKE '%GET_LOCK%'");
        if (safe_int($waiting->fetchColumn()) > 0) { $contending = true; break; }
        usleep(10000);
    }
    refundReleaseProviderHold();
    refundFinishRequest($first); refundFinishRequest($second);
    $state = refundProviderState(); unset($state['hold_file']); file_put_contents($store, json_encode($state, JSON_THROW_ON_ERROR));
    refundAssert($contending, true, 'second worker waits on the invoice lock during the provider persistence gap');
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 25.0, 'concurrent same-operation requests produce one local refund');
    refundAssert(count(refundProviderState()['refunds']), $before + 1, 'concurrent same-operation requests produce one provider refund');

    $id = refundInvoice(); $before = count(refundProviderState()['refunds']); refundFakeMode('delay');
    $first = refundStartRequest($id, refundPost(75)); $second = refundStartRequest($id, refundPost(75));
    refundFinishRequest($first); refundFinishRequest($second); refundFakeMode('');
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 75.0, 'concurrent separate requests validate remaining balance under the lock');
    refundAssert(count(refundProviderState()['refunds']), $before + 1, 'overlapping over-refund is rejected before provider submission');

    $id = refundInvoice(); $post = refundPost(25); $before = count(refundProviderState()['refunds']);
    $conn->exec("CREATE TRIGGER p1_identity_fault BEFORE UPDATE ON invoice_refund_operations FOR EACH ROW BEGIN IF NEW.stripe_refund_id IS NOT NULL AND OLD.stripe_refund_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic provider identity persistence interruption'; END IF; END");
    refundRequest($id, $post);
    refundAssert(count(refundProviderState()['refunds']), $before + 1, 'provider succeeds before interrupted identity persistence');
    $conn->exec('DROP TRIGGER p1_identity_fault'); refundRequest($id, $post);
    refundAssert(count(refundProviderState()['refunds']), $before + 1, 'identity-save interruption retries with the same provider key');
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 25.0, 'identity-save interruption recovers original refund');

    $id = refundInvoice(); $post = refundPost(25); $before = count(refundProviderState()['refunds']);
    $conn->exec("CREATE TRIGGER p1_completion_fault BEFORE UPDATE ON invoice_refund_operations FOR EACH ROW BEGIN IF NEW.completed_at IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic completion interruption'; END IF; END");
    refundRequest($id, $post);
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 0.0, 'completion interruption rolls back ledger and invoice status together');
    $conn->exec('DROP TRIGGER p1_completion_fault'); refundRequest($id, $post);
    refundAssert(count(refundProviderState()['refunds']), $before + 1, 'completion retry uses the saved provider identity');
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 25.0, 'completion retry records exactly one ledger entry');

    $id = refundInvoice(); $before = count(refundProviderState()['calls']);
    $conn->exec("CREATE TRIGGER p1_intent_fault BEFORE INSERT ON invoice_refund_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic intent persistence interruption'");
    refundRequest($id, refundPost(25));
    $conn->exec('DROP TRIGGER p1_intent_fault');
    refundAssert(count(refundProviderState()['calls']), $before, 'failed durable intent makes no provider call');

    $id = refundInvoice(); $post = refundPost(25); $before = count(refundProviderState()['calls']);
    $conn->exec("CREATE TRIGGER p1_attempt_fault BEFORE UPDATE ON invoice_refund_operations FOR EACH ROW BEGIN IF NEW.first_attempt_at IS NOT NULL AND OLD.first_attempt_at IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic attempt persistence interruption'; END IF; END");
    refundRequest($id, $post); $conn->exec('DROP TRIGGER p1_attempt_fault');
    refundAssert(count(refundProviderState()['calls']), $before, 'failed durable attempt timestamp makes no provider call');
    refundRequest($id, $post);
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 25.0, 'unattempted durable operation can safely resume');

    $id = refundInvoice(); $post = refundPost(25); refundFakeMode('drop_response'); refundRequest($id, $post);
    $before = count(refundProviderState()['refunds']);
    refundRequest($id, refundPost(10));
    refundAssert(count(refundProviderState()['refunds']), $before, 'an unresolved operation blocks a new operation on that invoice');
    $conn->prepare('UPDATE invoice_refund_operations SET first_attempt_at = ? WHERE operation_key = ?')->execute([time() - 90000, $post['refund_operation_key']]);
    // Expire the provider's idempotency cache; recovery must use metadata, never POST.
    $state = refundProviderState(); $state['keys'] = []; file_put_contents($store, json_encode($state, JSON_THROW_ON_ERROR));
    refundRequest($id, $post);
    refundAssert(count(refundProviderState()['refunds']), $before, 'expired idempotency key is reconciled without another refund POST');
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 25.0, 'metadata reconciliation repairs the old ambiguous operation');

    // Put the target after another refund for the same PaymentIntent, so recovery
    // must follow starting_after rather than treating the first page as complete.
    $post = refundPost(25); refundFakeMode('drop_response'); refundRequest($id, $post);
    $conn->prepare('UPDATE invoice_refund_operations SET first_attempt_at = ? WHERE operation_key = ?')->execute([time() - 90000, $post['refund_operation_key']]);
    $before = count(refundProviderState()['calls']); refundRequest($id, $post);
    $reads = array_values(array_filter(array_slice(refundProviderState()['calls'], $before), static fn($c): bool => !$c['post']));
    refundAssert(count($reads), 2, 'same-intent history is reconciled across multiple pages');
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 50.0, 'paginated exact match preserves distinct partial refund history');

    $id = refundInvoice(); $post = refundPost(25); refundFakeMode('drop_response'); refundRequest($id, $post);
    $conn->prepare('UPDATE invoice_refund_operations SET first_attempt_at = ? WHERE operation_key = ?')->execute([time() - 90000, $post['refund_operation_key']]);
    $before = count(refundProviderState()['calls']); refundFakeMode('list_failure'); refundRequest($id, $post); refundFakeMode('');
    $new_calls = array_slice(refundProviderState()['calls'], $before);
    refundAssert(count(array_filter($new_calls, static fn($c): bool => !empty($c['post']))), 0, 'failed reconciliation never replays an old refund');
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 0.0, 'failed reconciliation leaves ledger and balance unchanged');

    $state = refundProviderState();
    foreach ($state['refunds'] as &$refund) {
        if (($refund['metadata']['bdta_refund_operation'] ?? '') === $post['refund_operation_key']) $refund['metadata'] = [];
    }
    unset($refund); file_put_contents($store, json_encode($state, JSON_THROW_ON_ERROR));
    $before = count(refundProviderState()['refunds']); refundRequest($id, $post);
    refundAssert(count(refundProviderState()['refunds']), $before, 'missing old-operation match never creates a replacement refund');
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 0.0, 'missing old-operation match remains unresolved');

    $id = refundInvoice(); $post = refundPost(25); refundFakeMode('drop_response'); refundRequest($id, $post);
    $conn->prepare('UPDATE invoice_refund_operations SET first_attempt_at = ? WHERE operation_key = ?')->execute([time() - 90000, $post['refund_operation_key']]);
    $state = refundProviderState();
    foreach ($state['refunds'] as $refund) {
        if (($refund['metadata']['bdta_refund_operation'] ?? '') === $post['refund_operation_key']) {
            $refund['id'] = 're_fake_ambiguous'; $state['refunds'][$refund['id']] = $refund; break;
        }
    }
    file_put_contents($store, json_encode($state, JSON_THROW_ON_ERROR));
    $before = count(refundProviderState()['calls']); refundRequest($id, $post);
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 0.0, 'multiple old-operation matches require manual reconciliation');
    refundAssert(count(array_filter(array_slice(refundProviderState()['calls'], $before), static fn($c): bool => $c['post'])), 0, 'ambiguous reconciliation never sends a refund POST');

    $id = refundInvoice(); $before = count(refundProviderState()['refunds']); refundFakeMode('failed'); refundRequest($id, refundPost(25)); refundFakeMode('');
    refundAssert(bdta_invoice_get_refunded_total($conn, $id), 0.0, 'provider failed status is not recorded as refunded');
    refundAssert(count(refundProviderState()['refunds']), $before + 1, 'failed-status operation remains identified at provider');

    $id = refundInvoice(); $before = count(refundProviderState()['calls']);
    $post = refundPost(25); $post['csrf_token'] = 'invalid'; refundRequest($id, $post);
    refundRequest($id, refundPost(25), 'accountant');
    refundRequest($id, refundPost(101));
    refundAssert(count(refundProviderState()['calls']), $before, 'CSRF, accountant restrictions and over-refunds make no provider calls');
    echo "All $checks refund retry checks passed\n";
} finally {
    refundReleaseProviderHold();
    $conn->exec('DROP TRIGGER IF EXISTS p1_refund_fault');
    foreach (['p1_identity_fault', 'p1_completion_fault', 'p1_intent_fault', 'p1_attempt_fault'] as $trigger) $conn->exec('DROP TRIGGER IF EXISTS ' . $trigger);
    if ($client_id > 0) {
        $conn->prepare('DELETE FROM invoices WHERE client_id = ?')->execute([$client_id]);
        $conn->prepare('DELETE FROM clients WHERE id = ?')->execute([$client_id]);
    }
    foreach ($original_settings as $setting => $value) Settings::set($setting, $value);
    $previous_directory = getcwd();
    if ($previous_directory === false || !chdir($directory)) throw new RuntimeException('Unable to clean private fixture directory');
    try {
        unlink('provider.json'); unlink('php.ini'); unlink('request.inc'); unlink('invoice_refund_fake.inc');
        if (file_exists('provider-hold.entered')) unlink('provider-hold.entered');
        rmdir('empty_ini');
    } finally { chdir($previous_directory); }
    rmdir($directory);
}
