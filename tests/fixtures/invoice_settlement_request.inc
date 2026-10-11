<?php
$root = getenv('BDTA_INVOICE_TEST_SOURCE') ?: dirname(__DIR__, 2);
define('BDTA_TEST_MODE', true);
require_once $root . '/backend/includes/settings.php';
Settings::seedCacheForTesting(['timezone' => 'UTC', 'stripe_enabled' => true, 'stripe_mode' => 'test',
    'stripe_test_secret_key' => 'synthetic-no-network', 'stripe_test_publishable_key' => 'synthetic-no-network']);
session_save_path(sys_get_temp_dir());
session_start();
require_once $root . '/backend/includes/config.php';
$conn = new SafePDO($invoice_fixture_dsn ?? 'sqlite::memory:', getenv('BDTA_INVOICE_TEST_MYSQL') === '1' ? getenv('DB_USER') : null,
    getenv('BDTA_INVOICE_TEST_MYSQL') === '1' ? getenv('DB_PASSWORD') : null);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);
$ddl = [
    'settings (setting_key TEXT, setting_value TEXT, setting_type TEXT)',
    'clients (id INTEGER PRIMARY KEY, name TEXT, email TEXT)',
    'admin_users (id INTEGER PRIMARY KEY)',
    'invoices (id INTEGER PRIMARY KEY, client_id INTEGER, invoice_number TEXT, total_amount REAL, status TEXT, pay_token TEXT, payment_method TEXT, payment_date TEXT, stripe_payment_intent_id TEXT, updated_at TEXT, receipt_sent_at TEXT)',
    'invoice_items (id INTEGER PRIMARY KEY, invoice_id INTEGER, item_type TEXT, reference_id INTEGER, quantity INTEGER, description TEXT, rate REAL, amount REAL)',
    'invoice_payments (id INTEGER PRIMARY KEY, invoice_id INTEGER, amount REAL, payment_date TEXT, payment_method TEXT, stripe_payment_intent_id TEXT UNIQUE, notes TEXT)',
    'invoice_installments (id INTEGER PRIMARY KEY, invoice_id INTEGER, amount REAL, status TEXT, installment_number INTEGER, payment_method TEXT, payment_date TEXT, updated_at TEXT, receipt_sent_at TEXT)',
    'packages (id INTEGER PRIMARY KEY, name TEXT, expiration_days INTEGER, is_active INTEGER)',
    'package_items (id INTEGER PRIMARY KEY, package_id INTEGER, appointment_type_id INTEGER, quantity INTEGER)',
    'client_packages (id INTEGER PRIMARY KEY, client_id INTEGER, package_id INTEGER, package_name TEXT, expires_at TEXT, is_active INTEGER, notes TEXT, created_by INTEGER)',
    'client_package_credits (id INTEGER PRIMARY KEY, client_package_id INTEGER, client_id INTEGER, appointment_type_id INTEGER, total_credits INTEGER, used_credits INTEGER)',
    'package_credit_transactions (id INTEGER PRIMARY KEY, client_package_credit_id INTEGER, client_id INTEGER, appointment_type_id INTEGER, transaction_type TEXT, amount INTEGER, notes TEXT, created_by INTEGER)',
    'notifications (id INTEGER PRIMARY KEY, audience TEXT, recipient_id INTEGER, entity_type TEXT, entity_id INTEGER, title TEXT, message TEXT, url TEXT, is_read INTEGER, created_at TEXT, deleted_at TEXT)',
    'invoice_checkout_receipts (payment_intent_id VARCHAR(255) PRIMARY KEY, checkout_session_id VARCHAR(255) UNIQUE, invoice_id INTEGER, received_cents INTEGER, applied_cents INTEGER, excess_cents INTEGER, currency VARCHAR(10), created_at TEXT)',
    'invoice_package_fulfillments (invoice_item_id INTEGER, unit_number INTEGER, client_package_id INTEGER, PRIMARY KEY(invoice_item_id, unit_number))',
];
foreach ($ddl as $table) {
    if ($conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $table = str_replace(['INTEGER PRIMARY KEY', 'stripe_payment_intent_id TEXT UNIQUE'], ['INT AUTO_INCREMENT PRIMARY KEY', 'stripe_payment_intent_id VARCHAR(255) UNIQUE'], $table);
    }
    $conn->exec('CREATE TABLE ' . $table . ($conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB' : ''));
}
$conn->exec("INSERT INTO clients VALUES (1, 'Synthetic buyer', '')");
$conn->exec('INSERT INTO admin_users VALUES (1)');
$conn->exec("INSERT INTO invoices (id, client_id, invoice_number, total_amount, status, pay_token) VALUES (1, 1, 'SYN-100', 100, 'sent', 'synthetic-token')");
$conn->exec("INSERT INTO invoice_items VALUES (1, 1, 'package', 1, 1, 'Synthetic package', 100, 100)");
$conn->exec("INSERT INTO packages VALUES (1, 'Synthetic package', 30, 1)");
$conn->exec('INSERT INTO package_items VALUES (1, 1, 1, 5)');
if ($case === 'stale-cash') { $conn->exec("INSERT INTO invoice_payments (invoice_id, amount, payment_date, payment_method) VALUES (1, 40, CURRENT_DATE, 'cash')"); }
if ($case === 'already-paid') {
    $conn->exec("INSERT INTO invoice_payments (invoice_id, amount, payment_date, payment_method) VALUES (1, 100, CURRENT_DATE, 'cash')");
    $conn->exec("UPDATE invoices SET status = 'paid'");
}
if ($case === 'manual-final') { $conn->exec("INSERT INTO invoice_payments (invoice_id, amount, payment_date, payment_method) VALUES (1, 40, CURRENT_DATE, 'cash')"); }
if ($case === 'installment-final') {
    $conn->exec("INSERT INTO invoice_installments (id, invoice_id, amount, status, installment_number) VALUES (1, 1, 40, 'paid', 1), (2, 1, 60, 'unpaid', 2)");
}
if ($case === 'rollback-credits') { $conn->exec("CREATE TRIGGER fail_credit BEFORE INSERT ON client_package_credits BEGIN SELECT RAISE(ABORT, 'Synthetic interrupted fulfillment'); END"); }
(new ReflectionProperty(Database::class, 'sharedConnection'))->setValue(null, $conn);
$_SESSION = ['csrf_token' => 'synthetic-csrf', 'admin_id' => 1, 'admin_account_type' => 'main'];
$_GET = ['token' => 'synthetic-token', 'session_id' => 'cs_synthetic'];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
if (defined('BDTA_INVOICE_FIXTURE_ONLY')) {
    return;
}
$manual = str_starts_with($case, 'manual-') || $case === 'installment-final';
if ($manual) {
    $_GET = ['id' => '1'];
    if ($case === 'installment-final') { $_GET['installment_id'] = '2'; }
    $_POST = ['csrf_token' => 'synthetic-csrf', 'payment_method' => 'cash', 'payment_date' => date('Y-m-d'), 'payment_amount' => $case === 'manual-partial' ? '40' : '60'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
}
// Disabled native cURL functions are replaced in this child process; unknown URLs fail closed.
if (!defined('BDTA_INVOICE_FIXTURE_ONLY')) {
class InvoiceFakeCurl { public string $url; public function __construct(string $url) { $this->url = $url; } }
function curl_init(?string $url = null): InvoiceFakeCurl { return new InvoiceFakeCurl($url ?? ''); }
function curl_setopt_array(InvoiceFakeCurl $curl, array $options): bool { return true; }
function curl_exec(InvoiceFakeCurl $curl): string {
    global $case;
    if ($curl->url !== 'https://api.stripe.com/v1/checkout/sessions/cs_synthetic') { throw new RuntimeException('Local stub rejected endpoint'); }
    $amount = $case === 'partial' ? 4000 : 10000;
    return json_encode(['id' => 'cs_synthetic', 'payment_status' => $case === 'unpaid' ? 'unpaid' : 'paid',
        'payment_intent' => 'pi_synthetic', 'amount_total' => $amount, 'currency' => 'usd',
        'metadata' => ['invoice_id' => $case === 'wrong-invoice' ? '2' : '1', 'client_id' => $case === 'wrong-client' ? '2' : '1',
            'payment_amount_cents' => $case === 'wrong-amount' ? 9999 : $amount]], JSON_THROW_ON_ERROR);
}
function curl_getinfo(InvoiceFakeCurl $curl, ?int $option = null): int { return 200; }
function curl_close(InvoiceFakeCurl $curl): void {}
}
foreach (['CURLOPT_RETURNTRANSFER', 'CURLOPT_USERPWD', 'CURLINFO_HTTP_CODE'] as $index => $name) { if (!defined($name)) { define($name, 1000 + $index); } }
ob_start();
register_shutdown_function(static function () use ($conn, $case): void {
    ob_end_clean();
    $failures = [];
    $check = static function (bool $ok, string $message) use (&$failures): void { if (!$ok) { $failures[] = $message; } };
    $rejected = in_array($case, ['unpaid', 'wrong-invoice', 'wrong-client', 'wrong-amount', 'rollback-credits'], true);
    $partial = in_array($case, ['partial', 'manual-partial'], true);
    $expected_total = $rejected ? 0 : ($partial ? 40 : 100);
    $check((float) $conn->query('SELECT COALESCE(SUM(amount), 0) FROM invoice_payments')->fetchColumn() === (float) ($case === 'installment-final' ? 0 : $expected_total), 'applied invoice payments must not exceed current balance');
    $check((int) $conn->query('SELECT COUNT(*) FROM client_packages')->fetchColumn() === ($rejected || $partial || $case === 'already-paid' ? 0 : 1), 'paid package invoice must fulfill exactly once');
    $check((int) $conn->query('SELECT COALESCE(SUM(total_credits), 0) FROM client_package_credits')->fetchColumn() === ($rejected || $partial || $case === 'already-paid' ? 0 : 5), 'package credits must be complete');
    $check($conn->query('SELECT status FROM invoices')->fetchColumn() === ($rejected ? 'sent' : ($partial ? 'partial' : 'paid')), 'invoice status must match committed payment');
    if ($case === 'stale-cash' || $case === 'already-paid') {
        $check((int) $conn->query('SELECT received_cents FROM invoice_checkout_receipts')->fetchColumn() === 10000, 'full provider receipt must be durable');
        $check((int) $conn->query('SELECT excess_cents FROM invoice_checkout_receipts')->fetchColumn() === ($case === 'stale-cash' ? 4000 : 10000), 'excess must be retained for reconciliation');
        $check((int) $conn->query("SELECT COUNT(*) FROM notifications WHERE title = 'Invoice payment needs reconciliation'")->fetchColumn() === 1, 'admin must see reconciliation notice');
    }
    session_destroy();
    if ($failures !== []) { fwrite(STDERR, 'FAIL ' . $case . ': ' . implode('; ', $failures) . "\n"); exit(1); }
    echo 'PASS ' . $case . "\n";
});
chdir($root . ($manual ? '/client' : '/portal'));
require $manual ? 'invoices_payment.php' : 'invoice_pay_return.php';
