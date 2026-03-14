<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/settings.php';

class MoxieClientSync {
    private const DEFAULT_PAGE_SIZE = 100;
    private const MAX_PAGES = 100;

    private SafePDO $conn;

    public function __construct(?SafePDO $conn = null) {
        if ($conn instanceof SafePDO) {
            $this->conn = $conn;
            return;
        }

        $db = new Database();
        $this->conn = $db->getConnection();
    }

    public static function getLogFilePath(): string {
        return dirname(__DIR__) . '/logs/moxie.log';
    }

    public static function getConfiguredBaseUrl(): string {
        return self::normalizeBaseUrl(scalar_string(Settings::get('moxie_base_url', '')));
    }

    public static function getConfiguredApiKey(): string {
        return trim(scalar_string(Settings::get('moxie_api_key', '')));
    }

    public static function normalizeBaseUrl(string $base_url): string {
        $base_url = trim($base_url);
        if ($base_url === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $base_url)) {
            $base_url = 'https://' . ltrim($base_url, '/');
        }

        return rtrim($base_url, '/');
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $message, array $context = []): void {
        $log_file = self::getLogFilePath();
        $log_dir = dirname($log_file);

        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0775, true);
        }

        if (!file_exists($log_file)) {
            touch($log_file);
        }

        $line = '[' . gmdate('Y-m-d H:i:s') . " UTC] " . $message;
        if (!empty($context)) {
            $context_json = json_encode($context, JSON_UNESCAPED_SLASHES);
            if (is_string($context_json) && $context_json !== '') {
                $line .= ' ' . $context_json;
            }
        }

        file_put_contents($log_file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * @return array{fetched:int, created:int, updated:int, unchanged:int, skipped_archived:int, skipped_missing_email:int}
     */
    public function sync(string $base_url, string $api_key): array {
        $base_url = self::normalizeBaseUrl($base_url);
        $api_key = trim($api_key);

        if ($base_url === '') {
            throw new InvalidArgumentException('Moxie base URL is required.');
        }

        if ($api_key === '') {
            throw new InvalidArgumentException('Moxie API key is required.');
        }

        self::log('Starting Moxie client sync.', ['base_url' => $base_url]);
        $raw_clients = $this->fetchClients($base_url, $api_key);
        $result = $this->syncClientRows($raw_clients);
        self::log('Finished Moxie client sync.', $result);

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $raw_clients
     * @return array{fetched:int, created:int, updated:int, unchanged:int, skipped_archived:int, skipped_missing_email:int}
     */
    public function syncClientRows(array $raw_clients): array {
        $summary = [
            'fetched' => count($raw_clients),
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped_archived' => 0,
            'skipped_missing_email' => 0,
        ];

        $this->conn->beginTransaction();

        try {
            foreach ($raw_clients as $raw_client) {
                $client = self::normalizeClient($raw_client);

                if ($client['archived']) {
                    $summary['skipped_archived']++;
                    self::log('Skipping archived Moxie client.', [
                        'moxie_client_id' => $client['moxie_client_id'],
                        'name' => $client['name'],
                    ]);
                    continue;
                }

                if ($client['email'] === '') {
                    $summary['skipped_missing_email']++;
                    self::log('Skipping Moxie client without email.', [
                        'moxie_client_id' => $client['moxie_client_id'],
                        'name' => $client['name'],
                    ]);
                    continue;
                }

                $existing = $this->findExistingClient($client);

                if ($existing === null) {
                    $stmt = $this->conn->prepare("
                        INSERT INTO clients (name, email, phone, address, notes, moxie_client_id)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $client['name'],
                        $client['email'],
                        $client['phone'],
                        $client['address'],
                        $client['notes'],
                        $client['moxie_client_id'],
                    ]);
                    $summary['created']++;
                    self::log('Created client from Moxie import.', [
                        'client_id' => scalar_string($this->conn->lastInsertId()),
                        'moxie_client_id' => $client['moxie_client_id'],
                        'email' => $client['email'],
                    ]);
                    continue;
                }

                $merged = [
                    'name' => $client['name'] !== '' ? $client['name'] : self::stringValue($existing, 'name'),
                    'email' => $client['email'] !== '' ? $client['email'] : self::stringValue($existing, 'email'),
                    'phone' => $client['phone'] !== '' ? $client['phone'] : self::stringValue($existing, 'phone'),
                    'address' => $client['address'] !== '' ? $client['address'] : self::stringValue($existing, 'address'),
                    'notes' => $client['notes'] !== '' ? $client['notes'] : self::stringValue($existing, 'notes'),
                    'moxie_client_id' => $client['moxie_client_id'] !== '' ? $client['moxie_client_id'] : self::stringValue($existing, 'moxie_client_id'),
                ];

                $unchanged = $merged['name'] === self::stringValue($existing, 'name')
                    && strcasecmp($merged['email'], self::stringValue($existing, 'email')) === 0
                    && $merged['phone'] === self::stringValue($existing, 'phone')
                    && $merged['address'] === self::stringValue($existing, 'address')
                    && $merged['notes'] === self::stringValue($existing, 'notes')
                    && $merged['moxie_client_id'] === self::stringValue($existing, 'moxie_client_id');

                if ($unchanged) {
                    $summary['unchanged']++;
                    continue;
                }

                $stmt = $this->conn->prepare("
                    UPDATE clients
                    SET name = ?, email = ?, phone = ?, address = ?, notes = ?, moxie_client_id = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $merged['name'],
                    $merged['email'],
                    $merged['phone'],
                    $merged['address'],
                    $merged['notes'],
                    $merged['moxie_client_id'],
                    self::stringValue($existing, 'id'),
                ]);
                $summary['updated']++;
                self::log('Updated existing client from Moxie import.', [
                    'client_id' => self::stringValue($existing, 'id'),
                    'moxie_client_id' => $merged['moxie_client_id'],
                    'email' => $merged['email'],
                ]);
            }

            $this->conn->commit();
            return $summary;
        } catch (Throwable $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            self::log('Moxie client sync failed.', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchClients(string $base_url, string $api_key, int $page_size = self::DEFAULT_PAGE_SIZE): array {
        $clients = [];
        $next_url = $base_url . '/api/public/clients/list?start=0&count=' . $page_size;
        $page = 0;
        $start = 0;

        while ($next_url !== '' && $page < self::MAX_PAGES) {
            $page++;
            self::log('Fetching Moxie client page.', ['url' => $next_url, 'page' => $page]);
            $response = $this->requestJson($next_url, $api_key);
            $page_clients = self::extractClientRows($response);
            foreach ($page_clients as $page_client) {
                $clients[] = $page_client;
            }

            $response_next = self::extractNextUrl($response, $base_url);
            if ($response_next !== '') {
                $next_url = $response_next;
                continue;
            }

            if (count($page_clients) < $page_size) {
                break;
            }

            $start += $page_size;
            $next_url = $base_url . '/api/public/clients/list?start=' . $start . '&count=' . $page_size;
        }

        return $clients;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    public static function extractClientRows(array $payload): array {
        if (self::isListArray($payload)) {
            return self::listOfAssoc($payload);
        }

        $candidates = [
            $payload['_embedded']['clients'] ?? null,
            $payload['clients'] ?? null,
            $payload['data'] ?? null,
            $payload['items'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return self::listOfAssoc($candidate);
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function extractNextUrl(array $payload, string $base_url): string {
        $next = $payload['_links']['next']['href'] ?? ($payload['links']['next']['href'] ?? ($payload['next'] ?? ''));
        $next = trim(is_string($next) ? $next : '');

        if ($next === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $next)) {
            return $next;
        }

        return rtrim($base_url, '/') . '/' . ltrim($next, '/');
    }

    /**
     * @param array<string, mixed> $raw_client
     * @return array{name:string, email:string, phone:string, address:string, notes:string, moxie_client_id:string, archived:bool}
     */
    public static function normalizeClient(array $raw_client): array {
        $contacts = self::listOfAssoc($raw_client['contacts'] ?? []);
        $primary_contact = self::pickPrimaryContact($contacts);
        $contact_name = trim(self::stringValue($primary_contact, 'firstName') . ' ' . self::stringValue($primary_contact, 'lastName'));

        $name = trim(self::firstNonEmpty([
            self::stringValue($raw_client, 'name'),
            self::stringValue($raw_client, 'displayName'),
            $contact_name,
            self::stringValue($primary_contact, 'email'),
        ]));

        $address = implode(', ', array_values(array_filter([
            self::stringValue($raw_client, 'address1'),
            self::stringValue($raw_client, 'address2'),
            self::stringValue($raw_client, 'city'),
            self::stringValue($raw_client, 'locality'),
            self::stringValue($raw_client, 'postal'),
            self::stringValue($raw_client, 'country'),
        ], static fn (mixed $value): bool => trim(scalar_string($value)) !== '')));

        return [
            'name' => $name,
            'email' => trim(self::firstNonEmpty([
                self::stringValue($primary_contact, 'email'),
                self::stringValue($raw_client, 'email'),
                self::stringValue($raw_client, 'primaryEmail'),
            ])),
            'phone' => trim(self::firstNonEmpty([
                self::stringValue($raw_client, 'phone'),
                self::stringValue($primary_contact, 'phone'),
                self::stringValue($raw_client, 'primaryPhone'),
            ])),
            'address' => $address,
            'notes' => trim(self::firstNonEmpty([
                self::stringValue($raw_client, 'notes'),
                self::stringValue($primary_contact, 'notes'),
            ])),
            'moxie_client_id' => trim(self::firstNonEmpty([
                self::stringValue($raw_client, 'id'),
                self::stringValue($raw_client, 'clientId'),
                self::stringValue($raw_client, 'uuid'),
            ])),
            'archived' => self::boolValue($raw_client['archive'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findExistingClient(array $client): ?array {
        if ($client['moxie_client_id'] !== '') {
            $stmt = $this->conn->prepare("SELECT * FROM clients WHERE moxie_client_id = ? ORDER BY id LIMIT 1");
            $stmt->execute([$client['moxie_client_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $row;
            }
        }

        if ($client['email'] !== '') {
            $stmt = $this->conn->prepare("SELECT * FROM clients WHERE LOWER(email) = LOWER(?) ORDER BY id LIMIT 1");
            $stmt->execute([$client['email']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $row;
            }
        }

        if ($client['name'] !== '' && $client['phone'] !== '') {
            $stmt = $this->conn->prepare("SELECT * FROM clients WHERE name = ? AND phone = ? ORDER BY id LIMIT 1");
            $stmt->execute([$client['name'], $client['phone']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJson(string $url, string $api_key): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for Moxie request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-API-KEY: ' . $api_key,
            ],
        ]);

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Moxie request failed: ' . $curl_error);
        }

        if ($http_code < 200 || $http_code >= 300) {
            self::log('Moxie returned non-success status.', ['status' => $http_code, 'body' => substr(scalar_string($response), 0, 500)]);
            throw new RuntimeException('Moxie request failed with HTTP status ' . $http_code . '.');
        }

        $decoded = json_decode(scalar_string($response), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Moxie response could not be decoded as JSON.');
        }

        return $decoded;
    }

    /**
     * @param list<array<string, mixed>> $contacts
     * @return array<string, mixed>
     */
    private static function pickPrimaryContact(array $contacts): array {
        foreach ($contacts as $contact) {
            if (self::boolValue($contact['defaultContact'] ?? false)) {
                return $contact;
            }
        }

        return $contacts[0] ?? [];
    }

    /**
     * @param list<mixed> $values
     */
    private static function firstNonEmpty(array $values): string {
        foreach ($values as $value) {
            $string = trim(scalar_string($value));
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function stringValue(array $row, string $key): string {
        return scalar_string($row[$key] ?? '');
    }

    private static function boolValue(mixed $value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        $normalized = strtolower(trim(scalar_string($value)));
        return in_array($normalized, ['1', 'true', 'yes', 'y'], true);
    }

    private static function isListArray(array $value): bool {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    private static function listOfAssoc(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $assoc = [];
                foreach ($row as $key => $item) {
                    $assoc[(string) $key] = $item;
                }
                $rows[] = $assoc;
            }
        }

        return $rows;
    }
}
