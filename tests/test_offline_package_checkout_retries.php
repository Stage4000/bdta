#!/usr/bin/env php
<?php
/**
 * Real-controller/finalizer regression using disposable loopback MariaDB/MySQL.
 * Opt in with BDTA_CHECKOUT_TEST_PORT and BDTA_CHECKOUT_TEST_USER. No HTTP/browser
 * or real integration is used; every worker disables external transports.
 */
$port = getenv('BDTA_CHECKOUT_TEST_PORT');
$user = getenv('BDTA_CHECKOUT_TEST_USER');
if ($port === false || $user === false) {
    echo "SKIP: set BDTA_CHECKOUT_TEST_PORT and BDTA_CHECKOUT_TEST_USER for a disposable loopback server.\n";
    exit(0);
}
if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535 || $user === '') {
    throw new RuntimeException('Invalid disposable fixture configuration.');
}
$password = getenv('BDTA_CHECKOUT_TEST_PASSWORD');
$password = $password === false ? '' : $password;
putenv('DB_TYPE=mysql');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=' . $port);
putenv('DB_USER=' . $user);
putenv('DB_PASSWORD=' . $password);

if (($argv[1] ?? '') === '--worker') {
    $schema = $argv[2] ?? '';
    if (preg_match('/^bdta_test_checkout_[a-f0-9]{12}$/', $schema) !== 1) {
        throw new RuntimeException('Worker requires its generated disposable schema.');
    }
    /** @var array{mode: string, email: string, token: string, csrf: bool, fault: string, gate: bool, package_id: int, form_id: int} $request */
    $request = json_decode($argv[3] ?? '', true, 512, JSON_THROW_ON_ERROR);
    putenv('DB_NAME=' . $schema);
    require_once dirname(__DIR__) . '/backend/includes/database.php';
    putenv('DB_PASSWORD=' . $password);

    class OfflineCheckoutFixturePDO extends SafePDO {
        public string $fault = '';
        public bool $gate = false;
        /** @param array<mixed> $options */
        public function prepare(string $query, array $options = []): SafePDOStatement {
            if (($this->fault === 'credit' && preg_match('/INSERT\s+INTO\s+client_package_credits/i', $query) === 1)
                || ($this->fault === 'invoice' && preg_match('/INSERT\s+INTO\s+invoices\s*\(/i', $query) === 1)) {
                $this->fault = '';
                throw new RuntimeException('Synthetic persistence interruption.');
            }
            return parent::prepare($query, $options);
        }
        public function beginTransaction(): bool {
            if ($this->gate) {
                $this->gate = false;
                fwrite(STDOUT, "READY\n");
                if (trim((string) fgets(STDIN)) !== 'GO') {
                    throw new RuntimeException('Synthetic concurrency gate failed.');
                }
            }
            return parent::beginTransaction();
        }
        public function commit(): bool {
            if ($this->fault === 'before_commit') {
                $this->fault = '';
                throw new RuntimeException('Synthetic interruption before commit.');
            }
            $committed = parent::commit();
            if ($this->fault === 'after_commit') {
                $this->fault = '';
                throw new RuntimeException('Synthetic lost commit acknowledgement.');
            }
            return $committed;
        }
    }
    // Disabled built-ins are replaced only by local fail-closed transport stubs.
    function mail(string $to, string $subject, string $message, mixed $headers = '', string $parameters = ''): bool {
        return false;
    }
    $conn = new OfflineCheckoutFixturePDO("mysql:host=127.0.0.1;port={$port};dbname={$schema};charset=utf8mb4", $user, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);
    $conn->fault = $request['fault'];
    $conn->gate = $request['gate'];
    (new ReflectionProperty(Database::class, 'sharedConnection'))->setValue(null, $conn);
    $sessionDirectory = getenv('BDTA_CHECKOUT_TEST_SESSION_DIRECTORY');
    if ($sessionDirectory === false || !is_dir($sessionDirectory)) {
        throw new RuntimeException('Missing disposable session directory.');
    }
    ini_set('session.save_path', $sessionDirectory);
    session_start();
    $_SESSION = ['csrf_token' => str_repeat('a', 64)];
    $_SERVER['SCRIPT_NAME'] = '/client/package_detail.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REQUEST_METHOD'] = $request['mode'] === 'post' ? 'POST' : 'GET';
    $_GET = ['token' => str_repeat('a', 32)];
    if ($request['mode'] === 'confirmation') {
        $_GET['purchase'] = 'success';
        $_GET['checkout_attempt'] = $request['token'];
    }
    $_POST = ['action' => 'purchase', 'csrf_token' => str_repeat($request['csrf'] ? 'a' : 'b', 64),
        'buyer_name' => 'Synthetic Checkout Buyer', 'buyer_email' => $request['email'],
        'buyer_phone' => '555-0100', 'checkout_attempt_token' => $request['token']];
    $result = null;
    $failure = null;
    ob_start();
    register_shutdown_function(static function () use (&$result, &$failure): void {
        $html = (string) ob_get_clean();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        preg_match('/name="checkout_attempt_token"[^>]*value="([^"]+)"/', $html, $tokenMatch);
        echo json_encode(['result' => $result, 'failure' => $failure,
            'token' => $tokenMatch[1] ?? '', 'confirmed' => strpos($html, 'Purchase Confirmed!') !== false,
            'error' => strpos($html, 'alert-danger') !== false], JSON_THROW_ON_ERROR);
    });
    if (in_array($request['mode'], ['get', 'post', 'confirmation'], true)) {
        require dirname(__DIR__) . '/client/package_detail.php';
    } elseif ($request['mode'] === 'helper') {
        require_once dirname(__DIR__) . '/backend/includes/package_checkout.php';
        $stmt = $conn->prepare('SELECT * FROM packages WHERE id = ?');
        $stmt->execute([$request['package_id']]);
        $package = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
        $stmt = $conn->prepare('SELECT * FROM package_items WHERE package_id = ?');
        $stmt->execute([$request['package_id']]);
        $items = assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
        try {
            $result = bdta_finalize_package_purchase($conn, $package, $items, 'Synthetic Checkout Buyer',
                $request['email'], '555-0100', '', $request['form_id'] > 0 ? ['id' => $request['form_id']] : null,
                $request['form_id'] > 0 ? ['Synthetic answer'] : [], null, 'offline', null, null,
                $request['token'] !== '' ? $request['token'] : null);
        } catch (Throwable $e) {
            $failure = $e->getMessage();
        }
    } else {
        throw new RuntimeException('Unsupported synthetic mode.');
    }
    exit(0);
}

/** @param array<string, mixed> $request
 * @return array{process: resource, pipes: array<int, resource>}
 */
function startOfflineCheckoutWorker(string $schema, array $request): array {
    $disabled = 'mail,curl_init,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,exec,shell_exec,system,passthru,proc_open,imap_open,socket_connect';
    $command = [PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'disable_functions=' . $disabled];
    $extension = getenv('BDTA_CHECKOUT_TEST_EXTENSION');
    if ($extension !== false && $extension !== '') {
        $command = array_merge($command, ['-d', 'extension=' . $extension]);
    }
    $command = array_merge($command, [__FILE__, '--worker', $schema, json_encode($request, JSON_THROW_ON_ERROR)]);
    // Fixed PHP binary/script and array argv with no shell; request JSON remains data.
    // nosemgrep: php.lang.security.exec-use.exec-use
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to launch synthetic checkout worker.');
    }
    return ['process' => $process, 'pipes' => $pipes];
}

/** @param array{process: resource, pipes: array<int, resource>} $worker
 * @return array<string, mixed>
 */
function finishOfflineCheckoutWorker(array $worker): array {
    fclose($worker['pipes'][0]);
    $output = stream_get_contents($worker['pipes'][1]);
    $stderr = stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    $exit = proc_close($worker['process']);
    if ($exit !== 0 || $output === false) {
        throw new RuntimeException('Synthetic worker failed: ' . (string) $stderr);
    }
    $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid synthetic worker response.');
    }
    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/** @return array<string, list<array<string, string>>> */
function offlineCheckoutSnapshot(SafePDO $conn): array {
    $snapshot = [];
    foreach (['clients', 'client_packages', 'client_package_credits', 'package_credit_transactions',
        'invoices', 'invoice_items', 'invoice_payments', 'form_submissions', 'workflow_enrollments',
        'workflow_step_executions', 'notifications', 'client_emails', 'bookings'] as $table) {
        $snapshot[$table] = $conn->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $snapshot;
}

$schema = 'bdta_test_checkout_' . bin2hex(random_bytes(6));
$server = new PDO("mysql:host=127.0.0.1;port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE {$schema} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
putenv('DB_NAME=' . $schema);
require_once dirname(__DIR__) . '/backend/includes/database.php';
putenv('DB_PASSWORD=' . $password);
$sessionDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $schema;
if (!mkdir($sessionDirectory, 0700)) {
    throw new RuntimeException('Unable to create synthetic session directory.');
}
putenv('BDTA_CHECKOUT_TEST_SESSION_DIRECTORY=' . $sessionDirectory);
$checks = 0;
$failures = 0;
$check = static function (bool $passed, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$passed) {
        $failures++;
    }
    echo ($passed ? 'PASS: ' : 'FAIL: ') . $label . "\n";
};
try {
    $conn = (new Database())->getConnection();
    $conn->exec("UPDATE settings SET setting_value = '' WHERE setting_key IN ('smtp_host', 'smtp_username', 'smtp_password')");
    $conn->exec("UPDATE settings SET setting_value = '0' WHERE setting_key = 'stripe_enabled'");
    $appointmentType = safe_int($conn->query('SELECT id FROM appointment_types ORDER BY id LIMIT 1')->fetchColumn());
    $conn->prepare('INSERT INTO packages (name, description, price, share_token, is_active) VALUES (?, ?, 0, ?, 1)')
        ->execute(['Synthetic retry package', 'Disposable C1 regression', str_repeat('a', 32)]);
    $packageId = safe_int($conn->lastInsertId());
    $conn->prepare('INSERT INTO package_items (package_id, appointment_type_id, quantity) VALUES (?, ?, 2)')->execute([$packageId, $appointmentType]);
    $conn->exec("INSERT INTO form_templates (name, fields, form_type, is_active) VALUES ('Synthetic checkout form', '[]', 'client_form', 1)");
    $formId = safe_int($conn->lastInsertId());
    $conn->exec("INSERT INTO workflows (name, is_active) VALUES ('Synthetic checkout workflow', 1)");
    $workflowId = safe_int($conn->lastInsertId());
    $conn->prepare("INSERT INTO workflow_triggers (workflow_id, trigger_type, form_template_id, is_active) VALUES (?, 'form_submission', ?, 1)")
        ->execute([$workflowId, $formId]);
    $conn->prepare("INSERT INTO workflow_steps (workflow_id, step_order, step_name, email_subject, email_body_html, delay_type, delay_value) VALUES (?, 1, 'Synthetic queued step', 'Synthetic only', '<p>Synthetic only</p>', 'after_enrollment', '1 day')")
        ->execute([$workflowId]);
    $request = static function (string $mode, string $email, string $token = '', string $fault = '', bool $csrf = true, bool $gate = false, int $form = 0) use ($packageId): array {
        return ['mode' => $mode, 'email' => $email, 'token' => $token, 'csrf' => $csrf,
            'fault' => $fault, 'gate' => $gate, 'package_id' => $packageId, 'form_id' => $form];
    };
    $run = static function (array $request) use ($schema): array {
        return finishOfflineCheckoutWorker(startOfflineCheckoutWorker($schema, $request));
    };
    $countPurchases = static function (string $email) use ($conn): int {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM client_packages cp JOIN clients c ON c.id = cp.client_id WHERE c.email = ?');
        $stmt->execute([$email]);
        return safe_int($stmt->fetchColumn());
    };

    // Simulate an existing current schema with historical purchases but no key.
    $run($request('helper', 'history@example.test'));
    $run($request('helper', 'history@example.test'));
    $check($countPurchases('history@example.test') === 2, 'callers without an attempt key retain separate purchases');
    $history = offlineCheckoutSnapshot($conn);
    $withoutAttemptColumn = static function (array $snapshot): array {
        foreach ($snapshot['client_packages'] as &$row) {
            unset($row['checkout_attempt_token']);
        }
        unset($row);
        return $snapshot;
    };
    $hasColumn = $conn->query("SHOW COLUMNS FROM client_packages LIKE 'checkout_attempt_token'")->fetch(PDO::FETCH_ASSOC) !== false;
    if ($hasColumn) {
        $conn->exec('DROP INDEX idx_client_packages_checkout_attempt ON client_packages');
        $conn->exec('ALTER TABLE client_packages DROP COLUMN checkout_attempt_token');
    }
    (new ReflectionProperty(Database::class, 'sharedConnection'))->setValue(null, null);
    $conn = (new Database())->getConnection();
    $check($hasColumn && $withoutAttemptColumn($history) === $withoutAttemptColumn(offlineCheckoutSnapshot($conn)), 'first upgrade preserves all existing history');
    (new ReflectionProperty(Database::class, 'sharedConnection'))->setValue(null, null);
    $conn = (new Database())->getConnection();
    $upgraded = offlineCheckoutSnapshot($conn);
    $check($withoutAttemptColumn($history) === $withoutAttemptColumn($upgraded), 'repeated bootstrap preserves historical rows');
    $conn->exec('DROP INDEX idx_client_packages_checkout_attempt ON client_packages');
    (new ReflectionProperty(Database::class, 'sharedConnection'))->setValue(null, null);
    $conn = (new Database())->getConnection();
    $check($upgraded === offlineCheckoutSnapshot($conn), 'partial column-only migration restores mandatory uniqueness without changing history');
    foreach ([
        'nonunique' => 'CREATE INDEX idx_client_packages_checkout_attempt ON client_packages(checkout_attempt_token)',
        'prefix' => 'CREATE UNIQUE INDEX idx_client_packages_checkout_attempt ON client_packages(checkout_attempt_token(16))',
    ] as $label => $indexSQL) {
        $conn->exec('DROP INDEX idx_client_packages_checkout_attempt ON client_packages');
        $conn->exec($indexSQL);
        (new ReflectionProperty(Database::class, 'sharedConnection'))->setValue(null, null);
        $blocked = false;
        try {
            $conn = (new Database())->getConnection();
        } catch (RuntimeException $e) {
            $blocked = strpos($e->getMessage(), 'unique attempt-token index') !== false;
        }
        $check($blocked && $upgraded === offlineCheckoutSnapshot($conn), $label . ' index fails closed without altering history');
        $conn->exec('DROP INDEX idx_client_packages_checkout_attempt ON client_packages');
        (new ReflectionProperty(Database::class, 'sharedConnection'))->setValue(null, null);
        $conn = (new Database())->getConnection();
        $check($upgraded === offlineCheckoutSnapshot($conn), 'bootstrap recovers after the ' . $label . ' index is removed');
    }

    $get = $run($request('get', ''));
    $token = scalar_string($get['token'] ?? '');
    $check(preg_match('/^[a-f0-9]{64}$/', $token) === 1, 'new checkout form issues an attempt token');
    $token = $token !== '' ? $token : bin2hex(random_bytes(32));
    $retry = $request('post', 'retry@example.test', $token);
    $run($retry);
    $afterFirst = offlineCheckoutSnapshot($conn);
    $run($retry); // Discard the first redirect and replay the exact POST.
    $check($countPurchases('retry@example.test') === 1, 'identical offline POST creates one purchase');
    $check($afterFirst === offlineCheckoutSnapshot($conn), 'POST retry repeats no invoice, credit, form, booking or notification effects');
    $confirmation = $request('confirmation', '', $token);
    $confirmed = true;
    for ($refresh = 0; $refresh < 2; $refresh++) {
        $response = $run($confirmation);
        $confirmed = $confirmed && !empty($response['confirmed']);
    }
    $check($confirmed, 'confirmation survives refresh and a new session');
    $check($afterFirst === offlineCheckoutSnapshot($conn), 'confirmation GET does not fulfill again');
    $freshToken = scalar_string($run($request('get', ''))['token'] ?? '');
    $check($freshToken !== $token && preg_match('/^[a-f0-9]{64}$/', $freshToken) === 1, 'fresh form creates a distinct purchase attempt');
    $run($request('post', 'retry@example.test', $freshToken !== '' ? $freshToken : bin2hex(random_bytes(32))));
    $check($countPurchases('retry@example.test') === 2, 'intentional separate repeat purchase remains valid');
    $conn->exec("INSERT INTO packages (name, price, is_active) VALUES ('Synthetic other package', 0, 1)");
    $otherPackageId = safe_int($conn->lastInsertId());
    $conn->prepare('INSERT INTO package_items (package_id, appointment_type_id, quantity) VALUES (?, ?, 2)')->execute([$otherPackageId, $appointmentType]);
    $crossPackage = $request('helper', 'cross@example.test', $token);
    $crossPackage['package_id'] = $otherPackageId;
    $before = offlineCheckoutSnapshot($conn);
    $crossResponse = $run($crossPackage);
    $check(!empty($crossResponse['failure']) && $before === offlineCheckoutSnapshot($conn), 'attempt token cannot be rebound to a different package');

    foreach (['', 'invalid'] as $badToken) {
        $before = offlineCheckoutSnapshot($conn);
        $response = $run($request('post', 'invalid@example.test', $badToken));
        $check($before === offlineCheckoutSnapshot($conn) && !empty($response['error']), 'missing/malformed attempt token rejects mutation: ' . ($badToken === '' ? 'missing' : 'malformed'));
    }
    $before = offlineCheckoutSnapshot($conn);
    $run($request('post', 'retry@example.test', $token, '', false));
    $check($before === offlineCheckoutSnapshot($conn), 'CSRF remains required on a completed-attempt retry');
    $validationToken = bin2hex(random_bytes(32));
    $invalid = $run($request('post', '', $validationToken));
    $check(scalar_string($invalid['token'] ?? '') === $validationToken && !empty($invalid['error']), 'validation error retains the same attempt token');

    foreach (['credit', 'invoice', 'before_commit'] as $fault) {
        $faultToken = bin2hex(random_bytes(32));
        $email = $fault . '@example.test';
        $before = offlineCheckoutSnapshot($conn);
        $response = $run($request('post', $email, $faultToken, $fault));
        $check($before === offlineCheckoutSnapshot($conn) && !empty($response['error']), $fault . ' failure rolls back every persistence effect');
        $check(scalar_string($response['token'] ?? '') === $faultToken, $fault . ' failure retains retry key');
        $run($request('post', $email, $faultToken));
        $afterRetry = offlineCheckoutSnapshot($conn);
        $run($request('post', $email, $faultToken));
        $check($countPurchases($email) === 1 && $afterRetry === offlineCheckoutSnapshot($conn), $fault . ' retry recovers exactly one complete purchase');
    }
    $lostToken = bin2hex(random_bytes(32));
    $run($request('helper', 'lost@example.test', $lostToken, 'after_commit'));
    $afterLostCommit = offlineCheckoutSnapshot($conn);
    $lostRetry = $run($request('helper', 'lost@example.test', $lostToken));
    $check(is_array($lostRetry['result'] ?? null) && $countPurchases('lost@example.test') === 1 && $afterLostCommit === offlineCheckoutSnapshot($conn), 'lost commit acknowledgement recovers original purchase without effects');

    $formToken = bin2hex(random_bytes(32));
    $firstForm = $run($request('helper', 'form@example.test', $formToken, '', true, false, $formId));
    $afterForm = offlineCheckoutSnapshot($conn);
    $check(count($afterForm['form_submissions']) === 1 && count($afterForm['workflow_enrollments']) === 1
        && count($afterForm['workflow_step_executions']) === 1, 'first purchase persists its form and queued workflow once');
    $repeatForm = $run($request('helper', 'changed@example.test', $formToken, '', true, false, $formId));
    $check(is_array($firstForm['result'] ?? null) && is_array($repeatForm['result'] ?? null)
        && $firstForm['result']['client_package_id'] === $repeatForm['result']['client_package_id']
        && $afterForm === offlineCheckoutSnapshot($conn), 'same attempt recovers original result without rerunning submitted forms or workflows');

    $raceToken = bin2hex(random_bytes(32));
    $raceRequest = $request('helper', 'race@example.test', $raceToken, '', true, true);
    $workers = [startOfflineCheckoutWorker($schema, $raceRequest), startOfflineCheckoutWorker($schema, $raceRequest)];
    foreach ($workers as $worker) {
        if (trim((string) fgets($worker['pipes'][1])) !== 'READY') {
            throw new RuntimeException('Both workers must reach pre-persistence gate.');
        }
    }
    foreach ($workers as $worker) {
        fwrite($worker['pipes'][0], "GO\n");
    }
    $raceResults = array_map('finishOfflineCheckoutWorker', $workers);
    $check($countPurchases('race@example.test') === 1, 'concurrent first attempts create one purchase');
    $raceClientStmt = $conn->prepare('SELECT COUNT(*) FROM clients WHERE email = ?');
    $raceClientStmt->execute(['race@example.test']);
    $check(safe_int($raceClientStmt->fetchColumn()) === 1, 'concurrent loser leaves no orphan client');
    $check(is_array($raceResults[0]['result'] ?? null) && is_array($raceResults[1]['result'] ?? null)
        && $raceResults[0]['result']['client_package_id'] === $raceResults[1]['result']['client_package_id'], 'concurrent loser recovers the committed winner');
    $check(safe_int($conn->query('SELECT COUNT(*) FROM bookings')->fetchColumn()) === 0, 'checkout retries do not create bookings');
    $before = offlineCheckoutSnapshot($conn);
    $conn->prepare('DELETE FROM package_items WHERE package_id = ?')->execute([$packageId]);
    $conn->prepare('UPDATE packages SET price = 99 WHERE id = ?')->execute([$packageId]);
    $changedConfigRetry = $run($retry);
    $changedConfigConfirmation = $run($confirmation);
    $check(empty($changedConfigRetry['error']) && !empty($changedConfigConfirmation['confirmed'])
        && $before === offlineCheckoutSnapshot($conn), 'committed offline retry bypasses changed price and item validation without payment transport');
    $conn->prepare('INSERT INTO package_items (package_id, appointment_type_id, quantity) VALUES (?, ?, 2)')->execute([$packageId, $appointmentType]);
    $offlineToken = bin2hex(random_bytes(32));
    $offlineRequest = $request('helper', 'offline-priced@example.test', $offlineToken);
    $offlineResult = $run($offlineRequest);
    $before = offlineCheckoutSnapshot($conn);
    $run($offlineRequest);
    $check(is_array($offlineResult['result'] ?? null) && $countPurchases('offline-priced@example.test') === 1
        && $before === offlineCheckoutSnapshot($conn), 'legitimate nonzero-price offline helper purchase and invoice remain idempotent');
    $invoice = $conn->query('SELECT total_amount, status FROM invoices ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $check(is_array($invoice) && safe_float($invoice['total_amount'] ?? 0) === 99.0 && $invoice['status'] === 'draft'
        && safe_int($conn->query('SELECT COUNT(*) FROM invoice_payments')->fetchColumn()) === 0, 'offline invoice retains its unpaid amount without a provider payment');
    echo "Offline checkout checks: {$checks}; failures: {$failures}.\n";
} finally {
    $server->exec("DROP DATABASE {$schema}");
    rmdir($sessionDirectory);
}
exit($failures === 0 ? 0 : 1);
