<?php
// Synthetic fixtures only. Never load application config/database bootstrap or providers.
function scalar_string($v): string { return is_scalar($v) ? (string) $v : ''; }
function safe_int($v): int { return (int) $v; }
function safe_float($v): float { return (float) $v; }
function safe_timestamp($v): int { return (int) $v; }
function array_int_value(array $r, $k, int $d = 0): int { return (int) ($r[$k] ?? $d); }
function assoc_row($v): array { return is_array($v) ? $v : []; }
function assoc_rows($v): array { return is_array($v) ? $v : []; }
function bdta_create_notification(...$args): void {}
require dirname(__DIR__) . '/backend/includes/invoice_payment.php';

function connection(string $mode, string $path = ''): PDO {
    if ($mode === 'mysql') {
        if (getenv('BDTA_MANUAL_DISPOSABLE') !== '1') { throw new RuntimeException('Disposable service opt-in required.'); }
        // Fixed loopback service, fixed synthetic password/name. No production env/config accepted.
        return new PDO('mysql:host=127.0.0.1;port=3307;dbname=bdta_manual_request_ci;charset=utf8mb4', 'root', 'synthetic-audit-fixture', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=15000');
    $db->exec('PRAGMA foreign_keys=ON');
    return $db;
}
function seed(PDO $db, string $mode): void {
    $auto = $mode === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $tail = $mode === 'mysql' ? ' ENGINE=InnoDB' : '';
    foreach ([
        "clients(id INT PRIMARY KEY, name VARCHAR(40), email VARCHAR(80))",
        "invoices(id INT PRIMARY KEY,invoice_number VARCHAR(40),client_id INT,total_amount DOUBLE,status VARCHAR(40),payment_method VARCHAR(40),payment_date DATE,stripe_payment_intent_id VARCHAR(255),updated_at TIMESTAMP NULL,receipt_sent_at TIMESTAMP NULL)",
        "invoice_items(id INT PRIMARY KEY,invoice_id INT,item_type VARCHAR(40),reference_id INT,quantity DOUBLE)",
        "invoice_payments(id $auto,invoice_id INT,amount DOUBLE,payment_date DATE,payment_method VARCHAR(40),stripe_payment_intent_id VARCHAR(255) UNIQUE)",
        "invoice_installments(id INT PRIMARY KEY,invoice_id INT,installment_number INT,amount DOUBLE,status VARCHAR(40),payment_method VARCHAR(40),payment_date DATE,due_date DATE,updated_at TIMESTAMP NULL,receipt_sent_at TIMESTAMP NULL)",
        "packages(id INT PRIMARY KEY,name VARCHAR(40),expiration_days INT,is_active INT)",
        "package_items(id INT PRIMARY KEY,package_id INT,appointment_type_id INT,quantity INT)",
        "client_packages(id $auto,client_id INT,package_id INT,package_name VARCHAR(40),expires_at DATETIME,is_active INT,notes TEXT,created_by INT)",
        "client_package_credits(id $auto,client_package_id INT,client_id INT,appointment_type_id INT,total_credits INT,used_credits INT)",
        "package_credit_transactions(id $auto,client_package_credit_id INT,client_id INT,appointment_type_id INT,transaction_type VARCHAR(40),amount INT,notes TEXT,created_by INT)",
        "invoice_package_fulfillments(invoice_item_id INT,unit_number INT,client_package_id INT,PRIMARY KEY(invoice_item_id,unit_number))"
    ] as $ddl) { $db->exec('CREATE TABLE ' . $ddl . $tail); }
    if ($mode === 'mysql') {
        $db->exec(file_get_contents(dirname(__DIR__) . '/migrations/invoice_manual_payment_requests.sql'));
    } else {
        $db->exec('CREATE TABLE invoice_manual_payment_requests(request_id CHAR(64) PRIMARY KEY,invoice_id INT NOT NULL,payload_hash CHAR(64) NOT NULL,amount_cents BIGINT NOT NULL,payment_id INT,result_json TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(invoice_id) REFERENCES invoices(id),FOREIGN KEY(payment_id) REFERENCES invoice_payments(id))');
    }
    $db->exec("INSERT INTO clients VALUES(1,'Synthetic','fixture@example.invalid')");
    for ($i = 1; $i <= 30; $i++) { $db->exec("INSERT INTO invoices(id,invoice_number,client_id,total_amount,status) VALUES($i,'SYN-$i',1,100,'sent')"); }
    $db->exec("INSERT INTO packages VALUES(1,'Synthetic',0,1)");
    $db->exec('INSERT INTO package_items VALUES(1,1,1,5)');
}
function pay(PDO $db, int $invoice, string $key, float $amount = 40, string $method = 'cash', string $date = '2026-10-27', ?int $admin = 1, int $installment = 0): array {
    return bdta_invoice_record_manual_payment($db, $invoice, $amount, $method, $date, $admin, $installment, $key);
}
function countRows(PDO $db, string $table): int { return (int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(); }
$checks = 0;
function check(bool $ok, string $message): void { global $checks; if (!$ok) { throw new RuntimeException($message); } $checks++; }
function reject(callable $f): void { $failed = false; try { $f(); } catch (Throwable $e) { $failed = true; } check($failed, 'Expected rejection'); }
function keyFor(int $n): string { return hash('sha256', 'synthetic-form-' . $n); }
function spawn(array $args) {
    $pipes = [];
    $process = proc_open(array_merge([PHP_BINARY, __FILE__], $args), [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Worker failed to start'); }
    fclose($pipes[0]); return [$process, $pipes];
}
function finishWorker(array $worker): array {
    [$process, $pipes] = $worker;
    $out = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) { throw new RuntimeException('Worker failed: ' . $error . $out); }
    return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}
if (defined('BDTA_MANUAL_FIXTURE_ONLY')) { return; }
$mode = $argv[1] ?? 'sqlite';
if ($mode === 'worker') {
    try {
        $db = connection($argv[2], $argv[3]);
        if (isset($argv[7])) { file_put_contents($argv[7], 'ready'); }
        $r = pay($db, (int)$argv[4], $argv[5], (float)$argv[6]);
        echo json_encode(['ok' => true, 'replayed' => $r['replayed']], JSON_THROW_ON_ERROR);
    } catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => get_class($e)], JSON_THROW_ON_ERROR); }
    exit;
}
if (!in_array($mode, ['sqlite','mysql'], true)) { exit(2); }
$adminConnection = null;
$createdDatabase = false;
$path = $mode === 'sqlite' ? tempnam(sys_get_temp_dir(), 'bdta-manual-synthetic-') : '';
try {
    if ($mode === 'mysql') {
        if (getenv('BDTA_MANUAL_DISPOSABLE') !== '1') { throw new RuntimeException('Disposable service opt-in required.'); }
        $adminConnection = new PDO('mysql:host=127.0.0.1;port=3307', 'root', 'synthetic-audit-fixture', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Refuse an existing database instead of destroying/reusing unknown data.
        $adminConnection->exec('CREATE DATABASE bdta_manual_request_ci CHARACTER SET utf8mb4');
        $createdDatabase = true;
    }
    $db = connection($mode, $path); seed($db, $mode);
    if ($mode==='mysql') {
        check((string)$db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='bdta_manual_request_ci' AND TABLE_NAME='invoices' AND COLUMN_NAME='id'")->fetchColumn()==='int(11)','Signed live-compatible invoice key');
        check((int)$db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='bdta_manual_request_ci' AND ENGINE<>'InnoDB'")->fetchColumn()===0,'All fixture/migration tables transactional');
        check((string)$db->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='bdta_manual_request_ci' AND TABLE_NAME='invoice_manual_payment_requests' AND COLUMN_NAME='request_id'")->fetchColumn()==='ascii_bin','Request identity exact binary collation');
    }
    $key = keyFor(1); $a = pay($db, 1, $key); $b = pay($db, 1, $key);
    check(!$a['replayed'] && $b['replayed'], 'Partial same-form replay recognized');
    check($a['invoice'] === $b['invoice'], 'Original result survives lost acknowledgement');
    check(countRows($db, 'invoice_payments') === 1, 'Partial replay does not record eighty dollars');
    foreach ([fn()=>pay($db,1,$key,41),fn()=>pay($db,1,$key,40,'check'),fn()=>pay($db,1,$key,40,'cash','2026-10-28'),fn()=>pay($db,1,$key,40,'cash','2026-10-27',2),fn()=>pay($db,1,$key,40,'cash','2026-10-27',1,1),fn()=>pay($db,2,$key)] as $bad) { reject($bad); }
    check(countRows($db,'invoice_payments')===1 && countRows($db,'invoice_manual_payment_requests')===1, 'All conflicts preserve ledger and request');
    pay($db,1,keyFor(2),20); $b=pay($db,1,$key);
    check($b['invoice']===$a['invoice'], 'Replay returns original result after a later legitimate payment');
    pay($db,1,keyFor(3),40); $b=pay($db,1,keyFor(3),40);
    check($b['replayed'] && $b['invoice']['status']==='paid', 'Completed payment replay precedes closed-state check');
    reject(fn()=>pay($db,1,keyFor(4),1));
    $db->exec("INSERT INTO invoice_installments(id,invoice_id,installment_number,amount,status) VALUES(1,3,1,100,'unpaid')");
    reject(fn()=>pay($db,3,keyFor(5),40,'cash','2026-10-27',1,1));
    $a=pay($db,3,keyFor(5),100,'cash','2026-10-27',1,1); $b=pay($db,3,keyFor(5),100,'cash','2026-10-27',1,1);
    check(!$a['replayed']&&$b['replayed']&&$b['installment']['status']==='paid', 'Installment replay retains original outcome');
    // Failure after request+payment INSERT: fulfillment throws and all writes must roll back.
    $db->exec("INSERT INTO invoice_items VALUES(1,4,'package',99,1)");
    $before=[countRows($db,'invoice_payments'),countRows($db,'invoice_manual_payment_requests')];
    reject(fn()=>pay($db,4,keyFor(6),100));
    check($before===[countRows($db,'invoice_payments'),countRows($db,'invoice_manual_payment_requests')]&&!$db->inTransaction(), 'Failure rolls request and payment back together');
    $db->exec('UPDATE invoice_items SET reference_id=1 WHERE id=1');
    pay($db,4,keyFor(6),100); pay($db,4,keyFor(6),100);
    check(countRows($db,'client_packages')===1 && countRows($db,'package_credit_transactions')===1, 'Same-key retry after rollback grants exactly once');
    // Fail the final durable outcome UPDATE after the payment and invoice status writes.
    $db->exec($mode==='mysql'
        ? "CREATE TRIGGER synthetic_outcome_failure BEFORE UPDATE ON invoice_manual_payment_requests FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic outcome failure'"
        : "CREATE TRIGGER synthetic_outcome_failure BEFORE UPDATE ON invoice_manual_payment_requests BEGIN SELECT RAISE(ABORT,'synthetic outcome failure'); END");
    $before=[countRows($db,'invoice_payments'),countRows($db,'invoice_manual_payment_requests')];
    reject(fn()=>pay($db,12,keyFor(20),40));
    check($before===[countRows($db,'invoice_payments'),countRows($db,'invoice_manual_payment_requests')]&&$db->query('SELECT status FROM invoices WHERE id=12')->fetchColumn()==='sent','Outcome failure rolls financial changes back');
    $db->exec('DROP TRIGGER synthetic_outcome_failure');
    $a=pay($db,12,keyFor(20),40);$b=pay($db,12,keyFor(20),40);
    check(!$a['replayed']&&$b['replayed'],'Retry after outcome persistence failure succeeds once');
    // Independent concurrent processes prove locking on the selected database engine.
    foreach ([['same',5,keyFor(7),keyFor(7),40,40],['conflict',6,keyFor(8),keyFor(8),40,41],['different',7,keyFor(9),keyFor(10),40,40],['cross-invoice',8,keyFor(11),keyFor(11),40,40]] as [$case,$invoice,$k1,$k2,$v1,$v2]) {
        $secondInvoice=$case==='cross-invoice'?9:$invoice;
        $ready1=tempnam(sys_get_temp_dir(),'bdta-ready-');$ready2=tempnam(sys_get_temp_dir(),'bdta-ready-');
        $db->beginTransaction();
        bdta_invoice_lock_for_payment($db,$invoice);
        if($secondInvoice!==$invoice) { bdta_invoice_lock_for_payment($db,$secondInvoice); }
        $w1=spawn(['worker',$mode,$path,(string)$invoice,$k1,(string)$v1,$ready1]);
        $w2=spawn(['worker',$mode,$path,(string)$secondInvoice,$k2,(string)$v2,$ready2]);
        $deadline=microtime(true)+8;
        do { clearstatcache();$ready=filesize($ready1)>0&&filesize($ready2)>0;if(!$ready)usleep(10000); } while(!$ready&&microtime(true)<$deadline);
        $db->commit();
        check($ready,'Both concurrent connections ready behind parent invoice lock');
        unlink($ready1);unlink($ready2);
        $r1=finishWorker($w1);$r2=finishWorker($w2);
        $ok=(int)$r1['ok']+(int)$r2['ok'];
        check($ok===($case==='conflict'||$case==='cross-invoice'?1:2), $case.' concurrent outcomes');
        if($case==='same') { check((int)$r1['replayed']+(int)$r2['replayed']===1,'One concurrent winner, one replay'); }
        $sum=(float)$db->query('SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id IN ('.$invoice.','.$secondInvoice.')')->fetchColumn();
        check($case==='conflict'?in_array($sum,[40.0,41.0],true):$sum===($case==='different'?80.0:40.0),$case.' concurrent ledger total');
    }
    foreach (['40x','4e1','40.001','-1','0','','NaN'] as $bad) { reject(fn()=>bdta_manual_payment_cents($bad)); }
    check(bdta_manual_payment_cents('40.01')===4001 && bdta_manual_payment_cents('040.1')===4010,'Canonical cents parser');
    foreach ([NAN,INF,-1,40.001] as $bad) { reject(fn()=>pay($db,10,keyFor(12),$bad)); }
    reject(fn()=>pay($db,10,keyFor(12),40,'cash','2026-02-30'));
    $session=[];$token=bdta_manual_payment_form_token($session,1,0,1);$other=bdta_manual_payment_form_token($session,1,0,1);
    check($token!==$other,'Different tabs get different identities');
    $id=bdta_manual_payment_request_id($session,$token,1,0,1);
    check($id===bdta_manual_payment_request_id($session,$token,1,0,1),'Same form identity retained');
    foreach ([fn()=>bdta_manual_payment_request_id([], $token,1,0,1),fn()=>bdta_manual_payment_request_id($session,$token,2,0,1),fn()=>bdta_manual_payment_request_id($session,$token,1,1,1),fn()=>bdta_manual_payment_request_id($session,$token,1,0,2),fn()=>bdta_manual_payment_request_id($session,$id.'.'.str_repeat('0',64),1,0,1)] as $bad) { reject($bad); }
    // Missing migration must not allow protected HTTP writes to proceed silently.
    $db->exec('DROP TABLE invoice_manual_payment_requests');$before=countRows($db,'invoice_payments');
    reject(fn()=>pay($db,10,keyFor(12)));
    check(countRows($db,'invoice_payments')===$before&&!$db->inTransaction(),'Missing request schema fails atomically');
    echo json_encode(['engine'=>$mode,'checks'=>$checks,'failures'=>0,'synthetic_only'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    $db=null;
    if ($adminConnection !== null && $createdDatabase) { $adminConnection->exec('DROP DATABASE bdta_manual_request_ci'); }
    if ($mode==='sqlite' && is_string($path) && is_file($path)) { unlink($path); }
}
