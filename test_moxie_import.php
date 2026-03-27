#!/usr/bin/env php
<?php
require_once __DIR__ . '/backend/includes/database.php';
require_once __DIR__ . '/backend/includes/moxie.php';

echo "=== Moxie Import Test ===\n\n";

try {
    $db = new Database();
    $conn = $db->getConnection();
    $sync = new MoxieClientSync($conn);
    $test_suffix = bin2hex(random_bytes(4));
    $primary_client_id = 'test-moxie-' . $test_suffix;
    $archived_client_id = 'test-moxie-archived-' . $test_suffix;
    $missing_email_client_id = 'test-moxie-no-email-' . $test_suffix;
    $primary_email = 'jane.doe.' . $test_suffix . '@example.invalid';
    $existing_archived_client_email = 'archived.local.' . $test_suffix . '@example.invalid';
    $existing_archived_moxie_client_id = 'test-moxie-existing-archived-' . $test_suffix;
    $primary_invoice_id = 'test-moxie-invoice-primary-' . $test_suffix;
    $existing_archived_invoice_id = 'test-moxie-invoice-archived-' . $test_suffix;
    $missing_client_invoice_id = 'test-moxie-invoice-missing-' . $test_suffix;

    $first_pass = [
        [
            'id' => $primary_client_id,
            'name' => 'Acme Corporation',
            'phone' => '+1-555-1000',
            'address1' => '123 Main St',
            'city' => 'Metropolis',
            'locality' => 'FL',
            'postal' => '33870',
            'country' => 'USA',
            'notes' => 'Priority client',
            'contacts' => [
                [
                    'firstName' => 'Jane',
                    'lastName' => 'Doe',
                    'email' => $primary_email,
                    'phone' => '+1-555-2000',
                    'defaultContact' => true,
                ],
            ],
        ],
        [
            'id' => $archived_client_id,
            'name' => 'Archived Client',
            'archive' => true,
            'contacts' => [
                [
                    'email' => 'archived.' . $test_suffix . '@example.invalid',
                    'defaultContact' => true,
                ],
            ],
        ],
        [
            'id' => $missing_email_client_id,
            'name' => 'No Email Client',
        ],
    ];

    $result = $sync->syncClientRows($first_pass);
    if ($result['created'] !== 1 || $result['skipped_archived'] !== 1 || $result['skipped_missing_email'] !== 1) {
        throw new RuntimeException('Unexpected first sync result: ' . json_encode($result));
    }

    $stmt = $conn->prepare("SELECT name, email, phone, address, notes, moxie_client_id FROM clients WHERE moxie_client_id = ?");
    $stmt->execute([$primary_client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client)) {
        throw new RuntimeException('Imported client was not created.');
    }

    if (scalar_string($client['email'] ?? '') !== $primary_email) {
        throw new RuntimeException('Default contact email was not imported.');
    }

    if (scalar_string($client['address'] ?? '') !== '123 Main St, Metropolis, FL, 33870, USA') {
        throw new RuntimeException('Address was not normalized as expected.');
    }

    $normalized_base_url = MoxieClientSync::normalizeBaseUrl('pod00.withmoxie.dev');
    if ($normalized_base_url !== 'https://pod00.withmoxie.dev') {
        throw new RuntimeException('Expected Moxie base URL to normalize to HTTPS workspace origin.');
    }

    $invalid_base_urls = [
        'http://pod00.withmoxie.dev',
        'https://example.com',
        'https://pod00.withmoxie.dev/path',
        'https://user:pass@pod00.withmoxie.dev',
    ];

    foreach ($invalid_base_urls as $invalid_base_url) {
        try {
            MoxieClientSync::normalizeBaseUrl($invalid_base_url);
            throw new RuntimeException('Expected invalid Moxie base URL to be rejected: ' . $invalid_base_url);
        } catch (InvalidArgumentException $e) {
            // Expected path
        }
    }

    try {
        $sync->fetchClients('', 'test-api-key');
        throw new RuntimeException('Expected fetchClients() to reject an empty base URL.');
    } catch (InvalidArgumentException $e) {
        // Expected path
    }

    try {
        $sync->fetchClients($normalized_base_url, 'test-api-key', 0);
        throw new RuntimeException('Expected fetchClients() to reject a page size smaller than 1.');
    } catch (InvalidArgumentException $e) {
        // Expected path
    }

    $absolute_next = MoxieClientSync::extractNextUrl([
        '_links' => [
            'next' => ['href' => 'https://pod00.withmoxie.dev/api/public/clients/list?start=100&count=100'],
        ],
    ], $normalized_base_url);
    if ($absolute_next !== 'https://pod00.withmoxie.dev/api/public/clients/list?start=100&count=100') {
        throw new RuntimeException('Expected same-origin absolute pagination URL to be accepted.');
    }

    foreach ([
        'http://pod00.withmoxie.dev/api/public/clients/list?start=100&count=100',
        'https://pod01.withmoxie.dev/api/public/clients/list?start=100&count=100',
        'http://pod01.withmoxie.dev/api/public/clients/list?start=100&count=100',
    ] as $invalid_next_url) {
        try {
            MoxieClientSync::extractNextUrl([
                '_links' => [
                    'next' => ['href' => $invalid_next_url],
                ],
            ], $normalized_base_url);
            throw new RuntimeException('Expected invalid absolute pagination URL to be rejected: ' . $invalid_next_url);
        } catch (RuntimeException $e) {
            // Expected path
        }
    }

    $second_pass = [
        [
            'id' => $primary_client_id,
            'name' => 'Acme Corporation Updated',
            'phone' => '+1-555-9999',
            'notes' => 'Updated by Moxie',
            'contacts' => [
                [
                    'firstName' => 'Jane',
                    'lastName' => 'Doe',
                    'email' => $primary_email,
                    'defaultContact' => true,
                ],
            ],
        ],
    ];

    $result = $sync->syncClientRows($second_pass);
    if ($result['updated'] !== 1) {
        throw new RuntimeException('Expected one updated client on second sync: ' . json_encode($result));
    }

    $stmt->execute([$primary_client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client) || scalar_string($client['name'] ?? '') !== 'Acme Corporation Updated') {
        throw new RuntimeException('Existing client was not updated.');
    }

    $result = $sync->syncClientRows($second_pass);
    if ($result['unchanged'] !== 1) {
        throw new RuntimeException('Expected unchanged client on identical sync: ' . json_encode($result));
    }

    $create_existing_client = $conn->prepare("
        INSERT INTO clients (name, email, phone, address, notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    $create_existing_client->execute([
        'Archived Existing Client',
        $existing_archived_client_email,
        '+1-555-3000',
        '456 Oak St',
        'Existing local client for archived Moxie invoices',
    ]);
    $existing_archived_client_db_id = scalar_string($conn->lastInsertId());

    $invoice_pass = [
        [
            'id' => $primary_invoice_id,
            'invoiceNumberFormatted' => 'MOX-' . $test_suffix . '-001',
            'clientId' => $primary_client_id,
            'dateCreated' => '2024-01-10',
            'dateDue' => '2024-01-20',
            'datePaid' => '2024-01-18',
            'status' => 'SENT',
            'subTotal' => 100,
            'tax' => 7,
            'total' => 107,
            'amountDue' => 107,
            'clientInfo' => [
                'id' => $primary_client_id,
                'name' => 'Acme Corporation Updated',
                'phone' => '+1-555-9999',
                'contact' => [
                    'email' => $primary_email,
                    'phone' => '+1-555-9999',
                ],
            ],
        ],
        [
            'id' => $existing_archived_invoice_id,
            'invoiceNumberFormatted' => 'MOX-' . $test_suffix . '-002',
            'clientId' => $existing_archived_moxie_client_id,
            'dateCreated' => '2024-02-01',
            'dateDue' => '2024-02-15',
            'status' => 'PARTIAL',
            'subTotal' => 200,
            'tax' => 0,
            'total' => 200,
            'amountDue' => 50,
            'payments' => [
                [
                    'paymentProvider' => 'CHECK',
                    'datePaid' => '2024-02-10',
                ],
            ],
            'clientInfo' => [
                'id' => $existing_archived_moxie_client_id,
                'name' => 'Archived Existing Client',
                'phone' => '+1-555-3000',
                'contact' => [
                    'email' => $existing_archived_client_email,
                    'phone' => '+1-555-3000',
                ],
            ],
        ],
        [
            'id' => $missing_client_invoice_id,
            'invoiceNumberFormatted' => 'MOX-' . $test_suffix . '-003',
            'clientId' => 'missing-client-' . $test_suffix,
            'dateCreated' => '2024-03-01',
            'dateDue' => '2024-03-15',
            'status' => 'SENT',
            'subTotal' => 50,
            'tax' => 0,
            'total' => 50,
            'amountDue' => 50,
            'clientInfo' => [
                'id' => 'missing-client-' . $test_suffix,
                'name' => 'Missing Client',
                'contact' => [
                    'email' => 'missing.' . $test_suffix . '@example.invalid',
                ],
            ],
        ],
    ];

    $invoice_result = $sync->syncInvoiceRows($invoice_pass);
    if ($invoice_result['created'] !== 2 || $invoice_result['skipped_missing_client'] !== 1) {
        throw new RuntimeException('Unexpected invoice sync result: ' . json_encode($invoice_result));
    }

    $invoice_stmt = $conn->prepare("
        SELECT id, invoice_number, client_id, subtotal, tax_rate, tax_amount, total_amount, status, payment_method, payment_date
        FROM invoices
        WHERE moxie_invoice_id = ?
    ");
    $invoice_stmt->execute([$primary_invoice_id]);
    $primary_invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($primary_invoice)) {
        throw new RuntimeException('Primary Moxie invoice was not created.');
    }
    if (scalar_string($primary_invoice['invoice_number'] ?? '') !== 'MOX-' . $test_suffix . '-001') {
        throw new RuntimeException('Primary Moxie invoice number was not imported.');
    }
    if (scalar_string($primary_invoice['status'] ?? '') !== 'paid') {
        throw new RuntimeException('Expected invoices with an invoice-level paid date to import as paid.');
    }
    if (scalar_string($primary_invoice['payment_date'] ?? '') !== '2024-01-18') {
        throw new RuntimeException('Expected invoice-level paid date to be imported.');
    }

    $invoice_stmt->execute([$existing_archived_invoice_id]);
    $archived_existing_invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($archived_existing_invoice)) {
        throw new RuntimeException('Invoice for existing archived Moxie client was not created.');
    }
    if (scalar_string($archived_existing_invoice['client_id'] ?? '') !== $existing_archived_client_db_id) {
        throw new RuntimeException('Invoice for existing archived Moxie client did not attach to the existing BDTA client.');
    }
    if (scalar_string($archived_existing_invoice['payment_method'] ?? '') !== 'check') {
        throw new RuntimeException('Imported invoice payment provider was not normalized.');
    }
    if (scalar_string($archived_existing_invoice['status'] ?? '') !== 'overdue') {
        throw new RuntimeException('Expected partially paid past-due Moxie invoices without invoice-level paid dates to remain overdue.');
    }

    $archived_existing_client_stmt = $conn->prepare("SELECT moxie_client_id FROM clients WHERE id = ?");
    $archived_existing_client_stmt->execute([$existing_archived_client_db_id]);
    $archived_existing_client = $archived_existing_client_stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($archived_existing_client) || scalar_string($archived_existing_client['moxie_client_id'] ?? '') !== $existing_archived_moxie_client_id) {
        throw new RuntimeException('Existing BDTA client was not linked to the archived Moxie client during invoice import.');
    }

    $updated_invoice_pass = [
        [
            'id' => $primary_invoice_id,
            'invoiceNumberFormatted' => 'MOX-' . $test_suffix . '-001',
            'clientId' => $primary_client_id,
            'dateCreated' => '2024-01-10',
            'dateDue' => '2024-01-20',
            'datePaid' => '2024-01-18',
            'status' => 'PAID',
            'subTotal' => 120,
            'tax' => 12,
            'total' => 132,
            'amountDue' => 0,
            'payments' => [
                [
                    'paymentProvider' => 'STRIPE',
                    'datePaid' => '2024-01-18',
                ],
            ],
            'clientInfo' => [
                'id' => $primary_client_id,
                'name' => 'Acme Corporation Updated',
                'phone' => '+1-555-9999',
                'contact' => [
                    'email' => $primary_email,
                    'phone' => '+1-555-9999',
                ],
            ],
        ],
    ];

    $invoice_result = $sync->syncInvoiceRows($updated_invoice_pass);
    if ($invoice_result['updated'] !== 1) {
        throw new RuntimeException('Expected one updated invoice on second invoice sync: ' . json_encode($invoice_result));
    }

    $invoice_stmt->execute([$primary_invoice_id]);
    $primary_invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($primary_invoice)
        || scalar_string($primary_invoice['status'] ?? '') !== 'paid'
        || scalar_string($primary_invoice['payment_method'] ?? '') !== 'stripe'
        || scalar_string($primary_invoice['payment_date'] ?? '') !== '2024-01-18'
    ) {
        throw new RuntimeException('Imported invoice updates were not applied.');
    }

    $invoice_result = $sync->syncInvoiceRows($updated_invoice_pass);
    if ($invoice_result['unchanged'] !== 1) {
        throw new RuntimeException('Expected unchanged invoice on identical invoice sync: ' . json_encode($invoice_result));
    }

    $request_calls = [];
    $fetch_test_sync = new class($conn, $request_calls) extends MoxieClientSync {
        /** @var array<int, array{url:string, api_key:string, payload:array<string, int>|null}> */
        private array $captured_calls;

        /**
         * @param array<int, array{url:string, api_key:string, payload:array<string, int>|null}> $captured_calls
         */
        public function __construct(SafePDO $conn, array &$captured_calls) {
            parent::__construct($conn);
            $this->captured_calls = &$captured_calls;
        }

        /**
         * @param array<string, int>|null $payload
         * @return array<int|string, mixed>
         */
        protected function requestJson(string $url, string $api_key, ?array $payload = null): array {
            $this->captured_calls[] = [
                'url' => $url,
                'api_key' => $api_key,
                'payload' => $payload,
            ];

            if (count($this->captured_calls) === 1) {
                return [
                    'clients' => [
                        ['id' => 'page-1'],
                    ],
                    '_links' => [
                        'next' => ['href' => 'https://pod00.withmoxie.dev/api/public/clients/list?start=100&count=25'],
                    ],
                ];
            }

            return [
                'clients' => [
                    ['id' => 'page-2'],
                ],
            ];
        }
    };

    $fetched_clients = $fetch_test_sync->fetchClients($normalized_base_url, 'test-api-key', 100);
    if (count($fetched_clients) !== 2) {
        throw new RuntimeException('Expected fetchClients() to aggregate both mocked pages.');
    }

    if ($request_calls !== [
        [
            'url' => 'https://pod00.withmoxie.dev/api/public/action/clients/list',
            'api_key' => 'test-api-key',
            'payload' => null,
        ],
        [
            'url' => 'https://pod00.withmoxie.dev/api/public/clients/list?start=100&count=25',
            'api_key' => 'test-api-key',
            'payload' => null,
        ],
    ]) {
        throw new RuntimeException('Expected fetchClients() to GET the primary client list endpoint and follow the pagination URL returned by Moxie: ' . json_encode($request_calls));
    }

    $no_next_page_size = 100;
    $no_next_request_calls = [];
    $no_next_sync = new class($conn, $no_next_request_calls, $no_next_page_size) extends MoxieClientSync {
        /** @var array<int, array{url:string, api_key:string, payload:array<string, int>|null}> */
        private array $captured_calls;
        private int $pageSize;

        /**
         * @param array<int, array{url:string, api_key:string, payload:array<string, int>|null}> $captured_calls
         */
        public function __construct(SafePDO $conn, array &$captured_calls, int $page_size) {
            parent::__construct($conn);
            $this->captured_calls = &$captured_calls;
            $this->pageSize = $page_size;
        }

        /**
         * @param array<string, int>|null $payload
         * @return array<int|string, mixed>
         */
        protected function requestJson(string $url, string $api_key, ?array $payload = null): array {
            $this->captured_calls[] = [
                'url' => $url,
                'api_key' => $api_key,
                'payload' => $payload,
            ];

            return [
                'clients' => array_map(
                    static fn (int $index): array => ['id' => 'client-' . $index],
                    range(1, $this->pageSize)
                ),
            ];
        }
    };

    $no_next_clients = $no_next_sync->fetchClients($normalized_base_url, 'test-api-key', $no_next_page_size);
    if (count($no_next_clients) !== $no_next_page_size) {
        throw new RuntimeException('Expected fetchClients() to return the first GET page when Moxie does not return a next URL.');
    }

    if ($no_next_request_calls !== [
        [
            'url' => 'https://pod00.withmoxie.dev/api/public/action/clients/list',
            'api_key' => 'test-api-key',
            'payload' => null,
        ],
    ]) {
        throw new RuntimeException('Expected fetchClients() to stop GET pagination when Moxie does not return a next URL: ' . json_encode($no_next_request_calls));
    }

    $fallback_request_calls = [];
    $fallback_test_sync = new class($conn, $fallback_request_calls) extends MoxieClientSync {
        /** @var array<int, array{url:string, api_key:string, payload:array<string, int>|null}> */
        private array $captured_calls;

        /**
         * @param array<int, array{url:string, api_key:string, payload:array<string, int>|null}> $captured_calls
         */
        public function __construct(SafePDO $conn, array &$captured_calls) {
            parent::__construct($conn);
            $this->captured_calls = &$captured_calls;
        }

        /**
         * @param array<string, int>|null $payload
         * @return array<int|string, mixed>
         */
        protected function requestJson(string $url, string $api_key, ?array $payload = null): array {
            $this->captured_calls[] = [
                'url' => $url,
                'api_key' => $api_key,
                'payload' => $payload,
            ];

            if (count($this->captured_calls) === 1) {
                throw new RuntimeException('Moxie request failed with HTTP status 404.');
            }

            if (count($this->captured_calls) === 2) {
                return [
                    'clients' => [
                        ['id' => 'fallback-page-1'],
                    ],
                    '_links' => [
                        'next' => ['href' => 'https://pod00.withmoxie.dev/api/public/client/list?start=100&count=50'],
                    ],
                ];
            }

            return [
                'clients' => [
                    ['id' => 'fallback-page-2'],
                ],
            ];
        }
    };

    $fallback_clients = $fallback_test_sync->fetchClients($normalized_base_url, 'test-api-key', 100);
    if (count($fallback_clients) !== 2) {
        throw new RuntimeException('Expected fetchClients() to retry an alternate endpoint after a 404 and continue fallback pagination.');
    }

    if ($fallback_request_calls !== [
        [
            'url' => 'https://pod00.withmoxie.dev/api/public/action/clients/list',
            'api_key' => 'test-api-key',
            'payload' => null,
        ],
        [
            'url' => 'https://pod00.withmoxie.dev/api/public/client/list',
            'api_key' => 'test-api-key',
            'payload' => ['start' => 0, 'count' => 100],
        ],
        [
            'url' => 'https://pod00.withmoxie.dev/api/public/client/list',
            'api_key' => 'test-api-key',
            'payload' => ['start' => 100, 'count' => 50],
        ],
    ]) {
        throw new RuntimeException('Expected fetchClients() to retry and continue using the first legacy list endpoint after a 404: ' . json_encode($fallback_request_calls));
    }

    $server_error_fallback_calls = [];
    $server_error_fallback_sync = new class($conn, $server_error_fallback_calls) extends MoxieClientSync {
        /** @var array<int, array{url:string, api_key:string, payload:array<string, int>|null}> */
        private array $captured_calls;

        /**
         * @param array<int, array{url:string, api_key:string, payload:array<string, int>|null}> $captured_calls
         */
        public function __construct(SafePDO $conn, array &$captured_calls) {
            parent::__construct($conn);
            $this->captured_calls = &$captured_calls;
        }

        /**
         * @param array<string, int>|null $payload
         * @return array<int|string, mixed>
         */
        protected function requestJson(string $url, string $api_key, ?array $payload = null): array {
            $this->captured_calls[] = [
                'url' => $url,
                'api_key' => $api_key,
                'payload' => $payload,
            ];

            if (count($this->captured_calls) === 1) {
                throw new RuntimeException('Moxie request failed with HTTP status 500.');
            }

            return [
                'clients' => [
                    ['id' => 'server-error-fallback-page-1'],
                ],
            ];
        }
    };

    $server_error_fallback_clients = $server_error_fallback_sync->fetchClients($normalized_base_url, 'test-api-key', 100);
    if (count($server_error_fallback_clients) !== 1) {
        throw new RuntimeException('Expected fetchClients() to retry an alternate endpoint after a 500 on the first page.');
    }

    if ($server_error_fallback_calls !== [
        [
            'url' => 'https://pod00.withmoxie.dev/api/public/action/clients/list',
            'api_key' => 'test-api-key',
            'payload' => null,
        ],
        [
            'url' => 'https://pod00.withmoxie.dev/api/public/client/list',
            'api_key' => 'test-api-key',
            'payload' => ['start' => 0, 'count' => 100],
        ],
    ]) {
        throw new RuntimeException('Expected fetchClients() to retry the next endpoint after a 500 on the first page: ' . json_encode($server_error_fallback_calls));
    }

    $timeout_fallback_calls = [];
    $timeout_fallback_sync = new class($conn, $timeout_fallback_calls) extends MoxieClientSync {
        /** @var array<int, array{url:string, api_key:string, payload:array<string, int>|null}> */
        private array $captured_calls;

        /**
         * @param array<int, array{url:string, api_key:string, payload:array<string, int>|null}> $captured_calls
         */
        public function __construct(SafePDO $conn, array &$captured_calls) {
            parent::__construct($conn);
            $this->captured_calls = &$captured_calls;
        }

        /**
         * @param array<string, int>|null $payload
         * @return array<int|string, mixed>
         */
        protected function requestJson(string $url, string $api_key, ?array $payload = null): array {
            $this->captured_calls[] = [
                'url' => $url,
                'api_key' => $api_key,
                'payload' => $payload,
            ];

            if (count($this->captured_calls) === 1) {
                throw new RuntimeException('Moxie request failed: Operation timed out after 15000 milliseconds with 0 bytes received');
            }

            return [
                'clients' => [
                    ['id' => 'timeout-fallback-page-1'],
                ],
            ];
        }
    };

    $timeout_fallback_clients = $timeout_fallback_sync->fetchClients($normalized_base_url, 'test-api-key', 100);
    if (count($timeout_fallback_clients) !== 1) {
        throw new RuntimeException('Expected fetchClients() to retry an alternate endpoint after a first-page timeout.');
    }

    if ($timeout_fallback_calls !== [
        [
            'url' => 'https://pod00.withmoxie.dev/api/public/action/clients/list',
            'api_key' => 'test-api-key',
            'payload' => null,
        ],
        [
            'url' => 'https://pod00.withmoxie.dev/api/public/client/list',
            'api_key' => 'test-api-key',
            'payload' => ['start' => 0, 'count' => 100],
        ],
    ]) {
        throw new RuntimeException('Expected fetchClients() to retry the next endpoint after a first-page timeout: ' . json_encode($timeout_fallback_calls));
    }

    $rate_limited_request_calls = [];
    $rate_limited_retry_delays = [];
    $rate_limited_sync = new class($conn, $rate_limited_request_calls, $rate_limited_retry_delays) extends MoxieClientSync {
        /** @var array<int, array{url:string, api_key:string, payload:array<string, int>|null}> */
        private array $captured_calls;
        /** @var list<int> */
        private array $captured_retry_delays;

        /**
         * @param array<int, array{url:string, api_key:string, payload:array<string, int>|null}> $captured_calls
         * @param list<int> $captured_retry_delays
         */
        public function __construct(SafePDO $conn, array &$captured_calls, array &$captured_retry_delays) {
            parent::__construct($conn);
            $this->captured_calls = &$captured_calls;
            $this->captured_retry_delays = &$captured_retry_delays;
        }

        /**
         * @param array<string, int>|null $payload
         * @return array<int|string, mixed>
         */
        protected function requestJson(string $url, string $api_key, ?array $payload = null): array {
            $this->captured_calls[] = [
                'url' => $url,
                'api_key' => $api_key,
                'payload' => $payload,
            ];

            if (count($this->captured_calls) === 1) {
                return [
                    'clients' => [
                        ['id' => 'rate-limit-page-1'],
                    ],
                    '_links' => [
                        'next' => ['href' => 'https://pod00.withmoxie.dev/api/public/action/clients/list?start=100&count=100'],
                    ],
                ];
            }

            if (count($this->captured_calls) === 2) {
                throw new RuntimeException('Moxie request failed with HTTP status 429.');
            }

            return [
                'clients' => [
                    ['id' => 'rate-limit-page-2'],
                ],
            ];
        }

        protected function pauseBeforeRateLimitRetry(int $delay_seconds): void {
            $this->captured_retry_delays[] = $delay_seconds;
        }
    };

    $rate_limited_clients = $rate_limited_sync->fetchClients($normalized_base_url, 'test-api-key', 100);
    if (count($rate_limited_clients) !== 2) {
        throw new RuntimeException('Expected fetchClients() to retry a 429 response on the same page and continue pagination.');
    }

    if ($rate_limited_request_calls !== [
        [
            'url' => 'https://pod00.withmoxie.dev/api/public/action/clients/list',
            'api_key' => 'test-api-key',
            'payload' => null,
        ],
        [
            'url' => 'https://pod00.withmoxie.dev/api/public/action/clients/list?start=100&count=100',
            'api_key' => 'test-api-key',
            'payload' => null,
        ],
        [
            'url' => 'https://pod00.withmoxie.dev/api/public/action/clients/list?start=100&count=100',
            'api_key' => 'test-api-key',
            'payload' => null,
        ],
    ]) {
        throw new RuntimeException('Expected fetchClients() to retry a 429 response on the same paginated URL: ' . json_encode($rate_limited_request_calls));
    }

    if ($rate_limited_retry_delays !== [2]) {
        throw new RuntimeException('Expected fetchClients() to apply the initial rate-limit retry delay before retrying the same page: ' . json_encode($rate_limited_retry_delays));
    }

    $invoice_request_calls = [];
    $invoice_fetch_sync = new class($conn, $invoice_request_calls) extends MoxieClientSync {
        /** @var array<int, array{url:string, api_key:string, payload:array<string, int>|null}> */
        private array $captured_calls;

        /**
         * @param array<int, array{url:string, api_key:string, payload:array<string, int>|null}> $captured_calls
         */
        public function __construct(SafePDO $conn, array &$captured_calls) {
            parent::__construct($conn);
            $this->captured_calls = &$captured_calls;
        }

        /**
         * @param array<string, int>|null $payload
         * @return array<int|string, mixed>
         */
        protected function requestJson(string $url, string $api_key, ?array $payload = null): array {
            $this->captured_calls[] = [
                'url' => $url,
                'api_key' => $api_key,
                'payload' => $payload,
            ];

            if (count($this->captured_calls) === 1) {
                return [
                    'data' => [
                        [
                            'id' => 'invoice-fetch-1',
                            'clientId' => 'client-fetch-1',
                        ],
                    ],
                    '_links' => [
                        'next' => ['href' => 'https://pod00.withmoxie.dev/api/public/action/payableInvoices/search?start=100&count=100'],
                    ],
                ];
            }

            return [
                'data' => [
                    [
                        'id' => 'invoice-fetch-2',
                        'clientId' => 'client-fetch-2',
                    ],
                ],
            ];
        }
    };

    $fetched_invoices = $invoice_fetch_sync->fetchInvoices($normalized_base_url, 'test-api-key');
    if (count($fetched_invoices) !== 2) {
        throw new RuntimeException('Expected fetchInvoices() to return both mocked invoice pages.');
    }

    if ($invoice_request_calls !== [
        [
            'url' => 'https://pod00.withmoxie.dev/api/public/action/payableInvoices/search?start=0&count=100',
            'api_key' => 'test-api-key',
            'payload' => null,
        ],
        [
            'url' => 'https://pod00.withmoxie.dev/api/public/action/payableInvoices/search?start=100&count=100',
            'api_key' => 'test-api-key',
            'payload' => null,
        ],
    ]) {
        throw new RuntimeException('Expected fetchInvoices() to GET the payable invoices endpoint with explicit pagination and follow the pagination URL returned by Moxie: ' . json_encode($invoice_request_calls));
    }

    $invoice_request_calls_without_next = [];
    $invoice_fetch_sync_without_next = new class($conn, $invoice_request_calls_without_next) extends MoxieClientSync {
        /** @var array<int, array{url:string, api_key:string, payload:array<string, int>|null}> */
        private array $captured_calls;

        /**
         * @param array<int, array{url:string, api_key:string, payload:array<string, int>|null}> $captured_calls
         */
        public function __construct(SafePDO $conn, array &$captured_calls) {
            parent::__construct($conn);
            $this->captured_calls = &$captured_calls;
        }

        /**
         * @param array<string, int>|null $payload
         * @return array<int|string, mixed>
         */
        protected function requestJson(string $url, string $api_key, ?array $payload = null): array {
            $this->captured_calls[] = [
                'url' => $url,
                'api_key' => $api_key,
                'payload' => $payload,
            ];

            if (count($this->captured_calls) === 1) {
                return [
                    'data' => [
                        ['id' => 'invoice-offset-1', 'clientId' => 'client-offset-1'],
                        ['id' => 'invoice-offset-2', 'clientId' => 'client-offset-2'],
                    ],
                ];
            }

            return [
                'data' => [
                    ['id' => 'invoice-offset-3', 'clientId' => 'client-offset-3'],
                ],
            ];
        }
    };

    $fetched_offset_invoices = $invoice_fetch_sync_without_next->fetchInvoices($normalized_base_url, 'test-api-key', 2);
    if (count($fetched_offset_invoices) !== 3) {
        throw new RuntimeException('Expected fetchInvoices() to continue paging by start/count when Moxie omits a next URL.');
    }

    if ($invoice_request_calls_without_next !== [
        [
            'url' => 'https://pod00.withmoxie.dev/api/public/action/payableInvoices/search?start=0&count=2',
            'api_key' => 'test-api-key',
            'payload' => null,
        ],
        [
            'url' => 'https://pod00.withmoxie.dev/api/public/action/payableInvoices/search?start=2&count=2',
            'api_key' => 'test-api-key',
            'payload' => null,
        ],
    ]) {
        throw new RuntimeException('Expected fetchInvoices() to continue offset pagination when Moxie omits a next URL: ' . json_encode($invoice_request_calls_without_next));
    }

    echo "✓ Moxie import client creation works\n";
    echo "✓ Archived and missing-email clients are skipped\n";
    echo "✓ Existing clients update by Moxie client ID\n";
    echo "✓ Moxie invoice import creates invoices only for imported or existing BDTA clients\n";
    echo "✓ Invoice import can attach archived Moxie clients to an existing BDTA client record\n";
    echo "✓ Invoice-level paid dates keep imported Moxie invoices in paid status\n";
    echo "✓ Repeated invoice syncs are idempotent and update imported invoices in place\n";
    echo "✓ Moxie base URL validation restricts allowed origins\n";
    echo "✓ fetchClients validates required base URL and page size inputs\n";
    echo "✓ Absolute Moxie pagination URLs must stay on the configured HTTPS origin\n";
    echo "✓ fetchClients GETs the primary client list endpoint and follows the returned pagination URL\n";
    echo "✓ fetchClients stops GET pagination when Moxie does not return a next URL\n";
    echo "✓ fetchClients retries the legacy list endpoint when the primary endpoint returns 404\n";
    echo "✓ fetchClients retries the next list endpoint when the primary endpoint returns 500 on page one\n";
    echo "✓ fetchClients retries the next list endpoint when the primary endpoint times out on page one\n";
    echo "✓ fetchClients retries a 429 response on the same paginated request before failing\n";
    echo "✓ fetchInvoices GETs the payable invoice search endpoint with explicit pagination and follows pagination URLs\n";
    echo "✓ fetchInvoices keeps paging by start/count when Moxie omits a next URL\n";
    echo "✓ Repeated syncs are idempotent for unchanged clients\n\n";
    echo "=== All Moxie Import Tests Passed! ===\n";
} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    if (isset($conn) && $conn instanceof PDO) {
        $cleanup_invoice_ids = [
            $primary_invoice_id ?? '',
            $existing_archived_invoice_id ?? '',
            $missing_client_invoice_id ?? '',
        ];
        if (!in_array('', $cleanup_invoice_ids, true)) {
            $cleanup_invoices = $conn->prepare("DELETE FROM invoices WHERE moxie_invoice_id IN (?, ?, ?)");
            $cleanup_invoices->execute($cleanup_invoice_ids);
        }
        $cleanup_ids = [
            $primary_client_id ?? '',
            $archived_client_id ?? '',
            $missing_email_client_id ?? '',
            $existing_archived_moxie_client_id ?? '',
        ];
        if (!in_array('', $cleanup_ids, true)) {
            $cleanup = $conn->prepare("DELETE FROM clients WHERE moxie_client_id IN (?, ?, ?, ?)");
            $cleanup->execute($cleanup_ids);
        }
        if (isset($existing_archived_client_email)) {
            $cleanup_existing = $conn->prepare("DELETE FROM clients WHERE email = ?");
            $cleanup_existing->execute([$existing_archived_client_email]);
        }
    }
}
