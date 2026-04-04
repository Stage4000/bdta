<?php

require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/database.php';

const BDTA_DEFAULT_SESSION_LIFETIME_SECONDS = 1209600;

class BDTADatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface {
    private int $lifetime;
    private mixed $connection;

    public function __construct(int $lifetime, mixed $connection = null) {
        $this->lifetime = $lifetime;
        $this->connection = $connection;
    }

    private function getConnection(): mixed {
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
        $stmt = $this->getConnection()->prepare("
            SELECT session_data
            FROM app_sessions
            WHERE session_id = ? AND expires_at > UTC_TIMESTAMP()
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? scalar_string($row['session_data'] ?? '') : '';
    }

    public function write($id, $data): bool {
        $expires_at = gmdate('Y-m-d H:i:s', time() + $this->lifetime);
        $stmt = $this->getConnection()->prepare("
            INSERT INTO app_sessions (session_id, session_data, expires_at, created_at, updated_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                session_data = VALUES(session_data),
                expires_at = VALUES(expires_at),
                updated_at = CURRENT_TIMESTAMP
        ");

        return $stmt->execute([$id, $data, $expires_at]);
    }

    public function destroy($id): bool {
        $stmt = $this->getConnection()->prepare("DELETE FROM app_sessions WHERE session_id = ?");
        return $stmt->execute([$id]);
    }

    public function gc($max_lifetime): int|false {
        $stmt = $this->getConnection()->prepare("DELETE FROM app_sessions WHERE expires_at <= UTC_TIMESTAMP()");
        $stmt->execute([]);
        return 0;
    }

    public function validateId($id): bool {
        $stmt = $this->getConnection()->prepare("
            SELECT session_id
            FROM app_sessions
            WHERE session_id = ? AND expires_at > UTC_TIMESTAMP()
        ");
        $stmt->execute([$id]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateTimestamp($id, $data): bool {
        $expires_at = gmdate('Y-m-d H:i:s', time() + $this->lifetime);
        $stmt = $this->getConnection()->prepare("
            UPDATE app_sessions
            SET expires_at = ?, updated_at = CURRENT_TIMESTAMP
            WHERE session_id = ?
        ");

        return $stmt->execute([$expires_at, $id]);
    }
}

function bdta_get_session_lifetime_seconds(): int {
    EnvLoader::load();
    $configured = trim(EnvLoader::get('SESSION_LIFETIME_SECONDS', (string) BDTA_DEFAULT_SESSION_LIFETIME_SECONDS));
    $lifetime = ctype_digit($configured) ? (int) $configured : 0;

    return $lifetime > 0 ? $lifetime : BDTA_DEFAULT_SESSION_LIFETIME_SECONDS;
}

function bdta_register_session_handler(): void {
    static $handler = null;

    if ($handler instanceof BDTADatabaseSessionHandler || session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $handler = new BDTADatabaseSessionHandler(bdta_get_session_lifetime_seconds());
    session_set_save_handler($handler, true);
}

function bdta_apply_session_ini_settings(): int {
    $lifetime = bdta_get_session_lifetime_seconds();

    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.cookie_lifetime', (string) $lifetime);

    return $lifetime;
}
