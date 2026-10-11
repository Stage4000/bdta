#!/usr/bin/env php
<?php
/** Real-controller atomicity regressions on a disposable loopback MariaDB/MySQL. */
$port = getenv('BDTA_CREDIT_TEST_PORT');
$user = getenv('BDTA_CREDIT_TEST_USER');
if ($port === false || $user === false) {
    echo "SKIP: set BDTA_CREDIT_TEST_PORT and BDTA_CREDIT_TEST_USER for a disposable loopback server.\n";
    exit(0);
}
if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535 || $user === '') {
    throw new RuntimeException('Invalid disposable fixture configuration.');
}
$password = getenv('BDTA_CREDIT_TEST_PASSWORD');
$password = $password === false ? '' : $password;
putenv('DB_TYPE=mysql');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=' . $port);
putenv('DB_USER=' . $user);
putenv('DB_PASSWORD=' . $password);

/** @param array<string, mixed> $request
 * @return array{process: resource, pipes: array<int, resource>, gate_path: string}
 */
function startCreditAtomicityWorker(string $schema, array $request): array {
    $gateKey = bin2hex(random_bytes(8));
    $request['gate_key'] = $gateKey;
    $directory = getenv('BDTA_CREDIT_TEST_SESSION_DIRECTORY');
    if ($directory === false || !is_dir($directory)) { throw new RuntimeException('Private fixture directory required.'); }
    $disabled = 'mail,curl_init,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,exec,shell_exec,system,passthru,proc_open,imap_open,socket_connect';
    $command = [PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'disable_functions=' . $disabled];
    $extension = getenv('BDTA_CREDIT_TEST_EXTENSION');
    if ($extension !== false && $extension !== '') {
        $command = array_merge($command, ['-d', 'extension=' . $extension]);
    }
    $command = array_merge($command, [__DIR__ . '/support/booking_credit_atomicity_fixture.php',
        '--worker', $schema, json_encode($request, JSON_THROW_ON_ERROR)]);
    // Fixed executable/fixture and array argv; request JSON is data, with no shell.
    // nosemgrep: php.lang.security.exec-use.exec-use
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to launch synthetic worker.');
    }
    return ['process' => $process, 'pipes' => $pipes, 'gate_path' => $directory . DIRECTORY_SEPARATOR . 'gate_' . $gateKey];
}

/** @param array{process: resource, pipes: array<int, resource>, gate_path: string} $worker
 * @return array{result: array<string, mixed>, failure: string, mail_calls: int}
 */
function finishCreditAtomicityWorker(array $worker, string $prefix = ''): array {
    fclose($worker['pipes'][0]);
    $deadline = microtime(true) + 30;
    do {
        $status = proc_get_status($worker['process']);
        if (!$status['running']) { break; }
        if (microtime(true) >= $deadline) {
            proc_terminate($worker['process']);
            foreach ([1, 2] as $pipe) { fclose($worker['pipes'][$pipe]); }
            proc_close($worker['process']);
            throw new RuntimeException('Synthetic worker completion timed out.');
        }
        usleep(10000);
    } while (true);
    $output = stream_get_contents($worker['pipes'][1]);
    $stderr = stream_get_contents($worker['pipes'][2]);
    fclose($worker['pipes'][1]);
    fclose($worker['pipes'][2]);
    $exit = proc_close($worker['process']);
    if (($exit !== 0 && $status['exitcode'] !== 0) || $output === false) {
        throw new RuntimeException('Synthetic worker failed: ' . (string) $stderr);
    }
    $decoded = json_decode($prefix . $output, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid worker response: ' . (string) $stderr);
    }
    return ['result' => assoc_row($decoded['result'] ?? null), 'failure' => scalar_string($decoded['failure'] ?? ''),
        'mail_calls' => safe_int($decoded['mail_calls'] ?? 0)];
}

/** @param list<array<string, mixed>> $requests
 * @return list<array<string, mixed>>
 */
function raceCreditAtomicityWorkers(string $schema, array $requests, bool $staggerCommit = false): array {
    $workers = array_map(static fn (array $request): array => startCreditAtomicityWorker($schema, $request), $requests);
    $results = [];
    try {
        $phases = array_map(static fn (array $request): string => ($request['mode'] ?? '') === 'cancel' ? 'booking' : 'credit', $requests);
        foreach ($workers as $index => $worker) {
            $deadline = microtime(true) + 15;
            while (!is_file($worker['gate_path'] . '.ready') || file_get_contents($worker['gate_path'] . '.ready') !== $phases[$index]) {
                if (microtime(true) >= $deadline) { throw new RuntimeException('Both stale-read gates must arrive before serialization.'); }
                usleep(10000);
            }
        }
        foreach ($workers as $index => $worker) {
            file_put_contents($worker['gate_path'] . '.go', $phases[$index]);
            if ($staggerCommit) {
                $results[] = finishCreditAtomicityWorker($worker);
                unset($workers[$index]);
            }
        }
        if (in_array('booking', $phases, true)) {
            // The vulnerable endpoint waits after its unprotected refund read;
            // corrected requests may finish or wait on a transaction row lock.
            $deadline = microtime(true) + 15;
            foreach ($workers as $worker) {
                while (!is_file($worker['gate_path'] . '.done')) {
                    if (is_file($worker['gate_path'] . '.ready') && file_get_contents($worker['gate_path'] . '.ready') === 'refund') { break; }
                    if (microtime(true) >= $deadline) { throw new RuntimeException('Refund gate or protected completion timed out.'); }
                    usleep(10000);
                }
            }
            foreach ($workers as $worker) {
                if (is_file($worker['gate_path'] . '.ready') && file_get_contents($worker['gate_path'] . '.ready') === 'refund') {
                    file_put_contents($worker['gate_path'] . '.go', 'refund');
                }
            }
        }
        foreach ($workers as $index => $worker) {
            $results[] = finishCreditAtomicityWorker($worker);
            unset($workers[$index]);
        }
    } finally {
        foreach ($workers as $worker) {
            if (is_resource($worker['process'])) { proc_terminate($worker['process']); }
            foreach ($worker['pipes'] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($worker['process'])) { proc_close($worker['process']); }
        }
    }
    foreach ($results as $result) {
        if (str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'availability is being updated')) {
            throw new RuntimeException('Schedule-lock timeout is not valid credit-race evidence.');
        }
    }
    return $results;
}

/** @return array<string, list<array<string, string>>> */
function creditAtomicitySnapshot(SafePDO $conn): array {
    $snapshot = [];
    foreach (['clients', 'pets', 'bookings', 'appointment_pets', 'form_submissions', 'client_package_credits',
        'package_credit_transactions', 'booking_change_log', 'client_activity_log', 'workflow_enrollments',
        'workflow_step_executions', 'notifications', 'client_emails', 'invoices', 'invoice_items'] as $table) {
        $snapshot[$table] = $conn->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $snapshot;
}

$schema = 'bdta_test_credit_' . bin2hex(random_bytes(6));
$server = new PDO("mysql:host=127.0.0.1;port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
putenv('DB_NAME=' . $schema);
require_once dirname(__DIR__) . '/backend/includes/database.php';
putenv('DB_PASSWORD=' . $password);
$sessionDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $schema;
$schemaCreated = false;
$sessionCreated = false;
putenv('BDTA_CREDIT_TEST_SESSION_DIRECTORY=' . $sessionDirectory);
$checks = 0;
$failures = 0;
$check = static function (bool $passed, string $label) use (&$checks, &$failures): void {
    $checks++;
    $failures += $passed ? 0 : 1;
    echo ($passed ? 'PASS: ' : 'FAIL: ') . $label . "\n";
};
try {
    $server->exec("CREATE DATABASE {$schema} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $schemaCreated = true;
    if (!mkdir($sessionDirectory, 0700)) { throw new RuntimeException('Unable to create private fixture sessions.'); }
    $sessionCreated = true;
    $conn = (new Database())->getConnection();
    define('BDTA_TEST_MODE', true);
    ini_set('session.save_path', $sessionDirectory);
    session_start();
    require_once dirname(__DIR__) . '/backend/includes/config.php';
    putenv('DB_PASSWORD=' . $password);
    $conn->exec("UPDATE settings SET setting_value = '' WHERE setting_key IN ('smtp_host', 'smtp_username', 'smtp_password', 'google_client_id', 'google_client_secret', 'google_refresh_token', 'mailjet_api_key', 'mailjet_secret_key')");
    $conn->exec("UPDATE settings SET setting_value = '0' WHERE setting_key IN ('stripe_enabled', 'google_calendar_enabled', 'turnstile_enabled')");
    $conn->exec("INSERT INTO packages (name, price, is_active) VALUES ('Synthetic atomicity credits', 0, 1)");
    $packageId = safe_int($conn->lastInsertId());
    $conn->exec("INSERT INTO form_templates (name, fields, form_type, is_active) VALUES ('Synthetic booking form', '[]', 'client_form', 1)");
    $formId = safe_int($conn->lastInsertId());
    $conn->exec("INSERT INTO workflows (name, is_active) VALUES ('Synthetic queued booking workflow', 1)");
    $workflowId = safe_int($conn->lastInsertId());
    $conn->prepare("INSERT INTO workflow_triggers (workflow_id, trigger_type, form_template_id, is_active) VALUES (?, 'form_submission', ?, 1)")->execute([$workflowId, $formId]);
    $conn->prepare("INSERT INTO workflow_steps (workflow_id, step_order, step_name, email_subject, email_body_html, delay_type, delay_value) VALUES (?, 1, 'Synthetic queued step', 'Synthetic only', '<p>Synthetic only</p>', 'after_enrollment', '1 day')")->execute([$workflowId]);
    $scenarioNumber = 0;
    $scenario = static function (int $total = 3, int $used = 0, bool $pending = false, bool $portal = true) use ($conn, $packageId, $formId, &$scenarioNumber): array {
        $scenarioNumber++;
        $email = 'atomic-' . $scenarioNumber . '@example.test';
        $conn->prepare("INSERT INTO clients (name, email, address) VALUES ('Synthetic Credit Client', ?, 'Synthetic existing address')")->execute([$email]);
        $clientId = safe_int($conn->lastInsertId());
        $conn->prepare("INSERT INTO appointment_types (name, duration_minutes, is_active, portal_available, requires_admin_confirmation, location_types, available_days, available_start_time, available_end_time, advance_booking_max_days) VALUES (?, 60, 1, ?, ?, ?, ?, '09:00', '17:00', 365)")
            ->execute(['Synthetic Atomic ' . $scenarioNumber, (int) $portal, (int) $pending, '["client_address"]', '[1,2,3,4,5]']);
        $typeId = safe_int($conn->lastInsertId());
        $conn->prepare("INSERT INTO client_packages (client_id, package_id, package_name, is_active) VALUES (?, ?, 'Synthetic atomicity credits', 1)")->execute([$clientId, $packageId]);
        $clientPackageId = safe_int($conn->lastInsertId());
        $conn->prepare('INSERT INTO client_package_credits (client_package_id, client_id, appointment_type_id, total_credits, used_credits) VALUES (?, ?, ?, ?, ?)')->execute([$clientPackageId, $clientId, $typeId, $total, $used]);
        $creditId = safe_int($conn->lastInsertId());
        $conn->prepare("INSERT INTO pets (client_id, name, species, is_active) VALUES (?, 'Synthetic Pet', 'Dog', 1)")->execute([$clientId]);
        $petId = safe_int($conn->lastInsertId());
        $date = gmdate('Y-m-d', safe_timestamp(strtotime('next Monday +' . (21 + 7 * $scenarioNumber) . ' days')));
        return ['client_id' => $clientId, 'credit_id' => $creditId, 'type_id' => $typeId,
            'data' => ['action' => 'book', 'appointment_type_id' => $typeId, 'appointment_date' => $date,
                'appointment_time' => '10:00', 'client_name' => 'Synthetic Credit Client', 'client_email' => $email,
                'client_phone' => '555-0100', 'service_type' => 'Synthetic Atomic ' . $scenarioNumber,
                'location_type' => 'client_address', 'client_address' => 'Synthetic replacement address', 'overwrite_profile' => true,
                'use_credit' => true, 'pet_ids' => [$petId], 'form_responses' => [$formId => ['Synthetic answer']],
                'csrf_token' => str_repeat('a', 64), 'booking_attempt_token' => bin2hex(random_bytes(32))]];
    };
    $request = static function (array $fixture, string $mode = 'portal_book', string $fault = '', bool $gate = false): array {
        return ['mode' => $mode, 'client_id' => $fixture['client_id'], 'data' => $fixture['data'], 'fault' => $fault, 'gate' => $gate];
    };
    /** @var Closure(array<mixed>): array{result: array<string, mixed>, failure: string, mail_calls: int} $run */
    $run = static fn (array $request): array => finishCreditAtomicityWorker(startCreditAtomicityWorker($schema, $request));
    $count = static function (string $table, string $column, int $id, string $extra = '') use ($conn): int {
        $stmt = match ([$table, $column, $extra]) {
            ['bookings', 'client_id', ''] => $conn->prepare('SELECT COUNT(*) FROM bookings WHERE client_id = ?'),
            ['form_submissions', 'client_id', ''] => $conn->prepare('SELECT COUNT(*) FROM form_submissions WHERE client_id = ?'),
            ['workflow_enrollments', 'client_id', ''] => $conn->prepare('SELECT COUNT(*) FROM workflow_enrollments WHERE client_id = ?'),
            ['package_credit_transactions', 'client_id', ''] => $conn->prepare('SELECT COUNT(*) FROM package_credit_transactions WHERE client_id = ?'),
            ['package_credit_transactions', 'client_id', " AND transaction_type = 'consume'"] => $conn->prepare("SELECT COUNT(*) FROM package_credit_transactions WHERE client_id = ? AND transaction_type = 'consume'"),
            ['package_credit_transactions', 'client_id', " AND transaction_type = 'refund'"] => $conn->prepare("SELECT COUNT(*) FROM package_credit_transactions WHERE client_id = ? AND transaction_type = 'refund'"),
            ['booking_change_log', 'client_id', ''] => $conn->prepare('SELECT COUNT(*) FROM booking_change_log WHERE client_id = ?'),
            ['invoices', 'client_id', ''] => $conn->prepare('SELECT COUNT(*) FROM invoices WHERE client_id = ?'),
            default => throw new LogicException('Unsupported synthetic count query.'),
        };
        $stmt->execute([$id]);
        return safe_int($stmt->fetchColumn());
    };
    $usedCredits = static function (int $id) use ($conn): int {
        $stmt = $conn->prepare('SELECT used_credits FROM client_package_credits WHERE id = ?');
        $stmt->execute([$id]);
        return safe_int($stmt->fetchColumn());
    };
    $bookingRow = static function (int $id) use ($conn): array {
        $stmt = $conn->prepare('SELECT * FROM bookings WHERE id = ?');
        $stmt->execute([$id]);
        return assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
    };
    $succeeded = static fn (array $response): bool => is_array($response['result'] ?? null) && !empty($response['result']['success']);

    $valid = $scenario();
    $response = $run($request($valid));
    if (!$succeeded($response)) { echo 'INFO: valid portal response ' . json_encode($response, JSON_THROW_ON_ERROR) . "\n"; }
    $check($succeeded($response) && !empty($response['result']['credit_applied']) && $usedCredits($valid['credit_id']) === 1
        && $count('bookings', 'client_id', $valid['client_id']) === 1
        && $count('package_credit_transactions', 'client_id', $valid['client_id'], " AND transaction_type = 'consume'") === 1, 'valid portal booking and debit remain supported');
    $check($count('form_submissions', 'client_id', $valid['client_id']) === 1
        && $count('workflow_enrollments', 'client_id', $valid['client_id']) === 1, 'valid booking persists its form and queued workflow');
    $pending = $scenario(3, 0, true);
    $response = $run($request($pending));
    $check($succeeded($response) && ($response['result']['booking_status'] ?? '') === 'pending'
        && $usedCredits($pending['credit_id']) === 0 && $count('package_credit_transactions', 'client_id', $pending['client_id']) === 0, 'pending booking does not debit a credit');
    $pendingId = safe_int($response['result']['booking_id'] ?? 0);
    $check($pendingId > 0 && ($bookingRow($pendingId)['package_credit_id'] ?? null) === null, 'pending booking has no applied credit link');
    $pending['data'] = ['action' => 'cancel', 'booking_id' => $pendingId];
    $response = $run($request($pending, 'cancel'));
    $check($succeeded($response) && $usedCredits($pending['credit_id']) === 0
        && $count('package_credit_transactions', 'client_id', $pending['client_id']) === 0, 'pending cancellation creates no refund');
    $uncredited = $scenario(0);
    $response = $run($request($uncredited));
    $check($succeeded($response) && empty($response['result']['credit_applied']) && $usedCredits($uncredited['credit_id']) === 0, 'portal-available uncredited booking remains supported');
    $uncredited['data'] = ['action' => 'cancel', 'booking_id' => safe_int($response['result']['booking_id'] ?? 0)];
    $response = $run($request($uncredited, 'cancel'));
    $check($succeeded($response) && $count('package_credit_transactions', 'client_id', $uncredited['client_id']) === 0, 'uncredited cancellation creates no refund');
    $publicUncredited = $scenario(0);
    $response = $run($request($publicUncredited, 'public_book'));
    $check($succeeded($response) && empty($response['result']['credit_applied']) && $usedCredits($publicUncredited['credit_id']) === 0, 'public optional-credit fallback remains supported');

    foreach (['portal_book' => ['debit', 'consume_ledger', 'pet_link'], 'public_book' => ['debit', 'consume_ledger']] as $mode => $faults) {
        foreach ($faults as $fault) {
            $fixture = $scenario();
            $before = creditAtomicitySnapshot($conn);
            $response = $run($request($fixture, $mode, $fault));
            $check(!$succeeded($response) && $before === creditAtomicitySnapshot($conn), $mode . ' ' . $fault . ' failure rolls back all booking effects');
            $check($response['mail_calls'] === 0, $mode . ' ' . $fault . ' failure sends no mail');
            $retry = $run($request($fixture, $mode));
            $check($succeeded($retry) && $count('bookings', 'client_id', $fixture['client_id']) === 1
                && $usedCredits($fixture['credit_id']) === 1
                && $count('package_credit_transactions', 'client_id', $fixture['client_id'], " AND transaction_type = 'consume'") === 1, $mode . ' ' . $fault . ' retry creates one complete credited booking');
        }
    }
    $invoiceFixture = $scenario();
    $conn->prepare('UPDATE appointment_types SET auto_invoice = 1, default_amount = 25 WHERE id = ?')->execute([$invoiceFixture['type_id']]);
    $before = creditAtomicitySnapshot($conn);
    $response = $run($request($invoiceFixture, 'public_book', 'invoice_item'));
    $check(!$succeeded($response) && $before === creditAtomicitySnapshot($conn), 'invoice item failure rolls back booking, debit, ledger, invoice and dependent rows');
    $response = $run($request($invoiceFixture, 'public_book'));
    $check($succeeded($response) && $count('invoices', 'client_id', $invoiceFixture['client_id']) === 1
        && $usedCredits($invoiceFixture['credit_id']) === 1, 'auto-invoice retry creates one complete credited booking and invoice');
    foreach (['portal_book', 'public_book'] as $mode) {
        $fixture = $scenario();
        $response = $run($request($fixture, $mode, 'mail'));
        $check($succeeded($response) && $response['mail_calls'] > 0
            && $usedCredits($fixture['credit_id']) === 1 && $count('bookings', 'client_id', $fixture['client_id']) === 1,
            $mode . ' post-commit mail exception retains a successful complete booking');
    }

    foreach (['portal_book', 'public_book'] as $mode) {
        $fixture = $scenario(1, 0, false, false);
        $first = $request($fixture, $mode, '', true);
        $second = $first;
        $second['data']['appointment_time'] = '14:00'; // Different valid intent/slot.
        $second['data']['booking_attempt_token'] = bin2hex(random_bytes(32));
        $responses = raceCreditAtomicityWorkers($schema, [$first, $second], $mode === 'public_book');
        $creditedStmt = $conn->prepare('SELECT COUNT(*) FROM bookings WHERE client_id = ? AND package_credit_id = ?');
        $creditedStmt->execute([$fixture['client_id'], $fixture['credit_id']]);
        $creditedBookings = safe_int($creditedStmt->fetchColumn());
        $creditedResponses = array_filter($responses, static fn (array $response): bool => is_array($response['result'] ?? null) && !empty($response['result']['credit_applied']));
        $oneBookingRequired = $mode !== 'portal_book' || $count('bookings', 'client_id', $fixture['client_id']) === 1;
        echo 'INFO: ' . $mode . ' stale-credit race ' . json_encode(['successful_requests' => count(array_filter($responses, $succeeded)),
            'credit_backed_bookings' => $creditedBookings, 'total_credits' => 1, 'used_credits' => $usedCredits($fixture['credit_id']),
            'consume_rows' => $count('package_credit_transactions', 'client_id', $fixture['client_id'], " AND transaction_type = 'consume'")], JSON_THROW_ON_ERROR) . "\n";
        $check(count($creditedResponses) === 1 && $creditedBookings === 1 && $oneBookingRequired, $mode . ' last-credit race commits one credit-backed booking only');
        $check($usedCredits($fixture['credit_id']) === 1
            && $count('package_credit_transactions', 'client_id', $fixture['client_id'], " AND transaction_type = 'consume'") === 1, $mode . ' last-credit race cannot overspend or duplicate consume ledger');
    }

    $cancelFixture = static function () use ($scenario, $conn): array {
        $fixture = $scenario(3, 2);
        $fixture['booking_data'] = $fixture['data'];
        $conn->prepare("INSERT INTO bookings (client_id, appointment_type_id, client_name, client_email, service_type, appointment_date, appointment_time, duration_minutes, status, package_credit_id) VALUES (?, ?, 'Synthetic Credit Client', ?, 'Synthetic cancellation', ?, '10:00', 60, 'confirmed', ?)")
            ->execute([$fixture['client_id'], $fixture['type_id'], $fixture['data']['client_email'], $fixture['data']['appointment_date'], $fixture['credit_id']]);
        $bookingId = safe_int($conn->lastInsertId());
        $conn->prepare("INSERT INTO package_credit_transactions (client_package_credit_id, client_id, appointment_type_id, transaction_type, amount, booking_id) VALUES (?, ?, ?, 'consume', -1, ?)")
            ->execute([$fixture['credit_id'], $fixture['client_id'], $fixture['type_id'], $bookingId]);
        // The other used unit belongs to another booking and must remain consumed.
        $otherDate = gmdate('Y-m-d', safe_timestamp(strtotime($fixture['data']['appointment_date'] . ' +1 day')));
        $conn->prepare("INSERT INTO bookings (client_id, appointment_type_id, client_name, client_email, service_type, appointment_date, appointment_time, duration_minutes, status, package_credit_id) VALUES (?, ?, 'Synthetic Credit Client', ?, 'Synthetic other consumption', ?, '12:00', 60, 'confirmed', ?)")
            ->execute([$fixture['client_id'], $fixture['type_id'], $fixture['data']['client_email'], $otherDate, $fixture['credit_id']]);
        $otherBookingId = safe_int($conn->lastInsertId());
        $fixture['other_booking_id'] = $otherBookingId;
        $conn->prepare("INSERT INTO package_credit_transactions (client_package_credit_id, client_id, appointment_type_id, transaction_type, amount, booking_id) VALUES (?, ?, ?, 'consume', -1, ?)")
            ->execute([$fixture['credit_id'], $fixture['client_id'], $fixture['type_id'], $otherBookingId]);
        $fixture['data'] = ['action' => 'cancel', 'booking_id' => $bookingId, 'reason' => 'Synthetic cancellation', 'csrf_token' => str_repeat('a', 64)];
        return $fixture;
    };
    foreach (['refund_ledger', 'cancel_log'] as $fault) {
        $fixture = $cancelFixture();
        $before = creditAtomicitySnapshot($conn);
        $response = $run($request($fixture, 'cancel', $fault));
        $check(!$succeeded($response) && $before === creditAtomicitySnapshot($conn), $fault . ' failure rolls back cancellation and refund');
        $check($response['mail_calls'] === 0, $fault . ' failure sends no cancellation mail');
        $retry = $run($request($fixture, 'cancel'));
        $check($succeeded($retry) && $usedCredits($fixture['credit_id']) === 1
            && $count('package_credit_transactions', 'client_id', $fixture['client_id'], " AND transaction_type = 'refund'") === 1
            && $count('booking_change_log', 'client_id', $fixture['client_id']) === 1, $fault . ' retry completes one cancellation and refund');
    }
    $fixture = $cancelFixture();
    $response = $run($request($fixture, 'cancel'));
    $afterCancel = creditAtomicitySnapshot($conn);
    $run($request($fixture, 'cancel'));
    $check($succeeded($response) && $usedCredits($fixture['credit_id']) === 1 && $afterCancel === creditAtomicitySnapshot($conn), 'valid cancellation and sequential repeat refund once');
    $fixture = $cancelFixture();
    $raceRequest = $request($fixture, 'cancel', '', true);
    $responses = raceCreditAtomicityWorkers($schema, [$raceRequest, $raceRequest]);
    echo 'INFO: stale-cancellation race ' . json_encode(['successful_requests' => count(array_filter($responses, $succeeded)),
        'used_before' => 2, 'used_after' => $usedCredits($fixture['credit_id']),
        'refund_rows' => $count('package_credit_transactions', 'client_id', $fixture['client_id'], " AND transaction_type = 'refund'"),
        'change_log_rows' => $count('booking_change_log', 'client_id', $fixture['client_id'])], JSON_THROW_ON_ERROR) . "\n";
    $check(count(array_filter($responses, $succeeded)) === 1, 'concurrent cancellation has one successful mutation');
    $check($usedCredits($fixture['credit_id']) === 1, 'concurrent cancellation refunds only its own one credit');
    $check($count('package_credit_transactions', 'client_id', $fixture['client_id'], " AND transaction_type = 'refund'") === 1, 'concurrent cancellation inserts one refund ledger row');
    $check($count('booking_change_log', 'client_id', $fixture['client_id']) === 1, 'concurrent cancellation inserts one change log');
    $check(array_string_value($bookingRow(safe_int($fixture['data']['booking_id'])), 'status') === 'cancelled'
        && array_string_value($bookingRow(safe_int($fixture['other_booking_id'])), 'status') === 'confirmed', 'race cancellation changes only the requested booking status');
    $stmt = $conn->prepare("SELECT booking_id, client_package_credit_id, amount FROM package_credit_transactions WHERE client_id = ? AND transaction_type = 'refund'");
    $stmt->execute([$fixture['client_id']]);
    $refundRow = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
    $check(array_int_value($refundRow, 'booking_id') === safe_int($fixture['data']['booking_id'])
        && array_int_value($refundRow, 'client_package_credit_id') === $fixture['credit_id']
        && array_int_value($refundRow, 'amount') === 1, 'refund ledger identifies the exact cancelled booking and credit');

    foreach (['missing_consume', 'mismatched_credit', 'zero_used', 'expired_package', 'mail'] as $case) {
        $fixture = $cancelFixture();
        $targetId = safe_int($fixture['data']['booking_id']);
        if ($case === 'missing_consume') {
            $conn->prepare("DELETE FROM package_credit_transactions WHERE booking_id = ? AND transaction_type = 'consume'")->execute([$targetId]);
        } elseif ($case === 'mismatched_credit') {
            $other = $scenario();
            $conn->prepare('UPDATE bookings SET package_credit_id = ? WHERE id = ?')->execute([$other['credit_id'], $targetId]);
        } elseif ($case === 'zero_used') {
            $conn->prepare('UPDATE client_package_credits SET used_credits = 0 WHERE id = ?')->execute([$fixture['credit_id']]);
        } elseif ($case === 'expired_package') {
            $conn->prepare("UPDATE client_packages SET is_active = 0, expires_at = '2000-01-01' WHERE client_id = ?")->execute([$fixture['client_id']]);
        }
        $before = creditAtomicitySnapshot($conn);
        $response = $run($request($fixture, 'cancel', $case === 'mail' ? 'mail' : ''));
        if ($case === 'zero_used') {
            $check(!$succeeded($response) && $before === creditAtomicitySnapshot($conn), 'zero counter cannot fabricate a refund or partly cancel');
        } else {
            $expectedUsed = in_array($case, ['missing_consume', 'mismatched_credit'], true) ? 2 : 1;
            $expectedRefunds = $expectedUsed === 2 ? 0 : 1;
            $check($succeeded($response) && $usedCredits($fixture['credit_id']) === $expectedUsed
                && $count('package_credit_transactions', 'client_id', $fixture['client_id'], " AND transaction_type = 'refund'") === $expectedRefunds,
                $case . ' cancellation preserves supported refund behavior without invented credit');
        }
    }
    $fixture = $cancelFixture();
    $first = $request($fixture, 'cancel', '', true);
    $second = $first;
    $second['data']['booking_id'] = $fixture['other_booking_id'];
    $responses = raceCreditAtomicityWorkers($schema, [$first, $second]);
    $check(count(array_filter($responses, $succeeded)) === 2 && $usedCredits($fixture['credit_id']) === 0
        && $count('package_credit_transactions', 'client_id', $fixture['client_id'], " AND transaction_type = 'refund'") === 2,
        'concurrent cancellation of two different consumed bookings refunds both once');
    foreach (['portal_book', 'public_book'] as $mode) {
        $fixture = $cancelFixture();
        $creation = $request($fixture, $mode, '', true);
        $creation['data'] = $fixture['booking_data'];
        $creation['data']['appointment_time'] = '14:00';
        unset($creation['data']['form_responses'], $creation['data']['client_address']);
        $creation['data']['overwrite_profile'] = false;
        $responses = raceCreditAtomicityWorkers($schema, [$creation, $request($fixture, 'cancel', '', true)]);
        $check(count(array_filter($responses, $succeeded)) === 2 && $usedCredits($fixture['credit_id']) === 2
            && $count('bookings', 'client_id', $fixture['client_id']) === 3
            && $count('package_credit_transactions', 'client_id', $fixture['client_id'], " AND transaction_type = 'consume'") === 3
            && $count('package_credit_transactions', 'client_id', $fixture['client_id'], " AND transaction_type = 'refund'") === 1,
            $mode . ' creation without forms/profile writes and cancellation share a consistent credit lock order');
    }
    echo "Booking credit atomicity checks: {$checks}; failures: {$failures}.\n";
} finally {
    if ($schemaCreated) { $server->exec("DROP DATABASE {$schema}"); }
    if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
    foreach (glob($sessionDirectory . DIRECTORY_SEPARATOR . 'gate_*') ?: [] as $marker) {
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- only markers in this newly-created private fixture directory.
        unlink($marker);
    }
    if ($sessionCreated) { rmdir($sessionDirectory); }
}
exit($failures === 0 ? 0 : 1);
