#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/session_config.php';

ob_start();

function bdta_set_session_lifetime_env(string|false $value): void {
    if ($value === false) {
        putenv('SESSION_LIFETIME_SECONDS');
        unset($_ENV['SESSION_LIFETIME_SECONDS'], $_SERVER['SESSION_LIFETIME_SECONDS']);
        return;
    }

    putenv('SESSION_LIFETIME_SECONDS=' . $value);
    $_ENV['SESSION_LIFETIME_SECONDS'] = $value;
    $_SERVER['SESSION_LIFETIME_SECONDS'] = $value;
}

function bdta_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

class FakeSessionStatement {
    private FakeSessionConnection $connection;
    private string $query;
    private mixed $result = false;

    public function __construct(FakeSessionConnection $connection, string $query) {
        $this->connection = $connection;
        $this->query = $query;
    }

    /**
     * @param list<string> $params
     */
    public function execute(array $params): bool {
        $this->result = $this->connection->run($this->query, $params);
        return true;
    }

    public function fetch(int $mode = 0): mixed {
        return $this->result;
    }

    public function rowCount(): int {
        return $this->connection->last_row_count;
    }
}

class FakeSessionConnection {
    private const INSERT_PREFIX = 'INSERT INTO app_sessions';
    private const SELECT_DATA_PREFIX = 'SELECT session_data FROM app_sessions';
    private const SELECT_ID_PREFIX = 'SELECT session_id FROM app_sessions';
    private const UPDATE_PREFIX = 'UPDATE app_sessions SET expires_at = ?';
    private const DELETE_ID_PREFIX = 'DELETE FROM app_sessions WHERE session_id = ?';
    private const DELETE_EXPIRED_PREFIX = 'DELETE FROM app_sessions WHERE expires_at <= UTC_TIMESTAMP()';

    /** @var array<string, array{session_data: string, expires_at: string}> */
    public array $rows = [];
    public int $last_row_count = 0;

    public function prepare(string $query): FakeSessionStatement {
        return new FakeSessionStatement($this, $query);
    }

    private static function utcTimestamp(string $datetime): int {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $datetime, new DateTimeZone('UTC'));
        if ($parsed === false) {
            return 0;
        }

        return $parsed->getTimestamp();
    }

    public function setSessionExpiry(string $session_id, string $expires_at): void {
        if (isset($this->rows[$session_id])) {
            $this->rows[$session_id]['expires_at'] = $expires_at;
        }
    }

    public function hasSession(string $session_id): bool {
        return isset($this->rows[$session_id]);
    }

    /**
     * @param list<string> $params
     * @return array{session_data: string}|array{session_id: string}|bool|int
     */
    public function run(string $query, array $params): mixed {
        $normalized = preg_replace('/\s+/', ' ', trim($query)) ?: trim($query);

        if (strpos($normalized, self::INSERT_PREFIX) === 0) {
            $this->rows[$params[0]] = [
                'session_data' => $params[1],
                'expires_at' => $params[2],
            ];
            $this->last_row_count = 1;
            return true;
        }

        if (strpos($normalized, self::SELECT_DATA_PREFIX) === 0) {
            return $this->fetchActiveRow($params[0], true);
        }

        if (strpos($normalized, self::SELECT_ID_PREFIX) === 0) {
            return $this->fetchActiveRow($params[0], false);
        }

        if (strpos($normalized, self::UPDATE_PREFIX) === 0) {
            if (isset($this->rows[$params[1]])) {
                $this->rows[$params[1]]['expires_at'] = $params[0];
                $this->last_row_count = 1;
            } else {
                $this->last_row_count = 0;
            }
            return true;
        }

        if (strpos($normalized, self::DELETE_ID_PREFIX) === 0) {
            $this->last_row_count = isset($this->rows[$params[0]]) ? 1 : 0;
            unset($this->rows[$params[0]]);
            return true;
        }

        if (strpos($normalized, self::DELETE_EXPIRED_PREFIX) === 0) {
            $now = time();
            $deleted = 0;
            foreach ($this->rows as $id => $row) {
                if (self::utcTimestamp($row['expires_at']) <= $now) {
                    unset($this->rows[$id]);
                    $deleted++;
                }
            }
            $this->last_row_count = $deleted;
            return $deleted;
        }

        $this->last_row_count = 0;
        return false;
    }

    /**
     * @return array{session_data: string}|array{session_id: string}|false
     */
    private function fetchActiveRow(string $session_id, bool $with_data): array|false {
        if (!isset($this->rows[$session_id])) {
            $this->last_row_count = 0;
            return false;
        }

        if (self::utcTimestamp($this->rows[$session_id]['expires_at']) <= time()) {
            $this->last_row_count = 0;
            return false;
        }

        $this->last_row_count = 1;

        if ($with_data) {
            return ['session_data' => $this->rows[$session_id]['session_data']];
        }

        return ['session_id' => $session_id];
    }
}

$cases = [
    [
        'env' => false,
        'expected' => BDTA_DEFAULT_SESSION_LIFETIME_SECONDS,
        'label' => 'uses default lifetime when env is missing',
    ],
    [
        'env' => '3600',
        'expected' => 3600,
        'label' => 'uses configured positive lifetime',
    ],
    [
        'env' => 'invalid',
        'expected' => BDTA_DEFAULT_SESSION_LIFETIME_SECONDS,
        'label' => 'falls back on invalid lifetime',
    ],
    [
        'env' => '0',
        'expected' => BDTA_DEFAULT_SESSION_LIFETIME_SECONDS,
        'label' => 'falls back on non-positive lifetime',
    ],
];

$passed_labels = [];

foreach ($cases as $case) {
    bdta_set_session_lifetime_env($case['env']);

    $resolved = bdta_get_session_lifetime_seconds();
    if ($resolved !== $case['expected']) {
        fwrite(STDERR, "Failed: {$case['label']} (got {$resolved})\n");
        exit(1);
    }

    $applied = bdta_apply_session_ini_settings();
    if ($applied !== $case['expected']) {
        fwrite(STDERR, "Failed to apply: {$case['label']} (got {$applied})\n");
        exit(1);
    }

    if ((int) ini_get('session.gc_maxlifetime') !== $case['expected']) {
        fwrite(STDERR, "Failed gc_maxlifetime assertion for {$case['label']}\n");
        exit(1);
    }

    if ((int) ini_get('session.cookie_lifetime') !== $case['expected']) {
        fwrite(STDERR, "Failed cookie_lifetime assertion for {$case['label']}\n");
        exit(1);
    }

    $passed_labels[] = $case['label'];
}

bdta_set_session_lifetime_env(false);

ob_end_clean();

echo "=== Session Config Tests ===\n\n";
foreach ($passed_labels as $label) {
    echo "✓ {$label}\n";
}

$fake_connection = new FakeSessionConnection();
$handler = new BDTADatabaseSessionHandler(3600, $fake_connection);

bdta_assert($handler->write('session-a', 'payload-a') === true, 'Failed to write session payload');
bdta_assert($handler->read('session-a') === 'payload-a', 'Failed to read active session payload');
bdta_assert($handler->validateId('session-a') === true, 'Failed to validate active session id');
bdta_assert($handler->write('session-a', 'payload-a-updated') === true, 'Failed to update existing session payload');
bdta_assert($handler->read('session-a') === 'payload-a-updated', 'Failed to read updated session payload');

$fake_connection->setSessionExpiry('session-a', gmdate('Y-m-d H:i:s', time() + 60));
$previous_expiry = $fake_connection->rows['session-a']['expires_at'];
bdta_assert($handler->updateTimestamp('session-a', 'payload-a') === true, 'Failed to update session timestamp');
bdta_assert(strtotime($fake_connection->rows['session-a']['expires_at']) > strtotime($previous_expiry), 'Failed to extend session expiry');

$fake_connection->rows['session-expired'] = [
    'session_data' => 'payload-expired',
    'expires_at' => gmdate('Y-m-d H:i:s', time() - 60),
];
bdta_assert($handler->read('session-expired') === '', 'Expired session should not be readable');
bdta_assert($handler->validateId('session-expired') === false, 'Expired session should not validate');
$deleted_sessions = $handler->gc(0);
bdta_assert($deleted_sessions === 1, 'Garbage collection should report deleted expired sessions');
bdta_assert(!$fake_connection->hasSession('session-expired'), 'Garbage collection should remove expired sessions');

bdta_assert($handler->destroy('session-a') === true, 'Failed to destroy session');
bdta_assert(!$fake_connection->hasSession('session-a'), 'Destroyed session should be removed');

echo "✓ persists sessions in the database handler\n";
echo "\nAll session config tests passed.\n";
