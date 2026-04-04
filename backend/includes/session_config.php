<?php

require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/database.php';

const BDTA_DEFAULT_SESSION_LIFETIME_SECONDS = 1209600;

class BDTADatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface {
    private int $lifetime;
    private ?object $connection;

    public function __construct(int $lifetime, ?object $connection = null) {
        $this->lifetime = $lifetime;
        $this->connection = $connection;
    }

    private function getConnection(): object {
        if ($this->connection === null) {
            $db = new Database();
            $this->connection = $db->getConnection();
        }

        return $this->connection;
    }

    public function open($save_path, $session_name): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read($id): string {
        $connection = $this->getConnection();
        if (!method_exists($connection, 'prepare')) {
            throw new RuntimeException('Session connection does not support prepared statements.');
        }
        $stmt = $connection->prepare("
            SELECT session_data
            FROM app_sessions
            WHERE session_id = ? AND expires_at > UTC_TIMESTAMP()
        ");
        if (!is_object($stmt) || !method_exists($stmt, 'execute') || !method_exists($stmt, 'fetch')) {
            throw new RuntimeException('Session statement does not support reads.');
        }
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || !isset($row['session_data']) || !is_string($row['session_data'])) {
            return '';
        }

        return $row['session_data'];
    }

    public function write($id, $data): bool {
        $expires_at = gmdate('Y-m-d H:i:s', time() + $this->lifetime);
        $connection = $this->getConnection();
        if (!method_exists($connection, 'prepare')) {
            throw new RuntimeException('Session connection does not support prepared statements.');
        }
        $stmt = $connection->prepare("
            INSERT INTO app_sessions (session_id, session_data, expires_at, created_at, updated_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                session_data = ?,
                expires_at = ?,
                updated_at = CURRENT_TIMESTAMP
        ");
        if (!is_object($stmt) || !method_exists($stmt, 'execute')) {
            throw new RuntimeException('Session statement does not support writes.');
        }

        return $stmt->execute([$id, $data, $expires_at, $data, $expires_at]);
    }

    public function destroy($id): bool {
        $connection = $this->getConnection();
        if (!method_exists($connection, 'prepare')) {
            throw new RuntimeException('Session connection does not support prepared statements.');
        }
        $stmt = $connection->prepare("DELETE FROM app_sessions WHERE session_id = ?");
        if (!is_object($stmt) || !method_exists($stmt, 'execute')) {
            throw new RuntimeException('Session statement does not support deletes.');
        }
        return $stmt->execute([$id]);
    }

    public function gc($max_lifetime): int|false {
        $connection = $this->getConnection();
        if (!method_exists($connection, 'prepare')) {
            throw new RuntimeException('Session connection does not support prepared statements.');
        }
        $stmt = $connection->prepare("DELETE FROM app_sessions WHERE expires_at <= UTC_TIMESTAMP()");
        if (!is_object($stmt) || !method_exists($stmt, 'execute') || !method_exists($stmt, 'rowCount')) {
            throw new RuntimeException('Session statement does not support garbage collection.');
        }
        if (!$stmt->execute([])) {
            return false;
        }

        return $stmt->rowCount();
    }

    public function validateId($id): bool {
        $connection = $this->getConnection();
        if (!method_exists($connection, 'prepare')) {
            throw new RuntimeException('Session connection does not support prepared statements.');
        }
        $stmt = $connection->prepare("
            SELECT session_id
            FROM app_sessions
            WHERE session_id = ? AND expires_at > UTC_TIMESTAMP()
        ");
        if (!is_object($stmt) || !method_exists($stmt, 'execute') || !method_exists($stmt, 'fetch')) {
            throw new RuntimeException('Session statement does not support validation.');
        }
        $stmt->execute([$id]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateTimestamp($id, $data): bool {
        $expires_at = gmdate('Y-m-d H:i:s', time() + $this->lifetime);
        $connection = $this->getConnection();
        if (!method_exists($connection, 'prepare')) {
            throw new RuntimeException('Session connection does not support prepared statements.');
        }
        $stmt = $connection->prepare("
            UPDATE app_sessions
            SET expires_at = ?, updated_at = CURRENT_TIMESTAMP
            WHERE session_id = ?
        ");
        if (!is_object($stmt) || !method_exists($stmt, 'execute')) {
            throw new RuntimeException('Session statement does not support timestamp updates.');
        }

        return $stmt->execute([$expires_at, $id]);
    }
}

function bdta_get_session_lifetime_seconds(): int {
    $configured = trim(EnvLoader::get('SESSION_LIFETIME_SECONDS', (string) BDTA_DEFAULT_SESSION_LIFETIME_SECONDS));
    $lifetime = ctype_digit($configured) ? (int) $configured : 0;

    return $lifetime > 0 ? $lifetime : BDTA_DEFAULT_SESSION_LIFETIME_SECONDS;
}

function bdta_register_session_handler(): void {
    static $handler = null;
    static $registered = false;

    if ($registered || session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $handler = new BDTADatabaseSessionHandler(bdta_get_session_lifetime_seconds());
    session_set_save_handler($handler, true);
    $registered = true;
}

function bdta_apply_session_ini_settings(): int {
    $lifetime = bdta_get_session_lifetime_seconds();

    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.cookie_lifetime', (string) $lifetime);

    return $lifetime;
}
