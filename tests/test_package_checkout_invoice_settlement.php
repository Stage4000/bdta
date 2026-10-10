#!/usr/bin/env php
<?php
// Combined C1/P3 regression; only randomly named schemas on an opted-in local server.
$port = getenv('BDTA_CHECKOUT_TEST_PORT');
$user = getenv('BDTA_CHECKOUT_TEST_USER');
if ($port === false || $user === false) {
    echo "SKIP: opt in with the disposable checkout test server variables.\n";
    exit(0);
}
if (PHP_SAPI !== 'cli' || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535 || $user === ''
    || function_exists('mail') || function_exists('curl_exec') || ini_get('allow_url_fopen') !== '0') {
    throw new RuntimeException('Combined checkout test requires CLI and disabled outbound transports.');
}
$password = getenv('BDTA_CHECKOUT_TEST_PASSWORD') ?: '';
$schema = 'bdta_test_checkout_settlement_' . bin2hex(random_bytes(6));
$server = new PDO("mysql:host=127.0.0.1;port=$port;charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE $schema CHARACTER SET utf8mb4");
foreach (['DB_TYPE' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => $port, 'DB_USER' => $user,
    'DB_PASSWORD' => $password, 'DB_NAME' => $schema] as $key => $value) putenv($key . '=' . $value);
require_once dirname(__DIR__) . '/backend/includes/package_checkout.php';
require_once dirname(__DIR__) . '/backend/includes/invoice_payment.php';
$checks = 0;
function checkoutSettlementAssert(bool $passed, string $message): void {
    global $checks;
    if (!$passed) throw new RuntimeException($message);
    $checks++;
    echo 'PASS: ' . $message . PHP_EOL;
}
try {
    $conn = (new Database())->getConnection();
    $conn->exec("UPDATE settings SET setting_value = '' WHERE setting_key IN ('smtp_host','smtp_username','smtp_password')");
    $conn->exec("INSERT INTO packages(name, price, is_active) VALUES('Synthetic combined package',35,1)");
    $package_id = safe_int($conn->lastInsertId());
    $type_id = safe_int($conn->query('SELECT id FROM appointment_types ORDER BY id LIMIT 1')->fetchColumn());
    $conn->prepare('INSERT INTO package_items(package_id,appointment_type_id,quantity) VALUES(?,?,3)')->execute([$package_id,$type_id]);
    $package = assoc_row($conn->query('SELECT * FROM packages WHERE id = ' . $package_id)->fetch(PDO::FETCH_ASSOC));
    $items = assoc_rows($conn->query('SELECT * FROM package_items WHERE package_id = ' . $package_id)->fetchAll(PDO::FETCH_ASSOC));
    foreach (['manual', 'online', 'changed-definition'] as $mode) {
        $token = bin2hex(random_bytes(32));
        $email = $mode . '@example.test';
        $purchase = bdta_finalize_package_purchase($conn,$package,$items,'Synthetic buyer',$email,'','',null,[],null,'offline',null,null,$token);
        $invoice = bdta_find_package_purchase_invoice($conn,$purchase['client_id'],$purchase['client_package_id']);
        $invoice_id = array_int_value($invoice,'id');
        if ($mode === 'changed-definition') {
            $conn->prepare('UPDATE packages SET is_active = 0 WHERE id = ?')->execute([$package_id]);
            $conn->prepare('DELETE FROM package_items WHERE package_id = ?')->execute([$package_id]);
        }
        if ($mode !== 'online') {
            bdta_invoice_record_manual_payment($conn,$invoice_id,35,'cash',date('Y-m-d'),null);
        } else {
            bdta_invoice_record_manual_payment($conn,$invoice_id,10,'cash',date('Y-m-d'),null);
            $result = bdta_invoice_record_checkout_payment($conn,$invoice_id,$purchase['client_id'],3500,'cs_fake_combined','pi_fake_combined','usd');
            checkoutSettlementAssert($result['applied_cents'] === 2500 && $result['excess_cents'] === 1000, 'stale package checkout preserves applied/excess amounts');
            $replay = bdta_invoice_record_checkout_payment($conn,$invoice_id,$purchase['client_id'],3500,'cs_fake_combined','pi_fake_combined','usd');
            checkoutSettlementAssert($replay['replayed'], 'confirmed package invoice return replays');
        }
        $stmt = $conn->prepare('SELECT COUNT(*) FROM client_packages WHERE client_id = ?'); $stmt->execute([$purchase['client_id']]);
        checkoutSettlementAssert(safe_int($stmt->fetchColumn()) === 1, $mode . ' settlement keeps the original package purchase exactly once');
        $stmt = $conn->prepare('SELECT SUM(total_credits) FROM client_package_credits WHERE client_id = ?'); $stmt->execute([$purchase['client_id']]);
        checkoutSettlementAssert(safe_int($stmt->fetchColumn()) === 3, $mode . ' settlement keeps credits exactly once');
        $stmt = $conn->prepare('SELECT COUNT(*) FROM invoice_package_fulfillments WHERE client_package_id = ?'); $stmt->execute([$purchase['client_package_id']]);
        checkoutSettlementAssert(safe_int($stmt->fetchColumn()) === 1, $mode . ' fulfillment marker identifies the original purchase');
        checkoutSettlementAssert(bdta_finalize_package_purchase($conn,$package,$items,'Synthetic buyer',$email,'','',null,[],null,'offline',null,null,$token) === $purchase, $mode . ' offline retry recovers the original purchase after settlement');
        if ($mode === 'changed-definition') {
            $conn->prepare('UPDATE packages SET is_active = 1 WHERE id = ?')->execute([$package_id]);
            $conn->prepare('INSERT INTO package_items(package_id,appointment_type_id,quantity) VALUES(?,?,3)')->execute([$package_id,$type_id]);
        }
    }
    $snapshot = [];
    foreach (['clients','client_packages','client_package_credits','package_credit_transactions','invoices','invoice_items','invoice_package_fulfillments'] as $table) {
        $snapshot[$table] = $conn->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    }
    $conn->exec("CREATE TRIGGER combined_marker_fault BEFORE INSERT ON invoice_package_fulfillments FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic marker interruption'");
    $fault_token = bin2hex(random_bytes(32));
    $failed = false;
    try { bdta_finalize_package_purchase($conn,$package,$items,'Synthetic rollback','rollback@example.test','','',null,[],null,'offline',null,null,$fault_token); }
    catch (PDOException $e) { $failed = str_contains($e->getMessage(),'Synthetic marker interruption'); }
    checkoutSettlementAssert($failed, 'checkout marker failure is surfaced');
    foreach ($snapshot as $table => $rows) checkoutSettlementAssert($conn->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC) === $rows, 'marker interruption rolls back ' . $table);
    $conn->exec('DROP TRIGGER combined_marker_fault');
    $recovered = bdta_finalize_package_purchase($conn,$package,$items,'Synthetic rollback','rollback@example.test','','',null,[],null,'offline',null,null,$fault_token);
    $stmt = $conn->prepare('SELECT COUNT(*) FROM invoice_package_fulfillments WHERE client_package_id = ?'); $stmt->execute([$recovered['client_package_id']]);
    checkoutSettlementAssert(safe_int($stmt->fetchColumn()) === 1, 'same checkout attempt recovers after marker interruption');
    echo 'Combined checkout settlement checks: ' . $checks . '; failures: 0.' . PHP_EOL;
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $server->exec("DROP DATABASE $schema");
}
