#!/usr/bin/env php
<?php
/**
 * Real MySQL/MariaDB bootstrap regression using newly created disposable schemas.
 * Opt in with BDTA_MIGRATION_TEST_PORT and BDTA_MIGRATION_TEST_USER on loopback.
 * No application DB configuration or existing schema is used.
 */

$port = getenv('BDTA_MIGRATION_TEST_PORT');
$user = getenv('BDTA_MIGRATION_TEST_USER');
if ($port === false || $user === false) {
    echo "SKIP: set BDTA_MIGRATION_TEST_PORT and BDTA_MIGRATION_TEST_USER for a disposable loopback server.\n";
    exit(0);
}
if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535 || $user === '') {
    throw new RuntimeException('Invalid disposable database test configuration.');
}
$password = getenv('BDTA_MIGRATION_TEST_PASSWORD');
$password = $password === false ? '' : $password;
$server = new PDO("mysql:host=127.0.0.1;port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

putenv('DB_TYPE=mysql');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=' . $port);
putenv('DB_USER=' . $user);
putenv('DB_PASSWORD=' . $password);
require_once dirname(__DIR__) . '/backend/includes/database.php';
// EnvLoader treats an empty value as unset; retain the opted-in test password.
putenv('DB_PASSWORD=' . $password);

function assertPackagePreservation(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function clearPackageTestConnection(): void {
    (new ReflectionProperty(Database::class, 'sharedConnection'))->setValue(null, null);
}

class FailingPackageMetadataPDO extends SafePDO {
    /** @param array<mixed> $options */
    public function prepare(string $query, array $options = []): SafePDOStatement {
        if (strpos($query, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
            throw new PDOException('Synthetic package metadata read failure');
        }
        return parent::prepare($query, $options);
    }
}

/** @return array<string, mixed> */
function packageSnapshot(SafePDO $conn): array {
    $result = [];
    foreach (['package_items', 'client_packages', 'client_package_credits', 'package_credit_transactions', 'bookings', 'invoices'] as $table) {
        $result[$table] = $conn->query("SELECT * FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $result[$table . '_ddl'] = $conn->query("SHOW CREATE TABLE {$table}")->fetch(PDO::FETCH_NUM);
    }
    return $result;
}

function assertLegacyBootstrapBlocked(SafePDO $conn, string $table): void {
    $before = packageSnapshot($conn);
    clearPackageTestConnection();
    $blocked = false;
    try {
        new Database();
    } catch (RuntimeException $e) {
        $blocked = strpos($e->getMessage(), 'Legacy package schema') !== false
            && strpos($e->getMessage(), $table) !== false
            && strpos($e->getMessage(), 'backend/MYSQL_MIGRATION.md') !== false;
    }
    assertPackagePreservation(packageSnapshot($conn) === $before, 'Bootstrap must preserve every package/history row, link and table definition.');
    assertPackagePreservation($blocked, 'Legacy bootstrap must stop with an actionable migration error for ' . $table . '.');
    // Preflight must run before unrelated schema writes as well.
    assertPackagePreservation($conn->query("SHOW TABLES LIKE 'app_sessions'")->fetchColumn() === false, 'Blocked bootstrap must not recreate unrelated tables.');
}

function seedPackageHistory(PDO $conn, bool $legacy): void {
    $conn->exec("INSERT INTO clients (id, name, email) VALUES (71, 'Synthetic Legacy Client', 'legacy@example.test')");
    $conn->exec("INSERT INTO appointment_types (id, name) VALUES (72, 'Synthetic Private Session')");
    $conn->exec("INSERT INTO packages (id, name, price) VALUES (73, 'Synthetic Legacy Package', 125)");
    $conn->exec("INSERT INTO bookings (id, client_name, client_email, service_type, appointment_date, appointment_time, package_credit_id) VALUES (74, 'Synthetic Legacy Client', 'legacy@example.test', 'Synthetic Private Session', '2026-10-20', '10:00:00', 77)");
    $conn->exec("INSERT INTO invoices (id, invoice_number, client_id, issue_date, due_date, subtotal, total_amount, notes) VALUES (75, 'SYNTHETIC-LEGACY-1', 71, '2026-10-01', '2026-10-15', 125, 125, 'Package purchase #76')");
    $conn->exec("INSERT INTO client_packages (id, client_id, package_id, package_name, purchased_at, expires_at, notes, created_by) VALUES (76, 71, 73, 'Historical package name', '2026-10-01 12:00:00', '2026-12-01 12:00:00', 'Preserve purchase notes', 1)");
    $column = $legacy ? 'session_type' : 'appointment_type_id';
    $value = $legacy ? "'private'" : '72';
    $conn->exec("INSERT INTO package_items (id, package_id, {$column}, quantity) VALUES (78, 73, {$value}, 4)");
    $conn->exec("INSERT INTO client_package_credits (id, client_package_id, client_id, {$column}, total_credits, used_credits, created_at, updated_at) VALUES (77, 76, 71, {$value}, 4, 1, '2026-10-01 12:00:00', '2026-10-02 12:00:00')");
    $conn->exec("INSERT INTO package_credit_transactions (id, client_package_credit_id, client_id, {$column}, transaction_type, amount, booking_id, notes, created_by, created_at) VALUES (79, 77, 71, {$value}, 'usage', -1, 74, 'Preserve ledger notes', 1, '2026-10-02 12:00:00')");
}

function createLegacyPackageTables(PDO $conn): void {
    // The schemas before commit 64d3488, with their original FK relationships.
    foreach (['package_credit_transactions', 'client_package_credits', 'client_packages', 'package_items'] as $table) {
        $conn->exec("DROP TABLE {$table}");
    }
    $conn->exec("CREATE TABLE package_items (
        id INT AUTO_INCREMENT PRIMARY KEY, package_id INT NOT NULL, session_type TEXT NOT NULL,
        quantity INT NOT NULL DEFAULT 1, FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $conn->exec("CREATE TABLE client_packages (
        id INT AUTO_INCREMENT PRIMARY KEY, client_id INT NOT NULL, package_id INT NOT NULL,
        package_name TEXT NOT NULL, purchased_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NULL, is_active INT DEFAULT 1, notes TEXT, created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
        FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE RESTRICT,
        FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
    $conn->exec("CREATE TABLE client_package_credits (
        id INT AUTO_INCREMENT PRIMARY KEY, client_package_id INT NOT NULL, client_id INT NOT NULL,
        session_type TEXT NOT NULL, total_credits INT NOT NULL DEFAULT 0, used_credits INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (client_package_id) REFERENCES client_packages(id) ON DELETE CASCADE,
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $conn->exec("CREATE TABLE package_credit_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY, client_package_credit_id INT NOT NULL, client_id INT NOT NULL,
        session_type TEXT NOT NULL, transaction_type TEXT NOT NULL, amount INT NOT NULL, booking_id INT,
        notes TEXT, created_by INT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (client_package_credit_id) REFERENCES client_package_credits(id) ON DELETE CASCADE,
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
        FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
        FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
}

$schemas = [];
$cases = ['legacy', 'empty_legacy', 'partial_items', 'partial_credits', 'partial_transactions', 'missing_identity', 'current'];
try {
    foreach ($cases as $case) {
        $schema = 'bdta_test_package_schema_' . bin2hex(random_bytes(6));
        $schemas[] = $schema;
        $server->exec("CREATE DATABASE {$schema} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        putenv('DB_NAME=' . $schema);
        clearPackageTestConnection();
        $conn = (new Database())->getConnection();
        if ($case === 'legacy' || $case === 'empty_legacy') {
            createLegacyPackageTables($conn);
            if ($case === 'legacy') {
                seedPackageHistory($conn, true);
            }
        } else {
            seedPackageHistory($conn, false);
        }
        if ($case === 'current') {
            $before = packageSnapshot($conn);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                clearPackageTestConnection();
                new Database();
                assertPackagePreservation(packageSnapshot($conn) === $before, 'Current-schema repeated bootstrap must preserve history.');
            }
            echo "PASS: fresh and repeated current-schema bootstrap preserve history.\n";
            continue;
        }
        $table = 'package_items';
        $partialTable = ['partial_items' => 'package_items', 'partial_credits' => 'client_package_credits', 'partial_transactions' => 'package_credit_transactions'][$case] ?? null;
        if ($partialTable !== null) {
            $table = $partialTable;
            $conn->exec("ALTER TABLE {$table} ADD COLUMN session_type TEXT NULL");
            $conn->exec("UPDATE {$table} SET session_type = 'private'");
        } elseif ($case === 'missing_identity') {
            // An interrupted conversion removed the old column without adding its replacement.
            $conn->exec('CREATE TABLE saved_package_items LIKE package_items');
            $conn->exec('INSERT INTO saved_package_items SELECT * FROM package_items');
            $conn->exec('DROP TABLE package_items');
            $conn->exec('CREATE TABLE package_items (id INT PRIMARY KEY, package_id INT, quantity INT)');
            $conn->exec('INSERT INTO package_items SELECT id, package_id, quantity FROM saved_package_items');
        }
        $conn->exec('DROP TABLE app_sessions');
        if ($case === 'legacy') {
            // Inject only the metadata fault; the fixture and preservation queries are real.
            $before = packageSnapshot($conn);
            $failedConnection = new FailingPackageMetadataPDO("mysql:host=127.0.0.1;port={$port};dbname={$schema};charset=utf8mb4", $user, $password);
            $failedConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $failedConnection->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);
            $database = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
            (new ReflectionProperty(Database::class, 'conn'))->setValue($database, $failedConnection);
            $failed = false;
            try {
                (new ReflectionMethod(Database::class, 'initTables'))->invoke($database);
            } catch (PDOException $e) {
                $failed = $e->getMessage() === 'Synthetic package metadata read failure';
            }
            assertPackagePreservation($failed, 'Metadata failure must propagate instead of continuing bootstrap.');
            assertPackagePreservation(packageSnapshot($conn) === $before, 'Metadata failure must leave all data and definitions unchanged.');
            assertPackagePreservation($conn->query("SHOW TABLES LIKE 'app_sessions'")->fetchColumn() === false, 'Metadata failure must stop before DDL.');
            echo "PASS: injected metadata failure stops before DDL and preserves history.\n";
        }
        assertLegacyBootstrapBlocked($conn, $table);
        assertLegacyBootstrapBlocked($conn, $table);
        echo "PASS: {$case} first upgrade and retry leave rows, links and DDL unchanged.\n";
        if ($partialTable !== null) {
            // Resume after the fixture's explicit, already-mapped conversion is completed.
            $conn->exec("ALTER TABLE {$table} DROP COLUMN session_type");
            $before = packageSnapshot($conn);
            clearPackageTestConnection();
            new Database();
            assertPackagePreservation(packageSnapshot($conn) === $before, 'Completed explicit conversion must retain all history on retry.');
            echo "PASS: {$case} recovery after explicit conversion preserves history.\n";
        }
    }
    echo "All legacy package schema preservation tests passed.\n";
} finally {
    clearPackageTestConnection();
    foreach ($schemas as $schema) {
        $server->exec("DROP DATABASE {$schema}");
    }
}
