<?php
/** Disposable loopback MySQL only. Creates/drops its own random synthetic schema. */
if (PHP_SAPI !== 'cli' || getenv('BDTA_RECEIPT_MYSQL_TEST') !== '1') {
    throw new RuntimeException('Explicit disposable receipt migration test opt-in required.');
}
require_once dirname(__DIR__) . '/backend/includes/database.php';
$port = getenv('BDTA_RECEIPT_MYSQL_PORT');
if (!is_string($port) || !ctype_digit($port)) { throw new RuntimeException('Fixture port required'); }
$user = getenv('BDTA_RECEIPT_MYSQL_USER');
$password = getenv('BDTA_RECEIPT_MYSQL_PASSWORD');
if (!is_string($user) || !is_string($password)) { throw new RuntimeException('Fixture credentials required'); }
$pdo = new SafePDO('mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4', $user, $password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);
$schema = 'bdta_receipt_test_' . bin2hex(random_bytes(8));
if (!preg_match('/^bdta_receipt_test_[a-f0-9]{16}$/D', $schema)) { throw new RuntimeException('Unsafe fixture name'); }
$pdo->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $pdo->exec('USE `' . $schema . '`');
    $pdo->exec('CREATE TABLE client_emails(id INT PRIMARY KEY, message_id TEXT, subject TEXT)');
    $pdo->exec("INSERT INTO client_emails VALUES(1,'<old@example.invalid>','Synthetic legacy')");
    $reflection = new ReflectionClass(Database::class);
    $db = $reflection->newInstanceWithoutConstructor();
    $reflection->getProperty('conn')->setValue($db, $pdo);
    $migration = $reflection->getMethod('migrateClientEmailReadReceiptColumns');
    $migration->invoke($db);
    $migration->invoke($db);
    $row = $pdo->query('SELECT *, (read_receipt_reported_at IS NULL AND read_receipt_source_message_id IS NULL) AS no_report FROM client_emails WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row['subject'] !== 'Synthetic legacy' || $row['message_id'] !== '<old@example.invalid>'
        || (int)$row['read_receipt_requested'] !== 0 || (int)$row['no_report'] !== 1) {
        throw new RuntimeException('Additive/idempotent migration changed or enrolled legacy mail');
    }
    $pdo->exec("INSERT INTO client_emails(id,subject) VALUES(2,'Synthetic new')");
    $row = $pdo->query('SELECT read_receipt_requested,(read_receipt_reported_at IS NULL) AS no_report FROM client_emails WHERE id=2')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || (int)$row['read_receipt_requested'] !== 0 || (int)$row['no_report'] !== 1) {
        throw new RuntimeException('New mail default must remain off/unknown');
    }
    $sourceId = '<' . str_repeat('a', 900) . '@example.invalid>';
    $statement = $pdo->prepare('UPDATE client_emails SET read_receipt_source_message_id = ? WHERE id = 2');
    $statement->execute([$sourceId]);
    if ($pdo->query('SELECT read_receipt_source_message_id FROM client_emails WHERE id=2')->fetchColumn() !== $sourceId) {
        throw new RuntimeException('Migration must preserve receipt source IDs longer than 255 bytes');
    }
    echo "PASS: actual additive migration, repeated invocation, legacy preservation and default-off on disposable MariaDB.\n";
} finally {
    $pdo->exec('DROP DATABASE `' . $schema . '`');
}
