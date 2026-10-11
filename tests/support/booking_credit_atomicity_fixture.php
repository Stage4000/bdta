<?php
/** CLI-only controller worker for disposable booking/credit transaction tests. */
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--worker') {
    throw new RuntimeException('Synthetic controller worker only.');
}
$schema = $argv[2] ?? '';
$port = getenv('BDTA_CREDIT_TEST_PORT');
$user = getenv('BDTA_CREDIT_TEST_USER');
if (preg_match('/^bdta_test_credit_[a-f0-9]{12}$/', $schema) !== 1 || $port === false
    || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535 || $user === false || $user === '') {
    throw new RuntimeException('Generated schema and disposable loopback configuration required.');
}
/** @var array{mode: string, client_id: int, data: array<string, mixed>, fault: string, gate: bool, gate_key: string} $request */
$request = json_decode($argv[3] ?? '', true, 512, JSON_THROW_ON_ERROR);
$password = getenv('BDTA_CREDIT_TEST_PASSWORD');
$password = $password === false ? '' : $password;
putenv('DB_TYPE=mysql');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=' . $port);
putenv('DB_NAME=' . $schema);
putenv('DB_USER=' . $user);
putenv('DB_PASSWORD=' . $password);
define('BDTA_TEST_MODE', true);
require_once dirname(__DIR__, 2) . '/backend/includes/settings.php';
putenv('DB_PASSWORD=' . $password);

class CreditAtomicityFixturePDO extends SafePDO {
    public string $fault = '';
    /** @var list<string> */
    public array $gates = [];
    public string $gatePath = '';

    public function beforeExecute(string $sql): void {
        $matches = match ($this->fault) {
            'debit' => preg_match('/UPDATE\s+client_package_credits[\s\S]*used_credits\s*=\s*used_credits\s*\+\s*1/i', $sql) === 1,
            'consume_ledger' => preg_match("/INSERT\\s+INTO\\s+package_credit_transactions[\\s\\S]*'consume'/i", $sql) === 1,
            'refund_ledger' => preg_match("/INSERT\\s+INTO\\s+package_credit_transactions[\\s\\S]*'refund'/i", $sql) === 1,
            'cancel_log' => preg_match('/INSERT\s+INTO\s+booking_change_log/i', $sql) === 1,
            'pet_link' => preg_match('/INSERT\s+INTO\s+appointment_pets/i', $sql) === 1,
            'invoice_item' => preg_match('/INSERT\s+INTO\s+invoice_items/i', $sql) === 1,
            default => false,
        };
        if ($matches) {
            $this->fault = '';
            throw new PDOException('Synthetic persistence interruption.');
        }
    }

    public function afterRead(string $sql): void {
        // Never hold a database transaction/row lock while waiting at a test gate.
        if ($this->inTransaction() || $this->gates === []) {
            return;
        }
        $phase = $this->gates[0];
        $matches = match ($phase) {
            'credit' => preg_match('/SELECT\s+cpc\.id[\s\S]*FROM\s+client_package_credits/i', $sql) === 1,
            'booking' => preg_match('/SELECT\s+b\.\*[\s\S]*FROM\s+bookings\s+b/i', $sql) === 1,
            'refund' => preg_match("/SELECT\\s+COUNT\\(\\*\\)[\\s\\S]*package_credit_transactions[\\s\\S]*'refund'/i", $sql) === 1,
            default => false,
        };
        if ($matches) {
            $this->waitAtGate();
        }
    }

    private function waitAtGate(): void {
        $phase = array_shift($this->gates);
        file_put_contents($this->gatePath . '.ready', $phase);
        $deadline = microtime(true) + 20;
        while (!is_file($this->gatePath . '.go') || file_get_contents($this->gatePath . '.go') !== $phase) {
            if (microtime(true) >= $deadline) { throw new RuntimeException('Synthetic concurrency gate timed out.'); }
            usleep(10000);
        }
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- generated marker in this fixture's private temporary directory.
        unlink($this->gatePath . '.ready');
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- generated marker in this fixture's private temporary directory.
        unlink($this->gatePath . '.go');
    }

    public function beginTransaction(): bool {
        return parent::beginTransaction();
    }
}

class CreditAtomicityFixtureStatement extends SafePDOStatement {
    protected function __construct(private CreditAtomicityFixturePDO $fixture) {
    }
    /** @param array<mixed>|null $params */
    public function execute(?array $params = null): bool {
        $this->fixture->beforeExecute($this->queryString);
        return parent::execute($params);
    }
    /** @template TMode of int
     * @param TMode $mode
     * @return (TMode is PDO::FETCH_COLUMN ? string|false : array<string, string>|false)
     */
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        $result = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        $this->fixture->afterRead($this->queryString);
        return $result;
    }
    public function fetchColumn(int $column = 0): mixed {
        $result = parent::fetchColumn($column);
        $this->fixture->afterRead($this->queryString);
        return $result;
    }
}

class CreditAtomicityFakeMail {
    public static int $calls = 0;
    public static bool $throws = false;
}
// The worker disables the real built-in and all socket/curl/process transports.
if (!function_exists('mail')) {
    function mail(string $to, string $subject, string $message, mixed $headers = '', string $parameters = ''): bool {
        CreditAtomicityFakeMail::$calls++;
        if (CreditAtomicityFakeMail::$throws) { throw new RuntimeException('Synthetic mail provider failure.'); }
        return false;
    }
} else {
    throw new LogicException('Synthetic worker requires the real mail transport to be disabled.');
}

class CreditAtomicityInput {
    /** @var resource|null */
    public $context;
    public static string $payload = '';
    private int $position = 0;
    /** @param string|null $openedPath */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
        return $path === 'php://input' && $mode === 'rb';
    }
    public function stream_read(int $count): string {
        $chunk = substr(self::$payload, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }
    public function stream_eof(): bool {
        return $this->position >= strlen(self::$payload);
    }
    /** @return array<string, int> */
    public function stream_stat(): array {
        return ['size' => strlen(self::$payload)];
    }
}

$conn = new CreditAtomicityFixturePDO("mysql:host=127.0.0.1;port={$port};dbname={$schema};charset=utf8mb4", $user, $password);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CreditAtomicityFixtureStatement::class, [$conn]]);
$conn->fault = $request['fault'];
CreditAtomicityFakeMail::$throws = $request['fault'] === 'mail';
$conn->gates = !$request['gate'] ? [] : ($request['mode'] === 'cancel' ? ['booking', 'refund'] : ['credit']);
(new ReflectionProperty(Database::class, 'sharedConnection'))->setValue(null, $conn);
Settings::seedCacheForTesting(['timezone' => 'UTC', 'smtp_host' => '', 'smtp_username' => '', 'smtp_password' => '',
    'smtp_enabled' => false, 'google_calendar_enabled' => false, 'stripe_enabled' => false, 'turnstile_enabled' => false]);
$sessionDirectory = getenv('BDTA_CREDIT_TEST_SESSION_DIRECTORY');
if ($sessionDirectory === false || !is_dir($sessionDirectory)) {
    throw new RuntimeException('Private synthetic session directory required.');
}
$gateKey = $request['gate_key'];
if ($request['gate'] && preg_match('/^[a-f0-9]{16}$/', $gateKey) !== 1) {
    throw new RuntimeException('Generated private gate key required.');
}
$conn->gatePath = $sessionDirectory . DIRECTORY_SEPARATOR . 'gate_' . $gateKey;
ini_set('session.save_path', $sessionDirectory);
session_start();
$_SESSION = ['portal_client_id' => $request['client_id'], 'csrf_token' => str_repeat('a', 64)];
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/portal/api_book_credit.php';
$_SERVER['REQUEST_METHOD'] = $request['mode'] === 'public_book' ? 'CLI' : 'POST';
class CreditAtomicityWorkerResult {
    /** @var array<string, mixed>|null */
    public static ?array $result = null;
    public static ?string $failure = null;
}
ob_start();
register_shutdown_function(static function () use ($conn): void {
    $body = (string) ob_get_clean();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    echo json_encode(['result' => CreditAtomicityWorkerResult::$result ?? json_decode($body, true), 'failure' => CreditAtomicityWorkerResult::$failure,
        'mail_calls' => CreditAtomicityFakeMail::$calls], JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($conn->gatePath . '.done', 'done');
});
try {
    if ($request['mode'] === 'public_book') {
        chdir(dirname(__DIR__, 2) . '/backend/public');
        require dirname(__DIR__, 2) . '/backend/public/api_bookings.php';
        CreditAtomicityWorkerResult::$result = api_booking_create_booking($conn, $request['data']);
    } else {
        CreditAtomicityInput::$payload = json_encode($request['data'], JSON_THROW_ON_ERROR);
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', CreditAtomicityInput::class);
        chdir(dirname(__DIR__, 2) . '/portal');
        require dirname(__DIR__, 2) . '/portal/' . ($request['mode'] === 'cancel' ? 'api_appointments.php' : 'api_book_credit.php');
    }
} catch (Throwable $e) {
    CreditAtomicityWorkerResult::$failure = $e->getMessage();
}
