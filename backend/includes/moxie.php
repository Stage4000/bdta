<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/settings.php';

/** @phpstan-type NormalizedClient array{name:string, email:string, phone:string, address:string, notes:string, moxie_client_id:string, archived:bool} */
class MoxieClientSync {
    private const DEFAULT_PAGE_SIZE = 100;
    private const MAX_PAGES = 100;
    private const LOG_INIT_RETRY_INTERVAL = 60; // wait this long before retrying log setup after a failure
    /** @var list<string> Endpoint order: /client/list first, /clients/list as fallback for legacy workspaces. */
    private const CLIENT_LIST_PATHS = [
        '/api/public/client/list',
        '/api/public/clients/list',
    ];

    private static ?string $initializedLogFile = null;
    private static ?int $lastLogInitializationFailureAt = null;
    private static ?string $lastLogInitializationFailureMessage = null;

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
        try {
            return self::normalizeBaseUrl(scalar_string(Settings::get('moxie_base_url', '')));
        } catch (InvalidArgumentException $e) {
            error_log('Invalid stored Moxie base URL ignored: ' . $e->getMessage());
            return '';
        }
    }

    public static function getConfiguredApiKey(): string {
        return trim(scalar_string(Settings::get('moxie_api_key', '')));
    }

    public static function normalizeBaseUrl(string $base_url): string {
        $base_url = trim($base_url);
        if ($base_url === '') {
            return '';
        }

        if (!str_contains($base_url, '://')) {
            $base_url = 'https://' . ltrim($base_url, '/');
        }

        $parts = parse_url($base_url);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('Enter a valid Moxie workspace URL.');
        }

        $scheme = strtolower(scalar_string($parts['scheme'] ?? ''));
        $host = strtolower(scalar_string($parts['host'] ?? ''));
        $path = trim(scalar_string($parts['path'] ?? ''));
        $query = scalar_string($parts['query'] ?? '');
        $fragment = scalar_string($parts['fragment'] ?? '');
        $user = scalar_string($parts['user'] ?? '');
        $pass = scalar_string($parts['pass'] ?? '');
        $port = scalar_string($parts['port'] ?? '');

        if ($scheme !== 'https' || $host === '') {
            throw new InvalidArgumentException('Use an HTTPS Moxie workspace URL.');
        }

        if ($user !== '' || $pass !== '' || $port !== '' || $query !== '' || $fragment !== '' || ($path !== '' && $path !== '/')) {
            throw new InvalidArgumentException('Use only the Moxie workspace origin, without paths, ports, query strings, or credentials.');
        }

        if (!self::isAllowedMoxieHost($host)) {
            throw new InvalidArgumentException('Use a Moxie workspace host ending in withmoxie.dev or withmoxie.com.');
        }

        return 'https://' . $host;
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function log(string $message, array $context = []): void {
        $line = '[' . gmdate('Y-m-d H:i:s') . " UTC] " . $message;
        if (!empty($context)) {
            $context_json = json_encode($context, JSON_UNESCAPED_SLASHES);
            if ($context_json !== false) {
                $line .= ' ' . $context_json;
            }
        }

        try {
            $log_file = self::getInitializedLogFile();
            if ($log_file === null) {
                $reason = self::$lastLogInitializationFailureMessage ?? 'Moxie log initialization failed and the retry interval has not elapsed.';
                error_log($line . ' [moxie_log_error: ' . $reason . ']');
                return;
            }

            if (file_put_contents($log_file, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Unable to write to the Moxie log file.');
            }
        } catch (Throwable $e) {
            error_log($line . ' [moxie_log_error: ' . $e->getMessage() . ']');
        }
    }

    private static function getInitializedLogFile(): ?string {
        if (self::$initializedLogFile !== null) {
            return self::$initializedLogFile;
        }

        if (
            self::$lastLogInitializationFailureAt !== null
            && (time() - self::$lastLogInitializationFailureAt) < self::LOG_INIT_RETRY_INTERVAL
        ) {
            return null;
        }

        try {
            $log_file = self::getLogFilePath();
            $log_dir = dirname($log_file);

            if (!is_dir($log_dir) && !mkdir($log_dir, 0750, true) && !is_dir($log_dir)) {
                throw new RuntimeException('Unable to create the Moxie log directory.');
            }
            self::ensureLogDirectoryPermissions($log_dir);

            if (!file_exists($log_file) && @touch($log_file) === false) {
                throw new RuntimeException('Unable to create the Moxie log file.');
            }
            self::ensureLogFilePermissions($log_file);

            self::$initializedLogFile = $log_file;
            self::$lastLogInitializationFailureAt = null;
            self::$lastLogInitializationFailureMessage = null;
        } catch (Throwable $e) {
            self::markLogInitializationFailed($e->getMessage());
            error_log('Moxie log initialization failed: ' . $e->getMessage());
            return null;
        }

        return self::$initializedLogFile;
    }

    private static function markLogInitializationFailed(string $message): void {
        self::$initializedLogFile = null;
        self::$lastLogInitializationFailureAt = time();
        self::$lastLogInitializationFailureMessage = $message;
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
        $started_transaction = false;

        try {
            $this->conn->beginTransaction();
            $started_transaction = true;

            foreach ($raw_clients as $raw_client) {
                $client = self::normalizeClient($raw_client);

                if ($client['archived']) {
                    $summary['skipped_archived']++;
                    self::log('Skipping archived Moxie client.', [
                        'moxie_client_id' => $client['moxie_client_id'],
                    ]);
                    continue;
                }

                if ($client['email'] === '') {
                    $summary['skipped_missing_email']++;
                    self::log('Skipping Moxie client without email.', [
                        'moxie_client_id' => $client['moxie_client_id'],
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
                    ]);
                    continue;
                }

                $email = $client['email'];
                $merged = [
                    'name' => $client['name'] !== '' ? $client['name'] : self::stringValue($existing, 'name'),
                    'email' => $email,
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
                ]);
            }

            $this->conn->commit();
            return $summary;
        } catch (Throwable $e) {
            if ($started_transaction && $this->conn->inTransaction()) {
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
        $base_url = self::normalizeBaseUrl($base_url);
        if ($base_url === '') {
            throw new InvalidArgumentException('Moxie base URL is required.');
        }

        if ($page_size < 1) {
            throw new InvalidArgumentException('Moxie page size must be at least 1.');
        }

        $list_paths = self::CLIENT_LIST_PATHS;
        $path_count = count($list_paths);
        if ($path_count < 1) {
            throw new RuntimeException('No Moxie client list endpoints are configured.');
        }
        $last_exception = null;

        foreach ($list_paths as $path_index => $list_path) {
            $list_url = $base_url . $list_path;
            $clients = [];
            $page = 0;
            $start = 0;
            $count = $page_size;
            $completed_pagination = false;

            try {
                while ($page < self::MAX_PAGES) {
                    $page++;
                    self::log('Fetching Moxie client page.', [
                        'url' => $list_url,
                        'page' => $page,
                        'start' => $start,
                        'count' => $count,
                    ]);
                    $response = $this->requestJson($list_url, $api_key, [
                        'start' => $start,
                        'count' => $count,
                    ]);
                    $page_clients = self::extractClientRows($response);
                    foreach ($page_clients as $page_client) {
                        $clients[] = $page_client;
                    }

                    $response_next = self::extractNextUrl($response, $base_url);
                    if ($response_next !== '') {
                        ['start' => $start, 'count' => $count] = self::extractNextPageRequest($response_next, $start + $count, $page_size);
                        continue;
                    }

                    if (count($page_clients) < $count) {
                        $completed_pagination = true;
                        break;
                    }

                    $start += $count;
                    $count = $page_size;
                }
            } catch (RuntimeException $e) {
                if ($path_index < $path_count - 1 && $page === 1 && self::isHttpStatusException($e, 404)) {
                    self::log('Moxie client list endpoint returned 404; retrying alternate endpoint.', [
                        'failed_url' => $list_url,
                        'retry_url' => $base_url . $list_paths[$path_index + 1],
                    ]);
                    continue;
                }

                $last_exception = $e;
                break;
            }

            if (!$completed_pagination && $page >= self::MAX_PAGES) {
                self::log('Moxie client sync aborted after reaching the maximum page limit.', [
                    'max_pages' => self::MAX_PAGES,
                    'last_url' => $list_url,
                    'start' => $start,
                    'count' => $count,
                ]);
                throw new RuntimeException('Moxie client sync stopped after reaching the maximum page limit. Please narrow the import or increase the page limit in code.');
            }

            return $clients;
        }

        if ($last_exception !== null) {
            throw $last_exception;
        }

        throw new RuntimeException('Moxie client sync failed before any pages could be fetched.');
    }

    /**
     * @param array<int|string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    public static function extractClientRows(array $payload): array {
        if (self::isListArray($payload)) {
            return self::listOfAssoc($payload);
        }

        $embedded = self::arrayValue($payload, '_embedded');
        $candidates = [
            is_array($embedded) ? ($embedded['clients'] ?? null) : null,
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
     * @param array<int|string, mixed> $payload
     */
    public static function extractNextUrl(array $payload, string $base_url): string {
        $links = self::arrayValue($payload, '_links');
        $legacy_links = self::arrayValue($payload, 'links');
        $links_next = is_array($links) ? self::arrayValue($links, 'next') : null;
        $legacy_links_next = is_array($legacy_links) ? self::arrayValue($legacy_links, 'next') : null;

        $next = (is_array($links_next) ? ($links_next['href'] ?? null) : null)
            ?? (is_array($legacy_links_next) ? ($legacy_links_next['href'] ?? null) : null)
            ?? ($payload['next'] ?? '');
        $next = trim(is_string($next) ? $next : '');

        if ($next === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $next)) {
            $base_parts = parse_url($base_url);
            $next_parts = parse_url($next);

            if (!is_array($base_parts) || !is_array($next_parts)) {
                throw new RuntimeException('Moxie pagination returned an invalid next URL.');
            }

            $base_scheme = strtolower(scalar_string($base_parts['scheme'] ?? ''));
            $base_host = strtolower(scalar_string($base_parts['host'] ?? ''));
            $next_scheme = strtolower(scalar_string($next_parts['scheme'] ?? ''));
            $next_host = strtolower(scalar_string($next_parts['host'] ?? ''));

            if ($base_scheme !== 'https' || $base_host === '') {
                throw new RuntimeException('Moxie pagination validation requires a valid HTTPS workspace origin.');
            }

            if ($next_host === '') {
                throw new RuntimeException('Moxie pagination returned a next URL without a host.');
            }

            if ($next_scheme !== $base_scheme || $next_host !== $base_host) {
                throw new RuntimeException('Moxie pagination returned a next URL outside the configured workspace origin.');
            }

            return $next;
        }

        return rtrim($base_url, '/') . '/' . ltrim($next, '/');
    }

    /**
     * @return array{start:int, count:int}
     */
    private static function extractNextPageRequest(string $next_url, int $fallback_start, int $fallback_count): array {
        $parts = parse_url($next_url);
        if (!is_array($parts)) {
            throw new RuntimeException('Moxie pagination returned an invalid next URL.');
        }

        $query = scalar_string($parts['query'] ?? '');
        if ($query === '') {
            return [
                'start' => $fallback_start,
                'count' => $fallback_count,
            ];
        }

        parse_str($query, $params);
        $start = scalar_string($params['start'] ?? '');
        $count = scalar_string($params['count'] ?? '');

        $validated_start = filter_var($start, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($validated_start === false) {
            throw new RuntimeException('Moxie pagination returned an invalid next start offset.');
        }

        $validated_count = filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validated_count === false) {
            throw new RuntimeException('Moxie pagination returned an invalid next page size.');
        }

        return [
            'start' => $validated_start,
            'count' => $validated_count,
        ];
    }

    /**
     * @param array<string, mixed> $raw_client
     * @return NormalizedClient
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
     * @param NormalizedClient $client
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
     * @param array<string, mixed>|null $payload
     * @return array<int|string, mixed>
     */
    protected function requestJson(string $url, string $api_key, ?array $payload = null): array {
        $this->assertAllowedRequestUrl($url);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL for Moxie request.');
        }

        $headers = [
            'Accept: application/json',
            'X-API-KEY: ' . $api_key,
        ];
        $encoded_payload = null;
        if ($payload !== null) {
            $encoded_payload = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($encoded_payload === false) {
                throw new RuntimeException('Unable to encode the Moxie request payload as JSON.');
            }

            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($encoded_payload !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded_payload);
        }

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Moxie request failed: ' . $curl_error);
        }

        if ($http_code < 200 || $http_code >= 300) {
            self::log('Moxie returned non-success status.', ['status' => $http_code, 'url' => $url]);
            throw new RuntimeException('Moxie request failed with HTTP status ' . $http_code . '.');
        }

        $decoded = json_decode(scalar_string($response), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Moxie response could not be decoded as JSON.');
        }

        /** @var array<int|string, mixed> $decoded */
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

    /**
     * @param array<int|string, mixed> $row
     * @return array<int|string, mixed>|null
     */
    private static function arrayValue(array $row, string $key): ?array {
        $value = $row[$key] ?? null;
        return is_array($value) ? $value : null;
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private static function isListArray(array $value): bool {
        if (function_exists('array_is_list')) {
            return array_is_list($value);
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private static function isHttpStatusException(Throwable $exception, int $status): bool {
        return preg_match('/HTTP status (\d+)/', $exception->getMessage(), $matches) === 1
            && safe_int($matches[1] ?? 0) === $status;
    }

    private static function isAllowedMoxieHost(string $host): bool {
        $allowed_suffixes = ['withmoxie.dev', 'withmoxie.com'];
        foreach ($allowed_suffixes as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    private static function ensureLogDirectoryPermissions(string $path): void {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        $permissions_acceptable = self::directoryPermissionsAcceptable($path);
        if ($permissions_acceptable) {
            return;
        }

        $chmod_success = chmod($path, 0750);
        clearstatcache(true, $path);
        $permissions_acceptable = self::directoryPermissionsAcceptable($path);

        if (!$permissions_acceptable) {
            throw new RuntimeException($chmod_success
                ? 'Unable to secure the Moxie log directory permissions even though chmod() reported success.'
                : 'Unable to secure the Moxie log directory permissions because chmod() failed.');
        }
    }

    private static function ensureLogFilePermissions(string $path): void {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        $permissions_acceptable = self::filePermissionsAcceptable($path);
        if ($permissions_acceptable) {
            return;
        }

        $chmod_success = chmod($path, 0600);
        clearstatcache(true, $path);
        $permissions_acceptable = self::filePermissionsAcceptable($path);

        if (!$permissions_acceptable) {
            throw new RuntimeException($chmod_success
                ? 'Unable to secure the Moxie log file permissions even though chmod() reported success.'
                : 'Unable to secure the Moxie log file permissions because chmod() failed.');
        }
    }

    private static function directoryPermissionsAcceptable(string $path): bool {
        $perms = self::readPermissions($path);
        if ($perms === null) {
            return false;
        }

        return ($perms & 0700) === 0700 && ($perms & 0020) === 0 && ($perms & 0007) === 0;
    }

    private static function filePermissionsAcceptable(string $path): bool {
        $perms = self::readPermissions($path);
        if ($perms === null) {
            return false;
        }

        return ($perms & 0600) === 0600 && ($perms & 0077) === 0;
    }

    private static function readPermissions(string $path): ?int {
        clearstatcache(true, $path);
        $perms = fileperms($path);
        if ($perms === false) {
            return null;
        }

        return $perms & 0777;
    }

    private function assertAllowedRequestUrl(string $url): void {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new RuntimeException('Moxie request URL is invalid.');
        }

        $scheme = strtolower(scalar_string($parts['scheme'] ?? ''));
        $host = strtolower(scalar_string($parts['host'] ?? ''));
        $user = scalar_string($parts['user'] ?? '');
        $pass = scalar_string($parts['pass'] ?? '');
        $port = scalar_string($parts['port'] ?? '');

        if ($scheme !== 'https' || $host === '' || !self::isAllowedMoxieHost($host)) {
            throw new RuntimeException('Moxie requests must use an allowed HTTPS Moxie host.');
        }

        if ($user !== '' || $pass !== '' || $port !== '') {
            throw new RuntimeException('Moxie request URLs must not contain credentials or custom ports.');
        }
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
