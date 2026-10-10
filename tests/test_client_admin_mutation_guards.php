#!/usr/bin/env php
<?php
/**
 * Execute the real client controllers in isolated PHP processes against synthetic
 * roles in a newly created disposable loopback MySQL/MariaDB schema. No HTTP or
 * browser is used. Opt in with BDTA_ADMIN_TEST_PORT and BDTA_ADMIN_TEST_USER.
 */

$port = getenv('BDTA_ADMIN_TEST_PORT');
$user = getenv('BDTA_ADMIN_TEST_USER');
if ($port === false || $user === false) {
    echo "SKIP: set BDTA_ADMIN_TEST_PORT and BDTA_ADMIN_TEST_USER for a disposable loopback server.\n";
    exit(0);
}
if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535 || $user === '') {
    throw new RuntimeException('Invalid disposable test configuration.');
}
$password = getenv('BDTA_ADMIN_TEST_PASSWORD');
$password = $password === false ? '' : $password;
putenv('DB_TYPE=mysql');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=' . $port);
putenv('DB_USER=' . $user);
putenv('DB_PASSWORD=' . $password);

if (($argv[1] ?? '') === '--worker') {
    $schema = $argv[2] ?? '';
    if (preg_match('/^bdta_test_admin_mutations_[a-f0-9]{12}$/', $schema) !== 1) {
        throw new RuntimeException('Worker must use a generated disposable schema.');
    }
    $request = json_decode($argv[3] ?? '', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($request)) {
        throw new RuntimeException('Invalid fixture request.');
    }
    /** @var array{route: string, role: string, target: int, method: string, token: string, grant: bool, race: bool, no_op: bool} $request */
    if (!in_array($request['route'], ['clients_edit.php', 'client_set_password.php'], true)) {
        throw new RuntimeException('Unsupported fixture route.');
    }
    putenv('DB_NAME=' . $schema);
    require_once dirname(__DIR__) . '/backend/includes/database.php';
    putenv('DB_PASSWORD=' . $password);

    class ConcurrentClientPromotionPDO extends SafePDO {
        public bool $promoteBeforeUpdate = false;
        /** @param array<mixed> $options */
        public function prepare(string $query, array $options = []): SafePDOStatement {
            if ($this->promoteBeforeUpdate && preg_match('/UPDATE\s+clients\s+SET/i', $query) === 1) {
                $this->promoteBeforeUpdate = false;
                $this->exec('UPDATE clients SET is_admin = 1 WHERE id = 11');
            }
            return parent::prepare($query, $options);
        }
    }
    $conn = new ConcurrentClientPromotionPDO("mysql:host=127.0.0.1;port={$port};dbname={$schema};charset=utf8mb4", $user, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);
    $conn->promoteBeforeUpdate = $request['race'];
    (new ReflectionProperty(Database::class, 'sharedConnection'))->setValue(null, $conn);

    $roles = [
        'main' => ['admin_id' => 1, 'user_type' => 'admin', 'admin_account_type' => 'main'],
        'manager' => ['admin_id' => 2, 'user_type' => 'admin', 'admin_account_type' => 'standard'],
        'restricted' => ['admin_id' => 3, 'user_type' => 'admin', 'admin_account_type' => 'standard', 'can_manage_admin_users' => 1],
        'accountant' => ['admin_id' => 4, 'user_type' => 'admin', 'admin_account_type' => 'accountant'],
        'legacy' => ['admin_id' => 12, 'user_type' => 'client'],
        'signed_out' => [],
    ];
    if (!isset($roles[$request['role']])) {
        throw new RuntimeException('Unsupported fixture role.');
    }
    $sessionDirectory = getenv('BDTA_ADMIN_TEST_SESSION_DIRECTORY');
    if ($sessionDirectory === false || !is_dir($sessionDirectory)) {
        throw new RuntimeException('Missing disposable session directory.');
    }
    ini_set('session.save_path', $sessionDirectory);
    session_start();
    $_SESSION = $roles[$request['role']];
    $_SESSION['admin_account_type_refreshed_at'] = time();
    $_SESSION['csrf_token'] = str_repeat('a', 64);
    $_SERVER['SCRIPT_NAME'] = '/client/' . $request['route'];
    $_SERVER['REQUEST_METHOD'] = $request['method'];
    $_GET = [$request['route'] === 'clients_edit.php' ? 'id' : 'client_id' => (string) $request['target']];
    $_POST = $request['route'] === 'clients_edit.php'
        ? ['name' => 'Changed Synthetic Client', 'email' => 'changed@example.test', 'phone' => '555-0101', 'address' => 'Synthetic address', 'notes' => 'Synthetic edit']
        : ['new_password' => 'Synthetic-only-new-password!', 'confirm_password' => 'Synthetic-only-new-password!'];
    if ($request['no_op']) {
        $_POST = ['name' => 'Synthetic Client', 'email' => 'client@example.test', 'phone' => '', 'address' => '', 'notes' => ''];
    }
    if ($request['grant']) {
        $_POST['is_admin'] = '1';
    }
    if ($request['token'] !== 'missing') {
        $_POST['csrf_token'] = str_repeat($request['token'] === 'valid' ? 'a' : 'b', 64);
    }
    ob_start();
    register_shutdown_function(static function (): void {
        $html = (string) ob_get_clean();
        echo json_encode([
            'form' => strpos($html, '<form method="POST">') !== false,
            'csrf' => preg_match('/<form method="POST">.*?name="csrf_token"/s', $html) === 1,
            'admin_control' => strpos($html, 'name="is_admin"') !== false,
            'flash_type' => $_SESSION['flash_type'] ?? null,
        ], JSON_THROW_ON_ERROR);
    });
    chdir(dirname(__DIR__) . '/client');
    require dirname(__DIR__) . '/client/' . $request['route'];
    exit(0);
}

if (!function_exists('proc_open')) {
    throw new RuntimeException('Controller regression launcher requires proc_open; workers disable external transports and process execution.');
}
$schema = 'bdta_test_admin_mutations_' . bin2hex(random_bytes(6));
$server = new PDO("mysql:host=127.0.0.1;port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec("CREATE DATABASE {$schema} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
putenv('DB_NAME=' . $schema);
require_once dirname(__DIR__) . '/backend/includes/database.php';
putenv('DB_PASSWORD=' . $password);
$sessionDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $schema;
if (!mkdir($sessionDirectory, 0700)) {
    throw new RuntimeException('Unable to create disposable session directory.');
}
putenv('BDTA_ADMIN_TEST_SESSION_DIRECTORY=' . $sessionDirectory);
$failures = 0;
try {
    $conn = (new Database())->getConnection();
    $hash = password_hash('Synthetic-only-original-password!', PASSWORD_DEFAULT);
    $conn->exec("INSERT INTO admin_users (id, username, password_hash, email, account_type, can_manage_admin_users) VALUES
        (2, 'synthetic_manager', 'fixture', 'manager@example.test', 'standard', 1),
        (3, 'synthetic_restricted', 'fixture', 'restricted@example.test', 'standard', 0),
        (4, 'synthetic_accountant', 'fixture', 'accountant@example.test', 'accountant', 1)");
    // label, route, role, target, token, grant, expected; GET and race cases follow.
    $cases = [
        ['password missing CSRF', 'client_set_password.php', 'main', 11, 'missing', false, 'deny'],
        ['password invalid CSRF', 'client_set_password.php', 'main', 11, 'invalid', false, 'deny'],
        ['password valid CSRF', 'client_set_password.php', 'main', 11, 'valid', false, 'password'],
        ['edit missing CSRF', 'clients_edit.php', 'main', 11, 'missing', false, 'deny'],
        ['edit invalid CSRF', 'clients_edit.php', 'main', 11, 'invalid', false, 'deny'],
        ['edit valid CSRF', 'clients_edit.php', 'main', 11, 'valid', false, 'edit'],
        ['restricted promotion', 'clients_edit.php', 'restricted', 11, 'valid', true, 'deny'],
        ['restricted admin creation', 'clients_edit.php', 'restricted', 0, 'valid', true, 'deny'],
        ['restricted admin demotion', 'clients_edit.php', 'restricted', 12, 'valid', false, 'deny'],
        ['restricted admin identity edit', 'clients_edit.php', 'restricted', 12, 'valid', true, 'deny'],
        ['restricted admin password', 'client_set_password.php', 'restricted', 12, 'valid', false, 'deny'],
        ['restricted ordinary edit', 'clients_edit.php', 'restricted', 11, 'valid', false, 'edit'],
        ['restricted unchanged edit', 'clients_edit.php', 'restricted', 11, 'valid', false, 'no_op'],
        ['restricted ordinary password', 'client_set_password.php', 'restricted', 11, 'valid', false, 'password'],
        ['restricted ordinary creation', 'clients_edit.php', 'restricted', 0, 'valid', false, 'edit'],
        ['delegated promotion', 'clients_edit.php', 'manager', 11, 'valid', true, 'edit'],
        ['delegated demotion', 'clients_edit.php', 'manager', 12, 'valid', false, 'edit'],
        ['delegated admin password', 'client_set_password.php', 'manager', 12, 'valid', false, 'password'],
        ['main admin creation', 'clients_edit.php', 'main', 0, 'valid', true, 'edit'],
        ['main admin password', 'client_set_password.php', 'main', 12, 'valid', false, 'password'],
        ['signed-out edit', 'clients_edit.php', 'signed_out', 11, 'valid', true, 'deny'],
        ['signed-out password', 'client_set_password.php', 'signed_out', 12, 'valid', false, 'deny'],
        ['accountant edit', 'clients_edit.php', 'accountant', 11, 'valid', true, 'deny'],
        ['accountant password', 'client_set_password.php', 'accountant', 12, 'valid', false, 'deny'],
        ['legacy client promotion', 'clients_edit.php', 'legacy', 11, 'valid', true, 'deny'],
        ['legacy client admin password', 'client_set_password.php', 'legacy', 12, 'valid', false, 'deny'],
        ['restricted form', 'clients_edit.php', 'restricted', 11, 'valid', false, 'restricted_form'],
        ['delegated edit form', 'clients_edit.php', 'manager', 11, 'valid', false, 'admin_form'],
        ['delegated password form', 'client_set_password.php', 'manager', 12, 'valid', false, 'password_form'],
        ['restricted admin form', 'clients_edit.php', 'restricted', 12, 'valid', false, 'deny_form'],
        ['signed-out edit form', 'clients_edit.php', 'signed_out', 11, 'valid', false, 'deny_form'],
        ['signed-out password form', 'client_set_password.php', 'signed_out', 12, 'valid', false, 'deny_form'],
        ['promotion during restricted edit', 'clients_edit.php', 'restricted', 11, 'valid', false, 'race'],
        ['promotion during restricted password', 'client_set_password.php', 'restricted', 11, 'valid', false, 'race'],
    ];
    foreach ($cases as [$label, $route, $role, $target, $token, $grant, $expected]) {
        $conn->exec('DELETE FROM clients');
        $stmt = $conn->prepare("INSERT INTO clients (id, name, email, is_admin, password_hash, phone, address, notes) VALUES (?, ?, ?, ?, ?, '', '', '')");
        $stmt->execute([11, 'Synthetic Client', 'client@example.test', 0, $hash]);
        $stmt->execute([12, 'Synthetic Legacy Admin', 'legacy@example.test', 1, $hash]);
        $before = $conn->query('SELECT * FROM clients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        if ($expected === 'no_op') {
            // Keep timestamps unchanged so MySQL reports zero changed rows.
            $conn->exec('CREATE TRIGGER test_noop_timestamp BEFORE UPDATE ON clients FOR EACH ROW SET NEW.updated_at = OLD.updated_at');
        }
        $request = ['route' => $route, 'role' => $role, 'target' => $target,
            'method' => strpos($expected, 'form') !== false ? 'GET' : 'POST',
            'token' => $token, 'grant' => $grant, 'race' => $expected === 'race', 'no_op' => $expected === 'no_op'];
        $command = [PHP_BINARY, '-d', 'allow_url_fopen=0', '-d', 'disable_functions=mail,curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,exec,shell_exec,system,passthru,proc_open,imap_open,socket_connect'];
        $extension = getenv('BDTA_ADMIN_TEST_EXTENSION');
        if ($extension !== false && $extension !== '') {
            $command[] = '-d';
            $command[] = 'extension=' . $extension;
        }
        $command = array_merge($command, [__FILE__, '--worker', $schema, json_encode($request, JSON_THROW_ON_ERROR)]);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to launch synthetic controller worker.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || $output === false) {
            throw new RuntimeException('Controller worker failed: ' . (string) $stderr);
        }
        $response = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($response)) {
            throw new RuntimeException('Invalid worker result.');
        }
        $after = $conn->query('SELECT * FROM clients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        if ($expected === 'no_op') {
            $conn->exec('DROP TRIGGER test_noop_timestamp');
        }
        $row = $target === 12 ? $after[1] : ($target === 0 ? $after[2] ?? [] : $after[0]);
        $passed = false;
        if ($expected === 'deny') {
            $passed = $before === $after && empty($response['form']);
        } elseif ($expected === 'no_op') {
            $passed = $before === $after && ($response['flash_type'] ?? '') === 'success' && empty($response['form']);
        } elseif ($expected === 'edit') {
            $passed = ($row['name'] ?? '') === 'Changed Synthetic Client'
                && (int) ($row['is_admin'] ?? -1) === ($grant ? 1 : 0);
        } elseif ($expected === 'password') {
            $passed = password_verify('Synthetic-only-new-password!', (string) ($row['password_hash'] ?? ''));
        } elseif ($expected === 'race') {
            $before[0]['is_admin'] = $after[0]['is_admin'];
            $passed = (int) $after[0]['is_admin'] === 1 && $before === $after;
        } else {
            $passed = $before === $after && ($expected === 'deny_form'
                ? empty($response['form'])
                : !empty($response['form']) && !empty($response['csrf'])
                    && (!empty($response['admin_control']) === ($expected === 'admin_form')));
        }
        echo ($passed ? 'PASS: ' : 'FAIL: ') . $label . "\n";
        if (!$passed) {
            $failures++;
        }
    }
    echo 'Controller mutation cases: ' . count($cases) . '; failures: ' . $failures . ".\n";
} finally {
    $server->exec("DROP DATABASE {$schema}");
    foreach (glob($sessionDirectory . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $sessionFile) {
        unlink($sessionFile);
    }
    rmdir($sessionDirectory);
}
exit($failures === 0 ? 0 : 1);
