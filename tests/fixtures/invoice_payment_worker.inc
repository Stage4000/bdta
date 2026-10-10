<?php
define('BDTA_TEST_MODE', true);
require_once dirname(__DIR__, 2) . '/backend/includes/settings.php';
Settings::seedCacheForTesting(['timezone' => 'UTC']);
$dsn = $argv[2] ?? '';
$operation = $argv[3] ?? '';
if (str_starts_with($dsn, 'mysql:') && (getenv('DB_HOST') !== '127.0.0.1' || preg_match('/dbname=bdta_invoice_test_concurrency_[a-z0-9_]+$/', $dsn) !== 1)) { throw new RuntimeException('Unexpected concurrency database.'); }
$conn = new SafePDO($dsn, str_starts_with($dsn, 'mysql:') ? getenv('DB_USER') : null, str_starts_with($dsn, 'mysql:') ? getenv('DB_PASSWORD') : null);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);
$conn->exec(str_starts_with($dsn, 'mysql:') ? 'SET innodb_lock_wait_timeout = 10' : 'PRAGMA busy_timeout = 10000');
(new ReflectionProperty(Database::class, 'sharedConnection'))->setValue(null, $conn);
session_save_path(sys_get_temp_dir()); session_start();
require_once dirname(__DIR__, 2) . '/backend/includes/config.php';
require_once dirname(__DIR__, 2) . '/backend/includes/invoice_payment.php';
fwrite(STDOUT, "ready\n"); fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'go') { throw new RuntimeException('Missing concurrency barrier.'); }
try {
    if ($operation === 'manual') {
        try { bdta_invoice_record_manual_payment($conn, 1, 60, 'cash', date('Y-m-d'), 1); }
        catch (RuntimeException $e) { if (!str_contains($e->getMessage(), 'remaining balance') && !str_contains($e->getMessage(), 'no longer accepts')) { throw $e; } }
    } else {
        if (!in_array($operation, ['same', 'first', 'second', 'online'], true)) { throw new RuntimeException('Unknown payment worker operation.'); }
        bdta_invoice_record_checkout_payment($conn, 1, 1, 10000, 'cs_' . $operation, 'pi_' . $operation, 'usd');
    }
} finally { session_destroy(); }
