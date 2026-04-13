<?php
/**
 * Brook's Dog Training Academy - Database Configuration
 * MySQL-only database bootstrap and schema management.
 */

require_once __DIR__ . '/social_links.php';

// Load environment variables
require_once __DIR__ . '/env_loader.php';
EnvLoader::load();

class SafePDO extends PDO {
    /**
     * @param array<mixed> $options
     */
    public function prepare(string $query, array $options = []): SafePDOStatement {
        $statement = parent::prepare($query, $options);
        if (!$statement instanceof SafePDOStatement) {
            throw new RuntimeException('Failed to prepare SQL statement.');
        }

        return $statement;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): SafePDOStatement {
        if ($fetchMode === null) {
            $statement = parent::query($query);
        } else {
            $statement = parent::query($query, $fetchMode, ...$fetchModeArgs);
        }

        if (!$statement instanceof SafePDOStatement) {
            throw new RuntimeException('Failed to execute SQL query.');
        }

        return $statement;
    }
}

class SafePDOStatement extends PDOStatement {
    protected function __construct() {
    }

    /**
     * @template TMode of int
     * @param TMode $mode
     * @return (TMode is PDO::FETCH_COLUMN ? string|false : array<string, string>|false)
     */
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        $result = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        if ($result === false || is_array($result) || is_int($result) || is_float($result) || is_string($result) || $result === null) {
            /** @var array<string, string>|string|false $result */
            return $result;
        }

        return false;
    }

    /**
     * @template TMode of int
     * @param TMode $mode
     * @return (TMode is PDO::FETCH_COLUMN ? list<string> : list<array<string, string>>)
     */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        $result = parent::fetchAll($mode, ...array_map('scalar_string', $args));
        /** @var list<array<string, string>|string> $result */
        return $result;
    }

    /**
     * Returns null for aggregate queries over empty sets, scalar values for typical fetched
     * columns, stream resources for LOB columns, and false when no row is available or when
     * fetchColumn() fails under non-exception error modes.
     *
     * @return mixed
     */
    public function fetchColumn(int $column = 0): mixed {
        $result = parent::fetchColumn($column);
        return $result;
    }
}

function scalar_string(mixed $value): string {
    if (is_string($value)) {
        return $value;
    }

    if (is_int($value) || is_float($value) || is_bool($value)) {
        return (string) $value;
    }

    if (is_array($value)) {
        $firstKey = array_key_first($value);
        if ($firstKey !== null) {
            $first = $value[$firstKey];
            if (is_string($first) || is_int($first) || is_float($first) || is_bool($first)) {
                return (string) $first;
            }
        }
    }

    return '';
}

function safe_timestamp(int|false|null $timestamp): int {
    return is_int($timestamp) ? $timestamp : 0;
}

function safe_int(mixed $value): int {
    return is_numeric($value) ? (int) $value : 0;
}

function safe_float(mixed $value): float {
    return is_numeric($value) ? (float) $value : 0.0;
}

class Database {
    private const MYSQL_CLIENT_NAME_PHONE_INDEX_SQL = 'CREATE INDEX idx_clients_name_phone ON clients(name(128), phone(32))';
    private const MYSQL_CLIENT_EMAILS_MESSAGE_ID_INDEX_SQL = 'CREATE INDEX idx_client_emails_message_id ON client_emails(direction(8), message_id(160))';
    private const MYSQL_CLIENT_EMAILS_UTF8MB4_SQL = 'ALTER TABLE client_emails CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    private const MYSQL_UNMATCHED_EMAILS_MESSAGE_ID_INDEX_SQL = 'CREATE INDEX idx_unmatched_emails_message_id ON unmatched_emails(message_id(191))';

    private static ?SafePDO $sharedConnection = null;
    private SafePDO $conn;
    private string $db_type = 'mysql';
    private string $db_host;
    private string $db_port;
    private string $db_name;
    private string $db_user;
    private string $db_password;

    private function failConfiguration(string $message): never {
        die('Database configuration failed: ' . $message);
    }
    
    public function __construct() {
        if (self::$sharedConnection !== null) {
            $this->conn = self::$sharedConnection;
            $this->db_type = 'mysql';
            return;
        }
        // Load database configuration from environment
        $this->loadConfig();
        $this->connect();
        $this->initTables();
        self::$sharedConnection = $this->conn;
    }
    
    private function loadConfig(): void {
        $configured_db_type = strtolower(trim(EnvLoader::get('DB_TYPE', 'mysql')));
        if ($configured_db_type !== '' && $configured_db_type !== 'mysql') {
            $this->failConfiguration('SQLite support has been removed. Configure DB_TYPE=mysql or omit DB_TYPE.');
        }

        $this->db_host = EnvLoader::get('DB_HOST', 'localhost');
        $this->db_port = EnvLoader::get('DB_PORT', '3306');
        $this->db_name = EnvLoader::get('DB_NAME', 'bdta');
        $this->db_user = EnvLoader::get('DB_USER', '');
        $this->db_password = EnvLoader::get('DB_PASSWORD', '');

        if (trim($this->db_host) === '' || trim($this->db_name) === '' || trim($this->db_user) === '') {
            $this->failConfiguration('Create a `.env` file from `.env.example` and set DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASSWORD for your MySQL database.');
        }
    }
    
    private function connect(): void {
        try {
            $dsn = "mysql:host={$this->db_host};port={$this->db_port};dbname={$this->db_name};charset=utf8mb4";
            $this->conn = new SafePDO($dsn, $this->db_user, $this->db_password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);
            $this->conn->exec("SET NAMES utf8mb4");
            $this->conn->exec("SET time_zone = '+00:00'");
            $this->conn->exec("SET sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            $this->failConfiguration('Unable to connect with the configured MySQL credentials. Check DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASSWORD in `.env`.');
        }
    }
    
    public function getConnection(): SafePDO {
        return $this->conn;
    }
    
    public function getDatabaseType(): string {
        return $this->db_type;
    }
    
    /**
     * Build a MySQL LIMIT/OFFSET clause with integer literals.
     *
     * @param int $limit The number of rows to return
     * @param int $offset The number of rows to skip
     * @return string The LIMIT clause to append to SQL
     */
    public function buildLimitClause(int $limit, int $offset = 0): string {
        $limit = (int)$limit;  // Ensure integer
        $offset = (int)$offset; // Ensure integer
        
        if ($offset > 0) {
            return " LIMIT $limit OFFSET $offset";
        } else {
            return " LIMIT $limit";
        }
    }

    public function archiveClient(int|string $client_id): bool {
        $client_id = (int)$client_id;
        if ($client_id <= 0) {
            return false;
        }

        $started_transaction = false;

        try {
            if (!$this->conn->inTransaction()) {
                $this->conn->beginTransaction();
                $started_transaction = true;
            }

            $stmt = $this->conn->prepare("
                UPDATE clients
                SET is_archived = 1,
                    archived_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND COALESCE(is_archived, 0) = 0
            ");
            $stmt->execute([$client_id]);

            if ($stmt->rowCount() === 0) {
                if ($started_transaction) {
                    $this->conn->rollBack();
                }

                return false;
            }

            $this->conn->prepare("
                UPDATE quotes
                SET status = 'declined',
                    declined_at = COALESCE(declined_at, CURRENT_TIMESTAMP),
                    updated_at = CURRENT_TIMESTAMP
                WHERE client_id = ? AND status IN ('draft', 'sent', 'viewed')
            ")->execute([$client_id]);

            $this->conn->prepare("
                UPDATE contracts
                SET status = 'expired',
                    updated_at = CURRENT_TIMESTAMP
                WHERE client_id = ? AND status IN ('draft', 'sent')
            ")->execute([$client_id]);

            $this->conn->prepare("
                UPDATE invoices
                SET status = 'cancelled',
                    updated_at = CURRENT_TIMESTAMP
                WHERE client_id = ? AND status NOT IN ('paid', 'refunded', 'cancelled', 'void')
            ")->execute([$client_id]);

            $this->conn->prepare("
                UPDATE invoice_installments
                SET status = 'cancelled',
                    updated_at = CURRENT_TIMESTAMP
                WHERE invoice_id IN (SELECT id FROM invoices WHERE client_id = ?)
                  AND status = 'unpaid'
            ")->execute([$client_id]);

            $this->conn->prepare("
                UPDATE form_submissions
                SET status = 'cancelled'
                WHERE client_id = ? AND status = 'pending'
            ")->execute([$client_id]);

            $this->conn->prepare("
                UPDATE workflow_enrollments
                SET status = 'cancelled',
                    cancelled_at = COALESCE(cancelled_at, CURRENT_TIMESTAMP)
                WHERE client_id = ? AND status NOT IN ('completed', 'cancelled')
            ")->execute([$client_id]);

            $this->conn->prepare("
                UPDATE workflow_step_executions
                SET status = 'failed'
                WHERE enrollment_id IN (
                    SELECT id FROM workflow_enrollments WHERE client_id = ?
                ) AND status = 'pending'
            ")->execute([$client_id]);

            $this->conn->prepare("
                UPDATE bookings
                SET status = 'cancelled',
                    updated_at = CURRENT_TIMESTAMP
                WHERE client_id = ? AND status NOT IN ('completed', 'cancelled')
            ")->execute([$client_id]);

            if ($started_transaction) {
                $this->conn->commit();
            }

            return true;
        } catch (Throwable $e) {
            if ($started_transaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            throw $e;
        }
    }

    public function unarchiveClient(int|string $client_id): bool {
        $client_id = (int)$client_id;
        if ($client_id <= 0) {
            return false;
        }

        $stmt = $this->conn->prepare("
            UPDATE clients
            SET is_archived = 0,
                archived_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND COALESCE(is_archived, 0) = 1
        ");
        $stmt->execute([$client_id]);

        return $stmt->rowCount() > 0;
    }
     
    /**
     * Normalize legacy schema DDL fragments into MySQL syntax.
     */
    private function convertSQL(string $sql): string {
        $mysql_sql = $sql;
        
        // Convert INTEGER PRIMARY KEY AUTOINCREMENT to INT AUTO_INCREMENT PRIMARY KEY
        $mysql_sql = preg_replace(
            '/INTEGER PRIMARY KEY AUTOINCREMENT/i',
            'INT AUTO_INCREMENT PRIMARY KEY',
            $mysql_sql
        ) ?? $mysql_sql;
        
        // Convert TEXT to VARCHAR only when required by MySQL:
        // - UNIQUE: MySQL cannot create an index on an unbounded TEXT column.
        // - DEFAULT 'value': MySQL 5.7 and older do not support non-NULL defaults
        //   on TEXT columns, so we use VARCHAR(255) for those short-value fields.
        // TEXT NOT NULL is intentionally left as TEXT so that long-content columns
        // (e.g. template_text, contract_text) are not capped at 255 characters.
        $mysql_sql = preg_replace(
            '/(\w+)\s+TEXT\s+(UNIQUE|DEFAULT)/i',
            '$1 VARCHAR(255) $2',
            $mysql_sql
        ) ?? $mysql_sql;
        
        // Handle standalone TEXT columns (no constraints after)
        $mysql_sql = preg_replace(
            '/(\w+)\s+TEXT\s*,/i',
            '$1 TEXT,',
            $mysql_sql
        ) ?? $mysql_sql;
        
        // Convert INTEGER to INT
        $mysql_sql = preg_replace(
            '/(\w+)\s+INTEGER\s+/i',
            '$1 INT ',
            $mysql_sql
        ) ?? $mysql_sql;
        
        // Convert INTEGER, at end of line
        $mysql_sql = preg_replace(
            '/(\w+)\s+INTEGER\s*,/i',
            '$1 INT,',
            $mysql_sql
        ) ?? $mysql_sql;
        
        // Handle TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        // MySQL uses CURRENT_TIMESTAMP, which is compatible
        
        return $mysql_sql;
    }
    
    /**
     * Execute SQL after MySQL normalization.
     */
    private function execSQL(string $sql): int|false {
        $converted_sql = $this->convertSQL($sql);
        return $this->conn->exec($converted_sql);
    }

    private function seedManualSiteBuilderPages(): void {
        require_once __DIR__ . '/sitebuilder_manual_pages.php';

        try {
            $siteRootPath = dirname(__DIR__, 2);
            if (
                !SiteBuilderManualPageSeeder::needsSeeding($this->conn)
                && !SiteBuilderManualPageSeeder::needsAssetExtraction($siteRootPath)
            ) {
                return;
            }

            SiteBuilderManualPageSeeder::seed(
                $this->conn,
                $siteRootPath,
                $siteRootPath . '/brooksdogtrainingacademy.com_project.sitebuilder'
            );
        } catch (Throwable $e) {
            error_log('Manual SiteBuilder page seed failed: ' . $e->getMessage());
        }
    }
    
    /**
     * @return list<string>
     */
    private function getTableColumns(string $tableName): array {
        // Validate table name to prevent SQL injection
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new InvalidArgumentException("Invalid table name: $tableName");
        }
        
        $stmt = $this->conn->prepare("
            SELECT COLUMN_NAME
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
        ");
        $stmt->execute([$tableName]);
        return array_map('scalar_string', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function indexExists(string $tableName, string $indexName): bool {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new InvalidArgumentException("Invalid table name: $tableName");
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $indexName)) {
            throw new InvalidArgumentException("Invalid index name: $indexName");
        }

        $stmt = $this->conn->prepare("
            SELECT INDEX_NAME
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND INDEX_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$tableName, $indexName]);
        return $stmt->fetchColumn() !== false;
    }

    private function tableCollation(string $tableName): ?string {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new InvalidArgumentException("Invalid table name: $tableName");
        }

        $stmt = $this->conn->prepare("
            SELECT TABLE_COLLATION
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            LIMIT 1
        ");
        $stmt->execute([$tableName]);
        $collation = $stmt->fetchColumn();
        if (!is_string($collation) || $collation === '') {
            return null;
        }

        return strtolower($collation);
    }
    
    /**
     * Check if a table exists
     */
    private function tableExists(string $tableName): bool {
        // Validate table name to prevent SQL injection
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new InvalidArgumentException("Invalid table name: $tableName");
        }
        
        try {
            $stmt = $this->conn->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$tableName]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    private function initTables(): void {
        try {
            // Admin users table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS admin_users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT UNIQUE NOT NULL,
                    password_hash TEXT NOT NULL,
                    email TEXT NOT NULL,
                    account_type TEXT NOT NULL DEFAULT 'standard',
                    can_manage_admin_users INTEGER NOT NULL DEFAULT 0,
                    can_manage_api_keys INTEGER NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            try {
                $this->execSQL("ALTER TABLE admin_users ADD COLUMN account_type TEXT NOT NULL DEFAULT 'standard'");
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'Duplicate column name') === false) {
                    throw $e;
                }
            }

            try {
                $this->execSQL("ALTER TABLE admin_users ADD COLUMN can_manage_admin_users INTEGER NOT NULL DEFAULT 0");
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'Duplicate column name') === false) {
                    throw $e;
                }
            }

            try {
                $this->execSQL("ALTER TABLE admin_users ADD COLUMN can_manage_api_keys INTEGER NOT NULL DEFAULT 0");
            } catch (Throwable $e) {
                if (stripos($e->getMessage(), 'Duplicate column name') === false) {
                    throw $e;
                }
            }
            
            // Blog posts table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS blog_posts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    title TEXT NOT NULL,
                    slug TEXT UNIQUE NOT NULL,
                    content MEDIUMTEXT NOT NULL,
                    excerpt TEXT,
                    cover_photo TEXT,
                    author TEXT NOT NULL,
                    published INTEGER DEFAULT 0,
                    publish_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Bookings table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS bookings (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_name TEXT NOT NULL,
                    client_email TEXT NOT NULL,
                    client_phone TEXT,
                    admin_user_id INTEGER,
                    service_type TEXT NOT NULL,
                    appointment_date DATE NOT NULL,
                    appointment_time TIME NOT NULL,
                    duration_minutes INTEGER DEFAULT 60,
                    status TEXT DEFAULT 'pending',
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Clients table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS clients (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    email TEXT NOT NULL,
                    phone TEXT,
                    address TEXT,
                    dog_name TEXT,
                    dog_breed TEXT,
                    notes TEXT,
                    is_archived INTEGER NOT NULL DEFAULT 0,
                    archived_at TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Client contacts table - support multiple contacts per client
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS client_contacts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_id INTEGER NOT NULL,
                    name TEXT NOT NULL,
                    email TEXT NOT NULL,
                    phone TEXT NOT NULL,
                    is_primary INTEGER DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
                )
            ");
            
            // Pets table (enhanced for multi-pet support)
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS pets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_id INTEGER NOT NULL,
                    name TEXT NOT NULL,
                    species TEXT DEFAULT 'Dog',
                    breed TEXT,
                    date_of_birth DATE,
                    age_years INTEGER,
                    age_months INTEGER,
                    source TEXT,
                    ownership_length_years INTEGER,
                    ownership_length_months INTEGER,
                    spayed_neutered INTEGER DEFAULT 0,
                    vaccines_current INTEGER DEFAULT 1,
                    vaccine_notes TEXT,
                    behavior_notes TEXT,
                    medical_notes TEXT,
                    training_notes TEXT,
                    is_active INTEGER DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
                )
            ");
            
            // Pet files table - for storing uploaded documents and photos
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS pet_files (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    pet_id INTEGER NOT NULL,
                    file_type TEXT NOT NULL,
                    file_name TEXT NOT NULL,
                    original_name TEXT NOT NULL,
                    file_size INTEGER NOT NULL,
                    mime_type TEXT NOT NULL,
                    description TEXT,
                    uploaded_by INTEGER,
                    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (pet_id) REFERENCES pets(id) ON DELETE CASCADE,
                    FOREIGN KEY (uploaded_by) REFERENCES admin_users(id) ON DELETE SET NULL
                )
            ");
            
            // Appointment pets junction table (for multi-pet appointments)
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS appointment_pets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    booking_id INTEGER NOT NULL,
                    pet_id INTEGER NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
                    FOREIGN KEY (pet_id) REFERENCES pets(id) ON DELETE CASCADE
                )
            ");
            
            // Appointment types table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS appointment_types (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    description TEXT,
                    admin_user_id INTEGER,
                    duration_minutes INTEGER DEFAULT 60,
                    buffer_before_minutes INTEGER DEFAULT 0,
                    buffer_after_minutes INTEGER DEFAULT 0,
                    use_travel_time_buffer INTEGER DEFAULT 0,
                    travel_time_minutes INTEGER DEFAULT 0,
                    advance_booking_min_days INTEGER DEFAULT 1,
                    advance_booking_max_days INTEGER DEFAULT 90,
                    requires_forms INTEGER DEFAULT 0,
                    requires_contract INTEGER DEFAULT 0,
                    auto_invoice INTEGER DEFAULT 0,
                    invoice_due_days INTEGER DEFAULT 7,
                    consumes_credits INTEGER DEFAULT 0,
                    credit_count INTEGER DEFAULT 1,
                    is_group_class INTEGER DEFAULT 0,
                    max_participants INTEGER DEFAULT 1,
                    uses_resource INTEGER DEFAULT 0,
                    resource_name TEXT,
                    resource_capacity INTEGER DEFAULT 1,
                    resource_allocation TEXT DEFAULT 'per_appointment',
                    is_active INTEGER DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Client credits table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS client_credits (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_id INTEGER NOT NULL UNIQUE,
                    credit_balance INTEGER DEFAULT 0,
                    total_purchased INTEGER DEFAULT 0,
                    total_consumed INTEGER DEFAULT 0,
                    total_adjusted INTEGER DEFAULT 0,
                    credits_expire INTEGER DEFAULT 0,
                    expiration_days INTEGER,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
                )
            ");
            
            // Credit transactions table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS credit_transactions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_id INTEGER NOT NULL,
                    transaction_type TEXT NOT NULL,
                    amount INTEGER NOT NULL,
                    balance_before INTEGER NOT NULL,
                    balance_after INTEGER NOT NULL,
                    booking_id INTEGER,
                    notes TEXT,
                    created_by INTEGER,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
                    FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL
                )
            ");
            
            // Time entries table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS time_entries (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_id INTEGER NOT NULL,
                    booking_id INTEGER,
                    service_type TEXT NOT NULL,
                    description TEXT,
                    date DATE NOT NULL,
                    start_time TIME NOT NULL,
                    end_time TIME NOT NULL,
                    duration_minutes INTEGER NOT NULL,
                    hourly_rate REAL DEFAULT 0,
                    total_amount REAL DEFAULT 0,
                    billable INTEGER DEFAULT 1,
                    invoiced INTEGER DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES clients(id),
                    FOREIGN KEY (booking_id) REFERENCES bookings(id)
                )
            ");
            
            // Expenses table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS expenses (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_id INTEGER,
                    category TEXT NOT NULL,
                    description TEXT NOT NULL,
                    amount REAL NOT NULL,
                    expense_date DATE NOT NULL,
                    receipt_file TEXT,
                    billable INTEGER DEFAULT 0,
                    invoiced INTEGER DEFAULT 0,
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES clients(id)
                )
            ");
            
            // Invoices table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS invoices (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    invoice_number TEXT UNIQUE NOT NULL,
                    client_id INTEGER NOT NULL,
                    issue_date DATE NOT NULL,
                    due_date DATE NOT NULL,
                    subtotal REAL NOT NULL,
                    tax_rate REAL DEFAULT 0,
                    tax_amount REAL DEFAULT 0,
                    total_amount REAL NOT NULL,
                    status TEXT DEFAULT 'draft',
                    payment_method TEXT,
                    payment_date DATE,
                    stripe_payment_intent_id TEXT,
                    void_reason TEXT,
                    voided_at TIMESTAMP,
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES clients(id)
                )
            ");
            
            // Invoice items table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS invoice_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    invoice_id INTEGER NOT NULL,
                    item_type TEXT NOT NULL,
                    reference_id INTEGER,
                    description TEXT NOT NULL,
                    quantity REAL NOT NULL,
                    rate REAL NOT NULL,
                    amount REAL NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (invoice_id) REFERENCES invoices(id)
                )
            ");

            // Invoice installments table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS invoice_installments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    invoice_id INTEGER NOT NULL,
                    installment_number INTEGER NOT NULL,
                    amount REAL NOT NULL,
                    due_date DATE NOT NULL,
                    status TEXT DEFAULT 'unpaid',
                    payment_method TEXT,
                    payment_date DATE,
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
                )
            ");

            $this->execSQL("
                CREATE TABLE IF NOT EXISTS invoice_refunds (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    invoice_id INTEGER NOT NULL,
                    amount REAL NOT NULL,
                    refund_date DATE NOT NULL,
                    refund_method TEXT,
                    stripe_refund_id TEXT,
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
                )
            ");
            
            // Contracts table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS contracts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    contract_number TEXT UNIQUE NOT NULL,
                    client_id INTEGER NOT NULL,
                    title TEXT NOT NULL,
                    description TEXT,
                    contract_text TEXT NOT NULL,
                    status TEXT DEFAULT 'draft',
                    access_token VARCHAR(32),
                    created_date DATE NOT NULL,
                    effective_date DATE,
                    expiration_date DATE,
                    signed_date DATE,
                    signature_data TEXT,
                    ip_address TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES clients(id)
                )
            ");
            
            // Contract templates table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS contract_templates (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    description TEXT,
                    template_text TEXT NOT NULL,
                    service_type TEXT,
                    renewal_period_months INTEGER DEFAULT 12,
                    is_active INTEGER DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Settings table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS settings (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    setting_key TEXT UNIQUE NOT NULL,
                    setting_value TEXT,
                    setting_type TEXT DEFAULT 'text',
                    category TEXT NOT NULL,
                    label TEXT NOT NULL,
                    description TEXT,
                    is_secret INTEGER DEFAULT 0,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $this->execSQL("
                CREATE TABLE IF NOT EXISTS app_sessions (
                    session_id VARCHAR(128) PRIMARY KEY,
                    session_data BLOB NOT NULL,
                    expires_at TIMESTAMP NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ");
            if (!$this->indexExists('app_sessions', 'idx_app_sessions_expires_at')) {
                $this->execSQL("CREATE INDEX idx_app_sessions_expires_at ON app_sessions(expires_at)");
            }
            
            // Email signature templates table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS email_signature_templates (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    description TEXT,
                    html_content MEDIUMTEXT NOT NULL,
                    is_default INTEGER DEFAULT 0,
                    is_active INTEGER DEFAULT 1,
                    max_image_width INTEGER DEFAULT 600,
                    max_image_height INTEGER DEFAULT 200,
                    created_by INTEGER,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL
                )
            ");
            
            // Form templates table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS form_templates (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    description TEXT,
                    form_type TEXT NOT NULL DEFAULT 'client_form',
                    fields MEDIUMTEXT NOT NULL,
                    required_frequency TEXT,
                    appointment_type_id INTEGER,
                    is_internal INTEGER DEFAULT 0,
                    is_active INTEGER DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (appointment_type_id) REFERENCES appointment_types(id) ON DELETE SET NULL
                )
            ");
            
            // Form submissions table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS form_submissions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_id INTEGER NOT NULL,
                    template_id INTEGER NOT NULL,
                    booking_id INTEGER,
                    pet_id INTEGER,
                    responses MEDIUMTEXT NOT NULL,
                    status TEXT DEFAULT 'submitted',
                    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    submitted_by INTEGER,
                    reviewed_by INTEGER,
                    reviewed_at TIMESTAMP,
                    notes TEXT,
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                    FOREIGN KEY (template_id) REFERENCES form_templates(id) ON DELETE CASCADE,
                    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
                    FOREIGN KEY (pet_id) REFERENCES pets(id) ON DELETE SET NULL,
                    FOREIGN KEY (submitted_by) REFERENCES admin_users(id) ON DELETE SET NULL,
                    FOREIGN KEY (reviewed_by) REFERENCES admin_users(id) ON DELETE SET NULL
                )
            ");
            
            // Quotes table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS quotes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    quote_number TEXT UNIQUE NOT NULL,
                    client_id INTEGER NOT NULL,
                    title TEXT NOT NULL,
                    description TEXT,
                    amount DECIMAL(10,2) NOT NULL,
                    expiration_date DATE,
                    status TEXT DEFAULT 'sent',
                    accepted_at TIMESTAMP,
                    declined_at TIMESTAMP,
                    viewed_at TIMESTAMP,
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
                )
            ");
            
            // Quote items table  
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS quote_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    quote_id INTEGER NOT NULL,
                    description TEXT NOT NULL,
                    quantity INTEGER DEFAULT 1,
                    unit_price DECIMAL(10,2) NOT NULL,
                    amount DECIMAL(10,2) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
                )
            ");
            
            // Email templates table
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS email_templates (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    template_type TEXT NOT NULL,
                    subject TEXT NOT NULL,
                    body_html MEDIUMTEXT NOT NULL,
                    body_text MEDIUMTEXT,
                    variables TEXT,
                    is_active INTEGER DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");

            // Booking reminder rules table — configurable multi-rule reminders
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS booking_reminder_rules (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    appointment_type_id INTEGER DEFAULT NULL,
                    name TEXT NOT NULL,
                    hours_before INTEGER NOT NULL,
                    template_id INTEGER,
                    is_active INTEGER DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (appointment_type_id) REFERENCES appointment_types(id) ON DELETE CASCADE,
                    FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL
                )
            ");

            // Booking reminders sent — per-rule tracking (replaces single reminder_sent boolean)
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS booking_reminders_sent (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    booking_id INTEGER NOT NULL,
                    rule_id INTEGER NOT NULL,
                    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
                    FOREIGN KEY (rule_id) REFERENCES booking_reminder_rules(id) ON DELETE CASCADE
                )
            ");
            
            // Scheduled tasks table - for CRON job automation
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS scheduled_tasks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    task_name TEXT NOT NULL,
                    task_type TEXT NOT NULL,
                    schedule_type TEXT NOT NULL,
                    schedule_value TEXT,
                    is_active INTEGER DEFAULT 1,
                    last_run TIMESTAMP,
                    next_run TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Task execution log table - for tracking CRON job execution
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS task_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    task_id INTEGER,
                    task_name TEXT NOT NULL,
                    status TEXT NOT NULL,
                    message TEXT,
                    items_processed INTEGER DEFAULT 0,
                    execution_time REAL,
                    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (task_id) REFERENCES scheduled_tasks(id) ON DELETE CASCADE
                )
            ");
            
            // Workflows table - for custom automated email workflows
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS workflows (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    description TEXT,
                    is_active INTEGER DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Workflow steps table - individual emails in a workflow
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS workflow_steps (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    workflow_id INTEGER NOT NULL,
                    step_order INTEGER NOT NULL,
                    step_name TEXT NOT NULL,
                    email_subject TEXT NOT NULL,
                    email_body_html TEXT NOT NULL,
                    email_body_text TEXT,
                    delay_type TEXT NOT NULL,
                    delay_value TEXT,
                    scheduled_date DATE,
                    attach_contract_id INTEGER,
                    attach_form_id INTEGER,
                    attach_quote_id INTEGER,
                    attach_invoice_id INTEGER,
                    include_appointment_link INTEGER DEFAULT 0,
                    appointment_type_id INTEGER,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
                    FOREIGN KEY (attach_contract_id) REFERENCES contract_templates(id) ON DELETE SET NULL,
                    FOREIGN KEY (attach_form_id) REFERENCES form_templates(id) ON DELETE SET NULL,
                    FOREIGN KEY (appointment_type_id) REFERENCES appointment_types(id) ON DELETE SET NULL
                )
            ");
            
            // Workflow enrollments table - clients enrolled in workflows
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS workflow_enrollments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    workflow_id INTEGER NOT NULL,
                    client_id INTEGER NOT NULL,
                    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    enrolled_by INTEGER,
                    status TEXT DEFAULT 'active',
                    completed_at TIMESTAMP,
                    cancelled_at TIMESTAMP,
                    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                    FOREIGN KEY (enrolled_by) REFERENCES admin_users(id) ON DELETE SET NULL
                )
            ");
            
            // Workflow step executions table - track sent emails
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS workflow_step_executions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    enrollment_id INTEGER NOT NULL,
                    step_id INTEGER NOT NULL,
                    scheduled_for TIMESTAMP NOT NULL,
                    executed_at TIMESTAMP,
                    status TEXT DEFAULT 'pending',
                    error_message TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (enrollment_id) REFERENCES workflow_enrollments(id) ON DELETE CASCADE,
                    FOREIGN KEY (step_id) REFERENCES workflow_steps(id) ON DELETE CASCADE
                )
            ");
            
            // Workflow triggers table - auto-enrollment based on events
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS workflow_triggers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    workflow_id INTEGER NOT NULL,
                    trigger_type TEXT NOT NULL,
                    appointment_type_id INTEGER,
                    form_template_id INTEGER,
                    is_active INTEGER DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
                    FOREIGN KEY (appointment_type_id) REFERENCES appointment_types(id) ON DELETE CASCADE,
                    FOREIGN KEY (form_template_id) REFERENCES form_templates(id) ON DELETE CASCADE
                )
            ");
            
            // Client emails table - for per-client email correspondence
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS client_emails (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_id INTEGER NOT NULL,
                    direction TEXT NOT NULL,
                    status TEXT NOT NULL,
                    message_id TEXT,
                    from_email TEXT NOT NULL,
                    to_email TEXT NOT NULL,
                    subject TEXT NOT NULL,
                    body_html TEXT,
                    body_text TEXT,
                    template_id INTEGER,
                    mail_type TEXT,
                    scheduled_at TIMESTAMP,
                    sent_at TIMESTAMP,
                    delivered_at TIMESTAMP,
                    failed_at TIMESTAMP,
                    error_message TEXT,
                    created_by INTEGER,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                    FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL,
                    FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL
                )
            ");
            
            // Unmatched emails table - for emails from unknown senders
            $this->execSQL("
                CREATE TABLE IF NOT EXISTS unmatched_emails (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    message_id TEXT,
                    from_email TEXT NOT NULL,
                    from_name TEXT,
                    to_email TEXT NOT NULL,
                    subject TEXT NOT NULL,
                    body_html TEXT,
                    body_text TEXT,
                    received_at TIMESTAMP,
                    is_assigned INTEGER DEFAULT 0,
                    assigned_to_client_id INTEGER,
                    assigned_at TIMESTAMP,
                    assigned_by INTEGER,
                    is_archived INTEGER DEFAULT 0,
                    archived_at TIMESTAMP,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (assigned_to_client_id) REFERENCES clients(id) ON DELETE SET NULL,
                    FOREIGN KEY (assigned_by) REFERENCES admin_users(id) ON DELETE SET NULL
                )
            ");
            
            // Create default admin if not exists
            $stmt = $this->conn->prepare("SELECT id FROM admin_users WHERE username = ?");
            $stmt->execute(['admin']);
            
            if (!$stmt->fetch()) {
                $password_hash = password_hash('admin123', PASSWORD_DEFAULT);
                $stmt = $this->conn->prepare("
                    INSERT INTO admin_users (username, password_hash, email, account_type, can_manage_admin_users, can_manage_api_keys) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute(['admin', $password_hash, 'admin@brooksdogtrainingacademy.com', 'main', 1, 1]);
            }

            $this->conn->prepare("
                UPDATE admin_users
                SET account_type = 'main',
                    can_manage_admin_users = 1,
                    can_manage_api_keys = 1
                WHERE username = 'admin'
            ")->execute();

            $this->conn->prepare("
                UPDATE admin_users
                SET account_type = 'standard'
                WHERE username <> 'admin' AND COALESCE(account_type, '') = ''
            ")->execute();
            
            // Initialize default settings if table is empty
            $stmt = $this->conn->query("SELECT COUNT(*) FROM settings");
            if ($stmt->fetchColumn() == 0) {
                $this->initDefaultSettings();
            }
            
            // Initialize sample appointment types if table is empty
            $stmt = $this->conn->query("SELECT COUNT(*) FROM appointment_types");
            if ($stmt->fetchColumn() == 0) {
                $this->initSampleAppointmentTypes();
            }
            
            // Run database migrations to add new columns
            // These migrations should always run, not just when initializing sample data
            $this->runMigrations();
            
        } catch(PDOException $e) {
            die("Table creation failed: " . $e->getMessage());
        }
    }
    
    private function initDefaultSettings(): void {
        $default_settings = [
            // General Settings
            ['site_name', "Brook's Dog Training Academy", 'text', 'general', 'Site Name', 'The name of your business', 0],
            ['site_tagline', 'Teaching Humans to Speak Dog', 'text', 'general', 'Site Tagline', 'Your business tagline or slogan', 0],
            ['business_email', 'bookings@brooksdogtrainingacademy.com', 'email', 'general', 'Business Email', 'Primary contact email', 0],
            ['business_phone', '(555) 123-4567', 'text', 'general', 'Business Phone', 'Primary contact phone number', 0],
            ['business_address', 'Sebring, Florida', 'textarea', 'general', 'Business Address', 'Your business address', 0],
            ['founded_year', '2018', 'number', 'general', 'Founded Year', 'Year your business was founded', 0],
            
            // Email Settings
            ['email_from_address', 'bookings@brooksdogtrainingacademy.com', 'email', 'email', 'From Email Address', 'Email address for outgoing emails', 0],
            ['email_from_name', "Brook's Dog Training Academy", 'text', 'email', 'From Name', 'Name displayed in outgoing emails', 0],
            ['email_service', 'mail', 'select', 'email', 'Email Service', 'Email delivery service (mail, smtp, sendgrid, mailgun, ses)', 0],
            ['smtp_host', '', 'text', 'email', 'SMTP Host', 'SMTP server hostname (if using SMTP)', 0],
            ['smtp_port', '587', 'number', 'email', 'SMTP Port', 'SMTP server port (587 for TLS, 465 for SSL)', 0],
            ['smtp_encryption', 'tls', 'select', 'email', 'SMTP Encryption', 'Encryption method (tls, ssl, none)', 0],
            ['smtp_username', '', 'text', 'email', 'SMTP Username', 'SMTP authentication username (leave empty if not required)', 0],
            ['smtp_password', '', 'password', 'email', 'SMTP Password', 'SMTP authentication password (leave empty if not required)', 1],
            ['smtp_debug', '0', 'checkbox', 'email', 'SMTP Debug Mode', 'Enable detailed debug logging for troubleshooting', 0],
            ['sendgrid_api_key', '', 'password', 'email', 'SendGrid API Key', 'SendGrid API key (if using SendGrid)', 1],
            ['mailgun_api_key', '', 'password', 'email', 'Mailgun API Key', 'Mailgun API key (if using Mailgun)', 1],
            ['mailgun_domain', '', 'text', 'email', 'Mailgun Domain', 'Mailgun sending domain', 0],
            ['default_email_signature_id', '0', 'number', 'email', 'Default Email Signature', 'Default email signature template (0 = none)', 0],
            ['enable_email_signatures', '1', 'checkbox', 'email', 'Enable Email Signatures', 'Automatically include email signatures in outgoing emails', 0],
            
            // IMAP Settings for receiving emails
            ['imap_enabled', '0', 'checkbox', 'email', 'Enable IMAP Email Receiving', 'Fetch incoming emails automatically', 0],
            ['imap_host', '', 'text', 'email', 'IMAP Host', 'IMAP server hostname (e.g., imap.gmail.com)', 0],
            ['imap_port', '993', 'number', 'email', 'IMAP Port', 'IMAP server port (993 for SSL, 143 for TLS)', 0],
            ['imap_encryption', 'ssl', 'select', 'email', 'IMAP Encryption', 'Encryption method (ssl, tls, none)', 0],
            ['imap_username', '', 'text', 'email', 'IMAP Username', 'IMAP authentication username (usually email address)', 0],
            ['imap_password', '', 'password', 'email', 'IMAP Password', 'IMAP authentication password', 1],
            ['imap_folder', 'INBOX', 'text', 'email', 'IMAP Folder', 'Folder to fetch emails from (default: INBOX)', 0],
            ['imap_sync_days', '30', 'number', 'email', 'Sync Days', 'How many days of emails to sync (default: 30)', 0],
            
            // Stripe Payment Settings
            ['stripe_enabled', '0', 'checkbox', 'payment', 'Enable Stripe Payments', 'Enable online payment processing with Stripe', 0],
            ['stripe_mode', 'test', 'select', 'payment', 'Stripe Mode', 'Use test or live mode (test, live)', 0],
            ['stripe_test_publishable_key', 'pk_test_YOUR_KEY', 'password', 'payment', 'Test Publishable Key', 'Stripe test publishable key', 1],
            ['stripe_test_secret_key', 'sk_test_YOUR_KEY', 'password', 'payment', 'Test Secret Key', 'Stripe test secret key', 1],
            ['stripe_live_publishable_key', '', 'password', 'payment', 'Live Publishable Key', 'Stripe live publishable key', 1],
            ['stripe_live_secret_key', '', 'password', 'payment', 'Live Secret Key', 'Stripe live secret key', 1],
            ['stripe_currency', 'usd', 'text', 'payment', 'Currency', 'Currency code (usd, eur, gbp, etc.)', 0],
            
            // Booking Settings
            ['booking_start_time', '09:00', 'time', 'booking', 'Start Time', 'First available booking time', 0],
            ['booking_end_time', '17:00', 'time', 'booking', 'End Time', 'Last available booking time', 0],
            ['booking_slot_duration', '30', 'number', 'booking', 'Slot Duration', 'Duration of each time slot in minutes', 0],
            ['booking_buffer_time', '0', 'number', 'booking', 'Buffer Time', 'Buffer time between bookings in minutes', 0],
            ['booking_advance_days', '90', 'number', 'booking', 'Advance Booking Days', 'How many days in advance can clients book', 0],
            ['booking_confirmation_email', '1', 'checkbox', 'booking', 'Send Confirmation Emails', 'Automatically send booking confirmation emails', 0],
            
            // Calendar Integration
            ['google_calendar_enabled', '0', 'checkbox', 'calendar', 'Enable Google Calendar Sync', 'Sync bookings to Google Calendar', 0],
            ['google_calendar_id', 'primary', 'text', 'calendar', 'Google Calendar ID', 'Google Calendar ID to sync to (service account method)', 0],
            ['google_calendar_credentials_file', '', 'text', 'calendar', 'Credentials File Path', 'Path to Google Calendar credentials JSON file (service account method)', 0],
            ['google_oauth_client_id', '', 'text', 'calendar', 'OAuth Client ID', 'Google OAuth 2.0 Client ID (from Google Cloud Console)', 0],
            ['google_oauth_client_secret', '', 'password', 'calendar', 'OAuth Client Secret', 'Google OAuth 2.0 Client Secret (from Google Cloud Console)', 1],
            ['google_oauth_redirect_uri', '', 'text', 'calendar', 'OAuth Redirect URI', 'OAuth callback URL (e.g. https://yourdomain.com/backend/public/google_oauth_callback.php)', 0],
            
            // Invoice Settings
            ['invoice_prefix', 'INV-', 'text', 'invoice', 'Invoice Number Prefix', 'Prefix for invoice numbers', 0],
            ['invoice_next_number', '1001', 'number', 'invoice', 'Next Invoice Number', 'Next invoice number to use', 0],
            ['invoice_tax_rate', '0', 'number', 'invoice', 'Default Tax Rate', 'Default tax rate percentage (e.g., 7 for 7%)', 0],
            ['invoice_payment_terms', '30', 'number', 'invoice', 'Payment Terms', 'Default payment terms in days', 0],
            ['invoice_notes', 'Thank you for your business!', 'textarea', 'invoice', 'Default Invoice Notes', 'Default notes to include on invoices', 0],
            
            // Time Tracking Settings
            ['default_hourly_rate', '75', 'number', 'time_tracking', 'Default Hourly Rate', 'Default hourly rate for time tracking', 0],
            ['time_rounding', '15', 'select', 'time_tracking', 'Time Rounding', 'Round time entries to nearest X minutes (0, 5, 10, 15, 30)', 0],
            
            // Social Media
            ...bdta_get_social_settings_seed_rows(),
            
            // Theme Settings
            ['theme_primary_color', '#9a0073', 'color', 'theme', 'Primary Color', 'Main branding color used for buttons, links, and highlights', 0],
            ['theme_primary_dark_color', '#7a005a', 'color', 'theme', 'Primary Dark Color', 'Darker shade of primary color used for hover states', 0],
            ['theme_secondary_color', '#0a9a9c', 'color', 'theme', 'Secondary Color', 'Secondary accent color used for success states and accents', 0],
            ['theme_accent_color', '#a39f89', 'color', 'theme', 'Accent Color', 'Supporting accent color (tan/beige tones)', 0],
            ['theme_sidebar_bg_start', '#9a0073', 'color', 'theme', 'Sidebar Gradient Start', 'Starting color of the sidebar gradient', 0],
            ['theme_sidebar_bg_end', '#7a005a', 'color', 'theme', 'Sidebar Gradient End', 'Ending color of the sidebar gradient', 0],

            // Advanced
            ['base_url', 'http://localhost:8000', 'url', 'advanced', 'Base URL', 'Base URL of your website', 0],
            ['timezone', 'America/New_York', 'text', 'advanced', 'Timezone', 'Your local timezone', 0],
            ['date_format', 'Y-m-d', 'text', 'advanced', 'Date Format', 'PHP date format string', 0],
            ['time_format', 'H:i', 'text', 'advanced', 'Time Format', 'PHP time format string', 0],
            ['tawk_to_enabled', '0', 'checkbox', 'advanced', 'Enable Tawk.to Chat', 'Load the Tawk.to chat widget on public-facing pages and the client portal. Admin pages remain excluded.', 0],
            ['tawk_to_property_id', '', 'text', 'advanced', 'Tawk.to Property ID', 'Paste the property ID from your Tawk.to embed snippet.', 0],
            ['tawk_to_widget_id', 'default', 'text', 'advanced', 'Tawk.to Widget ID', 'Optional widget ID from the Tawk.to embed snippet. Leave as "default" unless Tawk.to specifies another value.', 0],
            
        ];
        
        $stmt = $this->conn->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_type, category, label, description, is_secret)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($default_settings as $setting) {
            $stmt->execute($setting);
        }
    }
    
    private function initSampleAppointmentTypes(): void {
        $sample_types = [
            [
                'Consultation',
                'Initial consultation to assess training needs and goals',
                60, // duration
                15, // buffer_before
                15, // buffer_after
                2,  // advance_booking_min_days
                30, // advance_booking_max_days
                1,  // requires_forms
                1,  // requires_contract
                1,  // auto_invoice
                7,  // invoice_due_days
                0,  // consumes_credits
                1,  // credit_count
                0,  // is_group_class
                1,  // max_participants
                1   // is_active
            ],
            [
                'Meet & Greet',
                'Free meet and greet session to get acquainted',
                30,
                10,
                10,
                1,
                14,
                0,
                0,
                0,
                0,
                0,
                1,
                0,
                1,
                1
            ],
            [
                'Coaching Session',
                'One-on-one training session',
                60,
                15,
                15,
                1,
                60,
                0,
                1,
                0,
                0,
                1,
                1,
                0,
                1,
                1
            ],
            [
                'Group Class',
                'Group training class for multiple dogs and handlers',
                90,
                15,
                30,
                3,
                30,
                0,
                1,
                1,
                7,
                0,
                1,
                1,
                6,
                1
            ],
        ];
        
        $stmt = $this->conn->prepare("
            INSERT INTO appointment_types (
                name, description, duration_minutes,
                buffer_before_minutes, buffer_after_minutes,
                advance_booking_min_days, advance_booking_max_days,
                requires_forms, requires_contract,
                auto_invoice, invoice_due_days,
                consumes_credits, credit_count,
                is_group_class, max_participants,
                is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($sample_types as $type) {
            $stmt->execute($type);
        }
    }
    
    private function runMigrations(): void {
        // Update bookings table to add new columns for enhanced booking
        // Check if columns exist before adding
        $column_names = $this->getTableColumns('bookings');
        
        if (!in_array('client_id', $column_names)) {
            $this->execSQL("ALTER TABLE bookings ADD COLUMN client_id INTEGER");
        }
        if (!in_array('appointment_type_id', $column_names)) {
            $this->execSQL("ALTER TABLE bookings ADD COLUMN appointment_type_id INTEGER");
        }
        if (!in_array('pets', $column_names)) {
            $this->execSQL("ALTER TABLE bookings ADD COLUMN pets TEXT");
        }
        if (!in_array('override_forms', $column_names)) {
            $this->execSQL("ALTER TABLE bookings ADD COLUMN override_forms INTEGER DEFAULT 0");
        }
        if (!in_array('override_contract', $column_names)) {
            $this->execSQL("ALTER TABLE bookings ADD COLUMN override_contract INTEGER DEFAULT 0");
        }
        if (!in_array('override_credits', $column_names)) {
            $this->execSQL("ALTER TABLE bookings ADD COLUMN override_credits INTEGER DEFAULT 0");
        }
        if (!in_array('reminder_sent', $column_names)) {
            $this->execSQL("ALTER TABLE bookings ADD COLUMN reminder_sent INTEGER DEFAULT 0");
        }

        // Add publish_date support to blog_posts for backdating and scheduling
        $blog_column_names = $this->getTableColumns('blog_posts');
        if (!in_array('publish_date', $blog_column_names)) {
            $this->execSQL("ALTER TABLE blog_posts ADD COLUMN publish_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
            try {
                // Column is newly added, so backfill every existing row to creation timestamp
                $this->execSQL("UPDATE blog_posts SET publish_date = created_at");
            } catch (PDOException $e) {
                error_log("Migration: could not backfill blog_posts.publish_date - " . $e->getMessage());
            }
        }
        if (!in_array('cover_photo', $blog_column_names)) {
            $this->execSQL("ALTER TABLE blog_posts ADD COLUMN cover_photo TEXT");
        }
        
        // Update contracts table to add reminder tracking
        $contract_column_names = $this->getTableColumns('contracts');
        
        if (!in_array('sent_at', $contract_column_names)) {
            $this->execSQL("ALTER TABLE contracts ADD COLUMN sent_at TIMESTAMP");
        }
        
        if (!in_array('last_reminder_sent', $contract_column_names)) {
            $this->execSQL("ALTER TABLE contracts ADD COLUMN last_reminder_sent TIMESTAMP");
        }

        if (!in_array('access_token', $contract_column_names)) {
            $this->execSQL("ALTER TABLE contracts ADD COLUMN access_token VARCHAR(32)");
        }
        
        // Update form_submissions table to add reminder tracking
        $form_column_names = $this->getTableColumns('form_submissions');
        
        if (!in_array('sent_at', $form_column_names)) {
            $this->execSQL("ALTER TABLE form_submissions ADD COLUMN sent_at TIMESTAMP");
        }
        
        if (!in_array('last_reminder_sent', $form_column_names)) {
            $this->execSQL("ALTER TABLE form_submissions ADD COLUMN last_reminder_sent TIMESTAMP");
        }

        if (!in_array('pet_id', $form_column_names)) {
            $this->execSQL("ALTER TABLE form_submissions ADD COLUMN pet_id INTEGER");
        }
        
        // Update quotes table to add reminder tracking
        $quote_column_names = $this->getTableColumns('quotes');
        
        if (!in_array('last_reminder_sent', $quote_column_names)) {
            $this->execSQL("ALTER TABLE quotes ADD COLUMN last_reminder_sent TIMESTAMP");
        }
        
        // Update invoices table to add reminder tracking and receipt audit trail
        $invoice_column_names = $this->getTableColumns('invoices');
        
        if (!in_array('last_reminder_sent', $invoice_column_names)) {
            $this->execSQL("ALTER TABLE invoices ADD COLUMN last_reminder_sent TIMESTAMP");
        }

        if (!in_array('receipt_sent_at', $invoice_column_names)) {
            $this->execSQL("ALTER TABLE invoices ADD COLUMN receipt_sent_at TIMESTAMP");
        }

        if (!in_array('invoice_sent_at', $invoice_column_names)) {
            $this->execSQL("ALTER TABLE invoices ADD COLUMN invoice_sent_at TIMESTAMP");
        }

        if (!in_array('pay_token', $invoice_column_names)) {
            $this->execSQL("ALTER TABLE invoices ADD COLUMN pay_token TEXT");
            try {
                $this->execSQL("CREATE UNIQUE INDEX idx_invoices_pay_token ON invoices(pay_token)");
            } catch (PDOException $e) {
                // Index already exists, ignore
            }
        }

        if (!in_array('void_reason', $invoice_column_names)) {
            $this->execSQL("ALTER TABLE invoices ADD COLUMN void_reason TEXT");
        }

        if (!in_array('voided_at', $invoice_column_names)) {
            $this->execSQL("ALTER TABLE invoices ADD COLUMN voided_at TIMESTAMP");
        }

        // Update invoice_installments table to add receipt audit trail
        $installment_column_names = $this->getTableColumns('invoice_installments');

        if (!in_array('receipt_sent_at', $installment_column_names)) {
            $this->execSQL("ALTER TABLE invoice_installments ADD COLUMN receipt_sent_at TIMESTAMP");
        }

        $this->execSQL("
            CREATE TABLE IF NOT EXISTS invoice_refunds (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_id INTEGER NOT NULL,
                amount REAL NOT NULL,
                refund_date DATE NOT NULL,
                refund_method TEXT,
                stripe_refund_id TEXT,
                notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
            )
        ");

        try {
            $this->execSQL("CREATE INDEX idx_invoice_refunds_invoice_id ON invoice_refunds(invoice_id)");
        } catch (PDOException $e) {
            // Index already exists, ignore.
        }

        try {
            $this->execSQL("CREATE UNIQUE INDEX idx_invoice_refunds_stripe_refund_id ON invoice_refunds(stripe_refund_id)");
        } catch (PDOException $e) {
            // Index already exists, ignore.
        }
        
        // Update clients table to add password and admin fields for client login
        $client_column_names = $this->getTableColumns('clients');
        
        if (!in_array('password_hash', $client_column_names)) {
            $this->execSQL("ALTER TABLE clients ADD COLUMN password_hash TEXT");
        }
        if (!in_array('is_admin', $client_column_names)) {
            $this->execSQL("ALTER TABLE clients ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('last_login', $client_column_names)) {
            $this->execSQL("ALTER TABLE clients ADD COLUMN last_login TIMESTAMP");
        }
        if (!in_array('password_reset_token', $client_column_names)) {
            $this->execSQL("ALTER TABLE clients ADD COLUMN password_reset_token TEXT");
        }
        if (!in_array('password_reset_expires', $client_column_names)) {
            $this->execSQL("ALTER TABLE clients ADD COLUMN password_reset_expires TIMESTAMP");
        }
        if (!in_array('is_archived', $client_column_names)) {
            $this->execSQL("ALTER TABLE clients ADD COLUMN is_archived INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('archived_at', $client_column_names)) {
            $this->execSQL("ALTER TABLE clients ADD COLUMN archived_at TIMESTAMP");
        }
        $added_moxie_client_id = false;
        if (!in_array('moxie_client_id', $client_column_names)) {
            $this->execSQL("ALTER TABLE clients ADD COLUMN moxie_client_id VARCHAR(255) NULL");
            $added_moxie_client_id = true;
        }
        if (!$added_moxie_client_id) {
            try {
                $this->execSQL("ALTER TABLE clients MODIFY moxie_client_id VARCHAR(255) NULL");
            } catch (PDOException $e) {
                // Column might already be the desired type, ignore
            }
        }
        if (!$this->indexExists('clients', 'idx_clients_moxie_client_id')) {
            try {
                $this->execSQL("CREATE INDEX idx_clients_moxie_client_id ON clients(moxie_client_id)");
            } catch (PDOException $e) {
                error_log("Migration: could not create clients moxie_client_id index - " . $e->getMessage());
            }
        }
        if (!$this->indexExists('clients', 'idx_clients_name_phone')) {
            try {
                $this->execSQL(self::MYSQL_CLIENT_NAME_PHONE_INDEX_SQL);
            } catch (PDOException $e) {
                error_log("Migration: could not create clients name/phone index - " . $e->getMessage());
            }
        }
        
        // Add unique_link column to appointment_types table
        $apt_column_names = $this->getTableColumns('appointment_types');

        if (!in_array('admin_user_id', $apt_column_names)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN admin_user_id INTEGER DEFAULT NULL");
        }
        
        if (!in_array('unique_link', $apt_column_names)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN unique_link VARCHAR(255)");
            
            // Create a unique index on the unique_link column
            try {
                $this->execSQL("CREATE UNIQUE INDEX idx_appointment_types_unique_link ON appointment_types(unique_link)");
            } catch (PDOException $e) {
                // Index might already exist, ignore
            }
            
            // Generate unique links for existing appointment types with collision detection
            $stmt = $this->conn->query("SELECT id FROM appointment_types WHERE unique_link IS NULL");
            $existing_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $update_stmt = $this->conn->prepare("UPDATE appointment_types SET unique_link = ? WHERE id = ?");
            foreach ($existing_types as $type) {
                // Generate unique link with collision detection
                do {
                    $unique_link = bin2hex(random_bytes(16));
                    $check_stmt = $this->conn->prepare("SELECT COUNT(*) FROM appointment_types WHERE unique_link = ?");
                    $check_stmt->execute([$unique_link]);
                    $exists = $check_stmt->fetchColumn();
                } while ($exists > 0);
                
                $update_stmt->execute([$unique_link, $type['id']]);
            }
        }
        
        // Add availability configuration columns to appointment_types table
        if (!in_array('available_days', $apt_column_names)) {
            // JSON array of available day numbers (0=Sunday, 1=Monday, ..., 6=Saturday)
            // Default to all days [0,1,2,3,4,5,6]
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN available_days TEXT DEFAULT '[0,1,2,3,4,5,6]'");
            
            // Set default for existing rows
            $this->execSQL("UPDATE appointment_types SET available_days = '[0,1,2,3,4,5,6]' WHERE available_days IS NULL");
        }
        
        if (!in_array('available_start_time', $apt_column_names)) {
            // Default start time (9:00 AM)
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN available_start_time TEXT DEFAULT '09:00'");
            
            // Set default for existing rows
            $this->execSQL("UPDATE appointment_types SET available_start_time = '09:00' WHERE available_start_time IS NULL");
        }
        
        if (!in_array('available_end_time', $apt_column_names)) {
            // Default end time (5:00 PM)
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN available_end_time TEXT DEFAULT '17:00'");
            
            // Set default for existing rows
            $this->execSQL("UPDATE appointment_types SET available_end_time = '17:00' WHERE available_end_time IS NULL");
        }
        
        if (!in_array('time_slot_interval', $apt_column_names)) {
            // Time slot interval in minutes (default 30)
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN time_slot_interval INTEGER DEFAULT 30");
            
            // Set default for existing rows
            $this->execSQL("UPDATE appointment_types SET time_slot_interval = 30 WHERE time_slot_interval IS NULL");
        }
        
        // Add schedule_type column for specific date scheduling
        if (!in_array('schedule_type', $apt_column_names)) {
            // Schedule type: 'recurring' for day-of-week based, 'specific_date' for one-time classes
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN schedule_type TEXT DEFAULT 'recurring'");
            
            // Set default for existing rows to maintain backward compatibility
            $this->execSQL("UPDATE appointment_types SET schedule_type = 'recurring' WHERE schedule_type IS NULL");
        }
        
        // Add specific_date column for one-time scheduled classes
        if (!in_array('specific_date', $apt_column_names)) {
            // Date for specific date scheduling (NULL for recurring schedules)
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN specific_date DATE");
        }
        
        // Add Mini Sessions support to appointment_types table
        if (!in_array('is_mini_session', $apt_column_names)) {
            // Flag to indicate this is a Mini Sessions appointment type
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN is_mini_session INTEGER DEFAULT 0");
            
            // Set default for existing rows
            $this->execSQL("UPDATE appointment_types SET is_mini_session = 0 WHERE is_mini_session IS NULL");
        }
        
        if (!in_array('mini_session_location', $apt_column_names)) {
            // Fixed location for Mini Sessions events
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN mini_session_location TEXT");
        }
        
        if (!in_array('mini_session_topic', $apt_column_names)) {
            // Topic/description for the Mini Sessions event
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN mini_session_topic TEXT");
        }
        
        // Add location support to bookings table
        $booking_column_names = $this->getTableColumns('bookings');
        
        if (!in_array('location', $booking_column_names)) {
            // Location/address for the appointment (especially for Mini Sessions)
            $this->execSQL("ALTER TABLE bookings ADD COLUMN location TEXT");
        }
        
        // Create mini_session_blocks table for managing individual time blocks
        $this->execSQL("
            CREATE TABLE IF NOT EXISTS mini_session_blocks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                appointment_type_id INTEGER NOT NULL,
                event_date DATE NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                topic TEXT,
                location TEXT NOT NULL,
                is_available INTEGER DEFAULT 1,
                booking_id INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (appointment_type_id) REFERENCES appointment_types(id) ON DELETE CASCADE,
                FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL
            )
        ");
        
        // Create index for efficient querying of available blocks
        try {
            $this->execSQL("CREATE INDEX idx_mini_session_blocks_event_date ON mini_session_blocks(event_date)");
        } catch (PDOException $e) {
            // Index might already exist, ignore
        }
        
        try {
            $this->execSQL("CREATE INDEX idx_mini_session_blocks_appointment_type ON mini_session_blocks(appointment_type_id)");
        } catch (PDOException $e) {
            // Index might already exist, ignore
        }
        
        // Add Field Rental support to appointment_types table
        if (!in_array('is_field_rental', $apt_column_names)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN is_field_rental INTEGER DEFAULT 0");
            $this->execSQL("UPDATE appointment_types SET is_field_rental = 0 WHERE is_field_rental IS NULL");
        }
        
        if (!in_array('field_rental_location', $apt_column_names)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN field_rental_location TEXT");
        }

        if (!in_array('per_day_schedule', $apt_column_names)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN per_day_schedule TEXT");
        }

        // Add specific_dates column for multi-date scheduling (JSON array of date+timeslot configs)
        if (!in_array('specific_dates', $apt_column_names)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN specific_dates TEXT");
        }

        // Add default_amount column to appointment_types table
        if (!in_array('default_amount', $apt_column_names)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN default_amount REAL DEFAULT 0");
        }

        // Create packages table (bundle definitions)
        $this->execSQL("
            CREATE TABLE IF NOT EXISTS packages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                price REAL DEFAULT 0,
                expiration_days INTEGER,
                is_active INTEGER DEFAULT 1,
                share_token TEXT,
                form_template_id INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Migrate legacy session_type-based tables to appointment_type_id-based schema.
        // Since the app is in development mode, existing data is intentionally wiped.
        if ($this->tableExists('package_items')) {
            $pi_cols = $this->getTableColumns('package_items');
            if (in_array('session_type', $pi_cols)) {
                // Old schema detected — drop all dependent tables in reverse-dependency order
                $this->execSQL("DROP TABLE IF EXISTS package_credit_transactions");
                $this->execSQL("DROP TABLE IF EXISTS client_package_credits");
                $this->execSQL("DROP TABLE IF EXISTS client_packages");
                $this->execSQL("DROP TABLE IF EXISTS package_items");
            }
        }

        // Create package_items table (per appointment-type allocations within a package)
        $this->execSQL("
            CREATE TABLE IF NOT EXISTS package_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                package_id INTEGER NOT NULL,
                appointment_type_id INTEGER NOT NULL,
                quantity INTEGER NOT NULL DEFAULT 1,
                FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
                FOREIGN KEY (appointment_type_id) REFERENCES appointment_types(id) ON DELETE CASCADE
            )
        ");
        
        // Create client_packages table (client purchases of packages)
        $this->execSQL("
            CREATE TABLE IF NOT EXISTS client_packages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NOT NULL,
                package_id INTEGER NOT NULL,
                package_name TEXT NOT NULL,
                purchased_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP,
                is_active INTEGER DEFAULT 1,
                notes TEXT,
                payment_method TEXT,
                stripe_checkout_session_id VARCHAR(255) NULL,
                created_by INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE RESTRICT,
                FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL
            )
        ");
        
        // Create client_package_credits table (per-appointment-type credit tracking per purchased package)
        $this->execSQL("
            CREATE TABLE IF NOT EXISTS client_package_credits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_package_id INTEGER NOT NULL,
                client_id INTEGER NOT NULL,
                appointment_type_id INTEGER NOT NULL,
                total_credits INTEGER NOT NULL DEFAULT 0,
                used_credits INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (client_package_id) REFERENCES client_packages(id) ON DELETE CASCADE,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                FOREIGN KEY (appointment_type_id) REFERENCES appointment_types(id) ON DELETE CASCADE
            )
        ");
        
        // Create package_credit_transactions table (audit trail for package credit usage)
        $this->execSQL("
            CREATE TABLE IF NOT EXISTS package_credit_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_package_credit_id INTEGER NOT NULL,
                client_id INTEGER NOT NULL,
                appointment_type_id INTEGER NOT NULL,
                transaction_type TEXT NOT NULL,
                amount INTEGER NOT NULL,
                booking_id INTEGER,
                notes TEXT,
                created_by INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (client_package_credit_id) REFERENCES client_package_credits(id) ON DELETE CASCADE,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                FOREIGN KEY (appointment_type_id) REFERENCES appointment_types(id) ON DELETE CASCADE,
                FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL
            )
        ");
        
        try {
            $this->execSQL("CREATE INDEX idx_client_package_credits_client ON client_package_credits(client_id)");
        } catch (PDOException $e) {
            // Index might already exist, ignore
        }
        
        try {
            $this->execSQL("CREATE INDEX idx_client_packages_client ON client_packages(client_id)");
        } catch (PDOException $e) {
            // Index might already exist, ignore
        }

        try {
            $this->execSQL("CREATE UNIQUE INDEX idx_client_packages_package_stripe_session ON client_packages(package_id, stripe_checkout_session_id)");
        } catch (PDOException $e) {
            // Index might already exist, ignore
        }

        // Add package_credit_id to bookings for tracking which package credit was consumed
        $booking_column_names_pkg = $this->getTableColumns('bookings');
        if (!in_array('package_credit_id', $booking_column_names_pkg)) {
            $this->execSQL("ALTER TABLE bookings ADD COLUMN package_credit_id INTEGER");
        }

        // Add location_type to bookings for standardized location handling
        // Supported types: client_address, custom_address, phone_inbound, phone_outbound, webcall, fixed
        $booking_cols_loc = $this->getTableColumns('bookings');
        if (!in_array('location_type', $booking_cols_loc)) {
            $this->execSQL("ALTER TABLE bookings ADD COLUMN location_type TEXT");
        }

        // Add location_types (JSON array) to appointment_types so admins can configure allowed location options
        $apt_col_names_loc = $this->getTableColumns('appointment_types');
        if (!in_array('location_types', $apt_col_names_loc)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN location_types TEXT");
        }

        // Add share_token to packages for shareable public links
        $pkg_column_names = $this->getTableColumns('packages');
        if (!in_array('share_token', $pkg_column_names)) {
            $this->execSQL("ALTER TABLE packages ADD COLUMN share_token TEXT");
        }
        if (!in_array('form_template_id', $pkg_column_names)) {
            $this->execSQL("ALTER TABLE packages ADD COLUMN form_template_id INTEGER");
        }

        $client_package_column_names = $this->getTableColumns('client_packages');
        if (!in_array('payment_method', $client_package_column_names)) {
            $this->execSQL("ALTER TABLE client_packages ADD COLUMN payment_method TEXT");
        }
        if (!in_array('stripe_checkout_session_id', $client_package_column_names)) {
            $this->execSQL("ALTER TABLE client_packages ADD COLUMN stripe_checkout_session_id VARCHAR(255) NULL");
        }

        // Create package_link_views table for analytics
        $this->execSQL("
            CREATE TABLE IF NOT EXISTS package_link_views (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                package_id INTEGER NOT NULL,
                ip_address TEXT,
                user_agent TEXT,
                referrer TEXT,
                purchased INTEGER DEFAULT 0,
                client_id INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE
            )
        ");

        $this->execSQL("
            CREATE TABLE IF NOT EXISTS package_pending_purchases (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                package_id INTEGER NOT NULL,
                package_token TEXT NOT NULL,
                stripe_checkout_session_id VARCHAR(255) NOT NULL,
                buyer_name TEXT NOT NULL,
                buyer_email TEXT NOT NULL,
                buyer_phone TEXT,
                notes TEXT,
                form_responses TEXT,
                view_id INTEGER,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (package_id) REFERENCES packages(id) ON DELETE CASCADE,
                FOREIGN KEY (view_id) REFERENCES package_link_views(id) ON DELETE SET NULL
            )
        ");

        try {
            $this->execSQL("CREATE UNIQUE INDEX idx_package_pending_purchases_package_session ON package_pending_purchases(package_id, stripe_checkout_session_id)");
        } catch (PDOException $e) {
            // Index might already exist, ignore
        }

        // Add portal_available column to appointment_types
        $apt_column_names = $this->getTableColumns('appointment_types');
        if (!in_array('portal_available', $apt_column_names)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN portal_available INTEGER DEFAULT 0");
            $this->execSQL("UPDATE appointment_types SET portal_available = 0 WHERE portal_available IS NULL");
        }

        // Add contract_template_id column to appointment_types (replaces requires_contract toggle)
        $apt_column_names = $this->getTableColumns('appointment_types');
        if (!in_array('contract_template_id', $apt_column_names)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN contract_template_id INTEGER DEFAULT NULL REFERENCES contract_templates(id) ON DELETE SET NULL");
        }

        // Add contract_accepted and contract_template_id columns to bookings for recording client acceptance
        $booking_column_names = $this->getTableColumns('bookings');
        if (!in_array('contract_accepted', $booking_column_names)) {
            $this->execSQL("ALTER TABLE bookings ADD COLUMN contract_accepted INTEGER DEFAULT 0");
        }
        if (!in_array('contract_accepted_at', $booking_column_names)) {
            $this->execSQL("ALTER TABLE bookings ADD COLUMN contract_accepted_at TIMESTAMP");
        }
        if (!in_array('contract_signature_name', $booking_column_names)) {
            $this->execSQL("ALTER TABLE bookings ADD COLUMN contract_signature_name TEXT");
        }
        if (!in_array('contract_signature_font', $booking_column_names)) {
            $this->execSQL("ALTER TABLE bookings ADD COLUMN contract_signature_font TEXT");
        }

        // Create portal_content table for customizable homepage
        $this->execSQL("
            CREATE TABLE IF NOT EXISTS portal_content (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                content_html TEXT,
                notice_html TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_by INTEGER
            )
        ");
        $count = $this->conn->query("SELECT COUNT(*) FROM portal_content")->fetchColumn();
        if ($count == 0) {
            $this->execSQL("INSERT INTO portal_content (content_html, notice_html) VALUES ('', '')");
        }

        // Create client_activity_log table
        $this->execSQL("
            CREATE TABLE IF NOT EXISTS client_activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id INTEGER NOT NULL,
                action TEXT NOT NULL,
                description TEXT,
                ip_address TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
            )
        ");

        // Create notifications table for admin and portal notification centers
        $this->execSQL("
            CREATE TABLE IF NOT EXISTS notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                audience TEXT NOT NULL,
                recipient_id INTEGER NOT NULL,
                entity_type TEXT NOT NULL,
                entity_id INTEGER DEFAULT 0,
                title TEXT NOT NULL,
                message TEXT,
                url TEXT NOT NULL,
                is_read INTEGER DEFAULT 0,
                read_at TIMESTAMP NULL,
                deleted_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Create appointment_type_forms join table for many-to-many relationship
        $this->execSQL("
            CREATE TABLE IF NOT EXISTS appointment_type_forms (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                appointment_type_id INTEGER NOT NULL,
                form_template_id INTEGER NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (appointment_type_id) REFERENCES appointment_types(id) ON DELETE CASCADE,
                FOREIGN KEY (form_template_id) REFERENCES form_templates(id) ON DELETE CASCADE,
                UNIQUE(appointment_type_id, form_template_id)
            )
        ");

        // Add typed-signature columns to contracts (electronic signature feature)
        $contract_column_names = $this->getTableColumns('contracts');
        if (!in_array('signature_typed_name', $contract_column_names)) {
            $this->execSQL("ALTER TABLE contracts ADD COLUMN signature_typed_name TEXT");
        }
        if (!in_array('signature_font', $contract_column_names)) {
            $this->execSQL("ALTER TABLE contracts ADD COLUMN signature_font TEXT");
        }

        // Create contract_signature_log table for audit trail
        $this->execSQL("
            CREATE TABLE IF NOT EXISTS contract_signature_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contract_id INTEGER NOT NULL,
                event_type TEXT NOT NULL,
                details TEXT,
                ip_address TEXT,
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
            )
        ");

        // Add direction column to unmatched_emails to distinguish incoming vs outgoing
        $unmatched_email_columns = $this->getTableColumns('unmatched_emails');
        if (!in_array('direction', $unmatched_email_columns)) {
            $this->execSQL("ALTER TABLE unmatched_emails ADD COLUMN direction TEXT DEFAULT 'incoming'");
        }

        // Add email template override columns to appointment_types
        $apt_col_names_tmpl = $this->getTableColumns('appointment_types');
        if (!in_array('confirmation_template_id', $apt_col_names_tmpl)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN confirmation_template_id INTEGER DEFAULT NULL");
        }
        if (!in_array('reminder_template_id', $apt_col_names_tmpl)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN reminder_template_id INTEGER DEFAULT NULL");
        }
        if (!in_array('cancellation_template_id', $apt_col_names_tmpl)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN cancellation_template_id INTEGER DEFAULT NULL");
        }
        if (!in_array('booking_request_template_id', $apt_col_names_tmpl)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN booking_request_template_id INTEGER DEFAULT NULL");
        }
        if (!in_array('requires_admin_confirmation', $apt_col_names_tmpl)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN requires_admin_confirmation INTEGER DEFAULT 0");
        }

        // Add unique index for booking_reminders_sent to prevent duplicate sends per rule
        try {
            $this->execSQL("CREATE UNIQUE INDEX idx_booking_reminders_sent_unique ON booking_reminders_sent(booking_id, rule_id)");
        } catch (PDOException $e) {
            // Index might already exist, ignore
        }

        // Add group class location support to appointment_types table
        $apt_col_names_gc = $this->getTableColumns('appointment_types');
        if (!in_array('group_class_location', $apt_col_names_gc)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN group_class_location TEXT");
        }

        // Add appointment_type_id to booking_reminder_rules for per-appointment-type rules.
        $brr_cols = $this->getTableColumns('booking_reminder_rules');
        if (!in_array('appointment_type_id', $brr_cols)) {
            $this->execSQL("ALTER TABLE booking_reminder_rules ADD COLUMN appointment_type_id INTEGER DEFAULT NULL");
        }

        // Seed a default reminder rule if none exist
        $rule_count = $this->conn->query("SELECT COUNT(*) FROM booking_reminder_rules")->fetchColumn();
        if ($rule_count == 0) {
            $this->execSQL("INSERT INTO booking_reminder_rules (name, hours_before, is_active) VALUES ('Day Before', 24, 1)");
            $this->execSQL("INSERT INTO booking_reminder_rules (name, hours_before, is_active) VALUES ('2 Days Before', 48, 0)");
        }

        // Add default email template settings for automated tasks
        $this->addEmailTemplateDefaultSettings();

        // Add database settings for existing installations
        $this->addDatabaseSettings();

        // Add booking form customization setting
        $this->addBookingFormSetting();

        // Add cancellation/reschedule advance notice period to appointment_types
        $apt_col_names_notice = $this->getTableColumns('appointment_types');
        if (!in_array('cancellation_notice_hours', $apt_col_names_notice)) {
            // Minimum hours before appointment that clients can cancel or reschedule (0 = no restriction)
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN cancellation_notice_hours INTEGER DEFAULT 0");
            $this->execSQL("UPDATE appointment_types SET cancellation_notice_hours = 0 WHERE cancellation_notice_hours IS NULL");
        }

        $apt_col_names_resources = $this->getTableColumns('appointment_types');
        if (!in_array('uses_resource', $apt_col_names_resources)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN uses_resource INTEGER DEFAULT 0");
            $this->execSQL("UPDATE appointment_types SET uses_resource = 0 WHERE uses_resource IS NULL");
        }
        if (!in_array('resource_name', $apt_col_names_resources)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN resource_name TEXT");
        }
        if (!in_array('resource_capacity', $apt_col_names_resources)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN resource_capacity INTEGER DEFAULT 1");
            $this->execSQL("UPDATE appointment_types SET resource_capacity = 1 WHERE resource_capacity IS NULL OR resource_capacity < 1");
        }
        if (!in_array('resource_allocation', $apt_col_names_resources)) {
            $this->execSQL("ALTER TABLE appointment_types ADD COLUMN resource_allocation TEXT DEFAULT 'per_appointment'");
            $this->execSQL("UPDATE appointment_types SET resource_allocation = 'per_appointment' WHERE resource_allocation IS NULL OR resource_allocation = ''");
        }

        // Create booking_change_log table for auditing client-initiated cancellations and reschedules
        $this->execSQL("
            CREATE TABLE IF NOT EXISTS booking_change_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                booking_id INTEGER NOT NULL,
                client_id INTEGER,
                change_type TEXT NOT NULL,
                reason TEXT,
                old_date DATE,
                old_time TIME,
                new_date DATE,
                new_time TIME,
                initiated_by TEXT NOT NULL DEFAULT 'client',
                ip_address TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
                FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL
            )
        ");

        // Create Google OAuth tokens table for per-user OAuth calendar integration
        $this->execSQL("
            CREATE TABLE IF NOT EXISTS google_oauth_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                admin_user_id INTEGER NOT NULL,
                access_token TEXT NOT NULL,
                refresh_token TEXT,
                token_type TEXT DEFAULT 'Bearer',
                expires_at TIMESTAMP,
                calendar_id TEXT DEFAULT 'primary',
                google_email TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE
            )
        ");

        // Add Google OAuth settings for existing installations
        $this->addGoogleOAuthSettings();

        // Add Moxie settings for existing installations
        $this->addMoxieSettings();

        // Add theme customization settings for existing installations
        $this->addThemeSettings();

        // Add social link settings for existing installations
        $this->addSocialSettings();

        // Add Tawk.to chat widget settings for existing installations
        $this->addTawkToSettings();

        // Add google_event_id column to bookings so cancelled bookings can be
        // removed from the connected Google Calendar
        $booking_col_names_gcal = $this->getTableColumns('bookings');
        if (!in_array('admin_user_id', $booking_col_names_gcal)) {
            $this->execSQL("ALTER TABLE bookings ADD COLUMN admin_user_id INTEGER DEFAULT NULL");
        }
        if (!in_array('google_event_id', $booking_col_names_gcal)) {
            $this->execSQL("ALTER TABLE bookings ADD COLUMN google_event_id TEXT DEFAULT NULL");
        }

        // Add mail_type column to client_emails to categorise automated vs composed emails
        $client_emails_cols = $this->getTableColumns('client_emails');
        if (!in_array('mail_type', $client_emails_cols)) {
            $this->execSQL("ALTER TABLE client_emails ADD COLUMN mail_type TEXT DEFAULT NULL");
        }
        if (!in_array('message_id', $client_emails_cols)) {
            $this->execSQL("ALTER TABLE client_emails ADD COLUMN message_id TEXT DEFAULT NULL");
        }

        if (!in_array('message_id', $unmatched_email_columns)) {
            $this->execSQL("ALTER TABLE unmatched_emails ADD COLUMN message_id TEXT DEFAULT NULL");
        }
        if (!$this->indexExists('client_emails', 'idx_client_emails_message_id')) {
            try {
                $this->execSQL(self::MYSQL_CLIENT_EMAILS_MESSAGE_ID_INDEX_SQL);
            } catch (PDOException $e) {
                error_log("Migration: could not create client_emails message_id index - " . $e->getMessage());
            }
        }
        $client_emails_collation = $this->tableCollation('client_emails');
        if ($client_emails_collation === null || !str_starts_with($client_emails_collation, 'utf8mb4_')) {
            try {
                $this->conn->exec(self::MYSQL_CLIENT_EMAILS_UTF8MB4_SQL);
            } catch (PDOException $e) {
                error_log("Migration: could not convert client_emails to utf8mb4 - " . $e->getMessage());
            }
        }
        if (!$this->indexExists('unmatched_emails', 'idx_unmatched_emails_message_id')) {
            try {
                $this->execSQL(self::MYSQL_UNMATCHED_EMAILS_MESSAGE_ID_INDEX_SQL);
            } catch (PDOException $e) {
                error_log("Migration: could not create unmatched_emails message_id index - " . $e->getMessage());
            }
        }

        // Add item_type and reference_id to quote_items to support package and appointment type line items
        $quote_items_cols = $this->getTableColumns('quote_items');
        if (!in_array('item_type', $quote_items_cols)) {
            $this->execSQL("ALTER TABLE quote_items ADD COLUMN item_type TEXT NOT NULL DEFAULT 'custom'");
        }
        if (!in_array('reference_id', $quote_items_cols)) {
            $this->execSQL("ALTER TABLE quote_items ADD COLUMN reference_id INTEGER DEFAULT NULL");
        }

        // Create site_pages table for the front-end visual page editor
        $this->execSQL("
            CREATE TABLE IF NOT EXISTS site_pages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                html_content TEXT,
                css_content TEXT,
                meta_description TEXT,
                is_homepage INTEGER NOT NULL DEFAULT 0,
                is_published INTEGER NOT NULL DEFAULT 0,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_by INTEGER
            )
        ");
        // Seed the home page entry if none exists yet
        $sp_count = $this->conn->query("SELECT COUNT(*) FROM site_pages WHERE slug = 'home'")->fetchColumn();
        if ($sp_count == 0) {
            $this->execSQL("INSERT INTO site_pages (slug, title, html_content, css_content, is_homepage, is_published, sort_order)
                VALUES ('home', 'Home', '', '', 1, 0, 0)");
        }

        // Add SEO columns to site_pages if they don't exist yet
        $sp_column_names = $this->getTableColumns('site_pages');
        if (!in_array('meta_keywords', $sp_column_names)) {
            $this->execSQL("ALTER TABLE site_pages ADD COLUMN meta_keywords TEXT");
        }
        if (!in_array('og_title', $sp_column_names)) {
            $this->execSQL("ALTER TABLE site_pages ADD COLUMN og_title TEXT");
        }
        if (!in_array('og_description', $sp_column_names)) {
            $this->execSQL("ALTER TABLE site_pages ADD COLUMN og_description TEXT");
        }
        if (!in_array('og_image', $sp_column_names)) {
            $this->execSQL("ALTER TABLE site_pages ADD COLUMN og_image TEXT");
        }

        // Ensure site_pages.html_content and css_content are wide enough on MySQL
        // before seeding manual SiteBuilder pages. On fresh MySQL installs these
        // columns may initially be TEXT, which can cause truncation or insert
        // failures under strict SQL mode when inserting large HTML/CSS blobs.
        try {
            // Check and widen html_content if necessary
            $htmlColStmt = $this->conn->query("SHOW COLUMNS FROM site_pages LIKE 'html_content'");
            $htmlColInfo = $htmlColStmt->fetch(PDO::FETCH_ASSOC);
            if ($htmlColInfo && isset($htmlColInfo['Type']) && stripos((string)$htmlColInfo['Type'], 'mediumtext') === false) {
                $this->execSQL("ALTER TABLE site_pages MODIFY COLUMN html_content MEDIUMTEXT");
            }

            // Check and widen css_content if necessary
            $cssColStmt = $this->conn->query("SHOW COLUMNS FROM site_pages LIKE 'css_content'");
            $cssColInfo = $cssColStmt->fetch(PDO::FETCH_ASSOC);
            if ($cssColInfo && isset($cssColInfo['Type']) && stripos((string)$cssColInfo['Type'], 'mediumtext') === false) {
                $this->execSQL("ALTER TABLE site_pages MODIFY COLUMN css_content MEDIUMTEXT");
            }
        } catch (PDOException $e) {
            // If this adjustment fails, continue; a later migration may already
            // have handled the MEDIUMTEXT widening.
            error_log("Migration: could not ensure MEDIUMTEXT for site_pages content columns - " . $e->getMessage());
        }
        $this->seedManualSiteBuilderPages();

        // Widen the form_templates.fields and form_submissions.responses columns on
        // MySQL installations where the TEXT → VARCHAR(255) conversion was previously
        // applied, so that large JSON payloads are no longer truncated.
        try {
            $this->conn->exec("ALTER TABLE client_packages MODIFY COLUMN stripe_checkout_session_id VARCHAR(255) NULL");
        } catch (PDOException $e) {
            error_log("Migration: could not modify client_packages.stripe_checkout_session_id - " . $e->getMessage());
        }
        try {
            $this->conn->exec("ALTER TABLE package_pending_purchases MODIFY COLUMN stripe_checkout_session_id VARCHAR(255) NOT NULL");
        } catch (PDOException $e) {
            error_log("Migration: could not modify package_pending_purchases.stripe_checkout_session_id - " . $e->getMessage());
        }
        try {
            $this->conn->exec("ALTER TABLE form_templates MODIFY COLUMN fields MEDIUMTEXT NOT NULL");
        } catch (PDOException $e) {
            error_log("Migration: could not modify form_templates.fields - " . $e->getMessage());
        }
        try {
            $this->conn->exec("ALTER TABLE form_submissions MODIFY COLUMN responses MEDIUMTEXT NOT NULL");
        } catch (PDOException $e) {
            error_log("Migration: could not modify form_submissions.responses - " . $e->getMessage());
        }
        // Widen contract_templates.template_text and contracts.contract_text on MySQL
        // installations where the TEXT NOT NULL → VARCHAR(255) conversion was previously
        // applied by convertSQL(), which capped long contract/template content at 255 chars.
        try {
            $this->conn->exec("ALTER TABLE contract_templates MODIFY COLUMN template_text MEDIUMTEXT NOT NULL");
        } catch (PDOException $e) {
            error_log("Migration: could not modify contract_templates.template_text - " . $e->getMessage());
        }
        try {
            $this->conn->exec("ALTER TABLE contracts MODIFY COLUMN contract_text MEDIUMTEXT NOT NULL");
        } catch (PDOException $e) {
            error_log("Migration: could not modify contracts.contract_text - " . $e->getMessage());
        }
        // Widen email_templates.body_html and body_text on MySQL installations
        // where TEXT (~64 KB) is too small for large HTML email templates.
        try {
            $this->conn->exec("ALTER TABLE email_templates MODIFY COLUMN body_html MEDIUMTEXT NOT NULL");
        } catch (PDOException $e) {
            error_log("Migration: could not modify email_templates.body_html - " . $e->getMessage());
        }
        try {
            $this->conn->exec("ALTER TABLE email_templates MODIFY COLUMN body_text MEDIUMTEXT");
        } catch (PDOException $e) {
            error_log("Migration: could not modify email_templates.body_text - " . $e->getMessage());
        }
        // Widen email_signature_templates.html_content on MySQL installations
        // where TEXT (~64 KB) is too small for large HTML email signatures.
        try {
            $this->conn->exec("ALTER TABLE email_signature_templates MODIFY COLUMN html_content MEDIUMTEXT NOT NULL");
        } catch (PDOException $e) {
            error_log("Migration: could not modify email_signature_templates.html_content - " . $e->getMessage());
        }
        // Widen site_pages HTML/CSS columns so inline images (data URIs) do not overflow TEXT (~64 KB)
        try {
            $this->conn->exec("ALTER TABLE site_pages MODIFY COLUMN html_content MEDIUMTEXT");
        } catch (PDOException $e) {
            error_log("Migration: could not modify site_pages.html_content - " . $e->getMessage());
        }
        try {
            $this->conn->exec("ALTER TABLE site_pages MODIFY COLUMN css_content MEDIUMTEXT");
        } catch (PDOException $e) {
            error_log("Migration: could not modify site_pages.css_content - " . $e->getMessage());
        }
        // Widen blog_posts.content on MySQL installations where TEXT (~64 KB)
        // is too small for large rich-text blog post content.
        try {
            $this->conn->exec("ALTER TABLE blog_posts MODIFY COLUMN content MEDIUMTEXT NOT NULL");
        } catch (PDOException $e) {
            error_log("Migration: could not modify blog_posts.content - " . $e->getMessage());
        }
    }
    
    private function addEmailTemplateDefaultSettings(): void {
        $template_settings = [
            'default_confirmation_template_id',
            'default_booking_request_template_id',
            'default_reminder_template_id',
            'default_payment_receipt_template_id',
            'default_cancellation_template_id',
        ];
        $labels = [
            'default_confirmation_template_id' => 'Default Booking Confirmation Template',
            'default_booking_request_template_id' => 'Default Booking Request Template',
            'default_reminder_template_id'      => 'Default Booking Reminder Template',
            'default_payment_receipt_template_id' => 'Default Payment Receipt Template',
            'default_cancellation_template_id'  => 'Default Booking Cancellation Template',
        ];
        $descriptions = [
            'default_confirmation_template_id' => 'Email template used for booking confirmations (0 = use built-in template)',
            'default_booking_request_template_id' => 'Email template used for pending booking requests (0 = use built-in template)',
            'default_reminder_template_id'      => 'Email template used for booking reminders (0 = use built-in template)',
            'default_payment_receipt_template_id' => 'Email template used for payment receipts (0 = use built-in template)',
            'default_cancellation_template_id'  => 'Email template used for booking cancellation notifications (0 = use built-in template)',
        ];

        $check = $this->conn->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
        $insert = $this->conn->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_type, category, label, description, is_secret)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($template_settings as $key) {
            $check->execute([$key]);
            if ($check->fetchColumn() == 0) {
                try {
                    $insert->execute([$key, '0', 'number', 'email', $labels[$key], $descriptions[$key], 0]);
                } catch (PDOException $e) {
                    // Already exists, ignore
                }
            }
        }
    }

    private function addDatabaseSettings(): void {
        try {
            $this->conn->prepare("DELETE FROM settings WHERE setting_key IN (?, ?)")
                ->execute(['db_type', 'sqlite_db_path']);
        } catch (PDOException $e) {
            // Ignore cleanup failures for obsolete settings.
        }

        $database_settings = [
            ['db_host', 'localhost', 'text', 'database', 'MySQL Host', 'MySQL server hostname', 0],
            ['db_port', '3306', 'number', 'database', 'MySQL Port', 'MySQL server port', 0],
            ['db_name', 'bdta', 'text', 'database', 'MySQL Database', 'MySQL database name', 0],
            ['db_user', 'bdta_user', 'text', 'database', 'MySQL Username', 'MySQL username', 0],
            ['db_password', '', 'password', 'database', 'MySQL Password', 'MySQL password', 1],
        ];

        $check = $this->conn->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
        $insert = $this->conn->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_type, category, label, description, is_secret)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($database_settings as $setting) {
            $check->execute([$setting[0]]);
            if ($check->fetchColumn() == 0) {
                try {
                    $insert->execute($setting);
                } catch (PDOException $e) {
                    // Setting might already exist, ignore
                }
            }
        }
    }
    private function addGoogleOAuthSettings(): void {
        $oauth_settings = [
            ['google_oauth_client_id', '', 'text', 'calendar', 'OAuth Client ID', 'Google OAuth 2.0 Client ID (from Google Cloud Console)', 0],
            ['google_oauth_client_secret', '', 'password', 'calendar', 'OAuth Client Secret', 'Google OAuth 2.0 Client Secret (from Google Cloud Console)', 1],
            ['google_oauth_redirect_uri', '', 'text', 'calendar', 'OAuth Redirect URI', 'OAuth callback URL (e.g. https://yourdomain.com/backend/public/google_oauth_callback.php)', 0],
        ];

        $check = $this->conn->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
        $insert = $this->conn->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_type, category, label, description, is_secret)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($oauth_settings as $setting) {
            $check->execute([$setting[0]]);
            if ($check->fetchColumn() == 0) {
                try {
                    $insert->execute($setting);
                } catch (PDOException $e) {
                    // Already exists, ignore
                }
            }
        }
    }

    private function addMoxieSettings(): void {
        $moxie_settings = [
            ['moxie_base_url', '', 'url', 'advanced', 'Moxie Base URL', 'Workspace base URL used for the Moxie public API client import tool (for example, https://pod00.withmoxie.dev).', 0],
            ['moxie_api_key', '', 'password', 'advanced', 'Moxie API Key', 'API key for importing clients from Moxie.', 1],
        ];

        $check = $this->conn->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
        $insert = $this->conn->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_type, category, label, description, is_secret)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($moxie_settings as $setting) {
            $check->execute([$setting[0]]);
            if ($check->fetchColumn() == 0) {
                try {
                    $insert->execute($setting);
                } catch (PDOException $e) {
                    // Already exists, ignore
                }
            }
        }

        try {
            $this->conn->prepare("
                UPDATE settings
                SET setting_type = ?, label = ?, description = ?, is_secret = ?
                WHERE setting_key = ?
            ")->execute([
                'url',
                'Moxie Base URL',
                'Workspace base URL used for the Moxie public API client import tool (for example, https://pod00.withmoxie.dev).',
                0,
                'moxie_base_url',
            ]);
        } catch (PDOException $e) {
            // Setting may not exist yet, ignore
        }

        try {
            $this->conn->prepare("
                UPDATE settings
                SET setting_type = ?, label = ?, description = ?, is_secret = ?
                WHERE setting_key = ?
            ")->execute([
                'password',
                'Moxie API Key',
                'API key for importing clients from Moxie.',
                1,
                'moxie_api_key',
            ]);
        } catch (PDOException $e) {
            // Setting may not exist yet, ignore
        }
    }

    private function addThemeSettings(): void {
        $theme_settings = [
            ['theme_primary_color', '#9a0073', 'color', 'theme', 'Primary Color', 'Main branding color used for buttons, links, and highlights', 0],
            ['theme_primary_dark_color', '#7a005a', 'color', 'theme', 'Primary Dark Color', 'Darker shade of primary color used for hover states', 0],
            ['theme_secondary_color', '#0a9a9c', 'color', 'theme', 'Secondary Color', 'Secondary accent color used for success states and accents', 0],
            ['theme_accent_color', '#a39f89', 'color', 'theme', 'Accent Color', 'Supporting accent color (tan/beige tones)', 0],
            ['theme_sidebar_bg_start', '#9a0073', 'color', 'theme', 'Sidebar Gradient Start', 'Starting color of the sidebar gradient', 0],
            ['theme_sidebar_bg_end', '#7a005a', 'color', 'theme', 'Sidebar Gradient End', 'Ending color of the sidebar gradient', 0],
        ];

        $check = $this->conn->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
        $insert = $this->conn->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_type, category, label, description, is_secret)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($theme_settings as $setting) {
            $check->execute([$setting[0]]);
            if ($check->fetchColumn() == 0) {
                try {
                    $insert->execute($setting);
                } catch (PDOException $e) {
                    // Already exists, ignore
                }
            }
        }
    }

    private function addSocialSettings(): void {
        $social_settings = bdta_get_social_settings_seed_rows();

        $check = $this->conn->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
        $insert = $this->conn->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_type, category, label, description, is_secret)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($social_settings as $setting) {
            $check->execute([$setting[0]]);
            if ($check->fetchColumn() == 0) {
                try {
                    $insert->execute($setting);
                } catch (PDOException $e) {
                    // Already exists, ignore
                }
            }
        }
    }

    private function addTawkToSettings(): void {
        $tawk_settings = [
            ['tawk_to_enabled', '0', 'checkbox', 'advanced', 'Enable Tawk.to Chat', 'Load the Tawk.to chat widget on public-facing pages and the client portal. Admin pages remain excluded.', 0],
            ['tawk_to_property_id', '', 'text', 'advanced', 'Tawk.to Property ID', 'Paste the property ID from your Tawk.to embed snippet.', 0],
            ['tawk_to_widget_id', 'default', 'text', 'advanced', 'Tawk.to Widget ID', 'Optional widget ID from the Tawk.to embed snippet. Leave as "default" unless Tawk.to specifies another value.', 0],
        ];

        $check = $this->conn->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
        $insert = $this->conn->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_type, category, label, description, is_secret)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($tawk_settings as $setting) {
            $check->execute([$setting[0]]);
            if ($check->fetchColumn() == 0) {
                try {
                    $insert->execute($setting);
                } catch (PDOException $e) {
                    // Already exists, ignore
                }
            }
        }
    }
    /**
     * Add the default_booking_form_id setting to the booking category.
     * Allows admins to configure a custom Booking Intake Form template that replaces
     * the hardcoded fields on the public booking page. Idempotent: skipped if the
     * setting already exists. Called from runMigrations().
     */
    private function addBookingFormSetting(): void {
        $check = $this->conn->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
        $check->execute(['default_booking_form_id']);
        if ($check->fetchColumn() == 0) {
            try {
                $this->conn->prepare("
                    INSERT INTO settings (setting_key, setting_value, setting_type, category, label, description, is_secret)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    'default_booking_form_id',
                    '0',
                    'number',
                    'booking',
                    'Custom Booking Intake Form',
                    'Select a Booking Intake Form template to replace the default fields (Name, Email, Phone, Pet Name, Notes) on the public booking page. Leave at 0 to use the built-in fields.',
                    0
                ]);
            } catch (PDOException $e) {
                // Already exists, ignore
            }
        }
    }
}
?>
