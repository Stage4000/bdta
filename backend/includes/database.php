<?php
/**
 * Brook's Dog Training Academy - Database Configuration
 * SQLite database initialization and connection
 */

class Database {
    private $db_file = 'bdta.db';
    private $conn = null;
    
    public function __construct() {
        $this->connect();
        $this->initTables();
    }
    
    private function connect() {
        try {
            $this->conn = new PDO('sqlite:' . $this->db_file);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    private function initTables() {
        try {
            // Admin users table
            $this->conn->exec("
                CREATE TABLE IF NOT EXISTS admin_users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT UNIQUE NOT NULL,
                    password_hash TEXT NOT NULL,
                    email TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Blog posts table
            $this->conn->exec("
                CREATE TABLE IF NOT EXISTS blog_posts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    title TEXT NOT NULL,
                    slug TEXT UNIQUE NOT NULL,
                    content TEXT NOT NULL,
                    excerpt TEXT,
                    author TEXT NOT NULL,
                    published INTEGER DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Bookings table
            $this->conn->exec("
                CREATE TABLE IF NOT EXISTS bookings (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_name TEXT NOT NULL,
                    client_email TEXT NOT NULL,
                    client_phone TEXT,
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
            $this->conn->exec("
                CREATE TABLE IF NOT EXISTS clients (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    email TEXT NOT NULL,
                    phone TEXT,
                    address TEXT,
                    dog_name TEXT,
                    dog_breed TEXT,
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Time entries table
            $this->conn->exec("
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
            $this->conn->exec("
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
            $this->conn->exec("
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
                    notes TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (client_id) REFERENCES clients(id)
                )
            ");
            
            // Invoice items table
            $this->conn->exec("
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
            
            // Contracts table
            $this->conn->exec("
                CREATE TABLE IF NOT EXISTS contracts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    contract_number TEXT UNIQUE NOT NULL,
                    client_id INTEGER NOT NULL,
                    title TEXT NOT NULL,
                    description TEXT,
                    contract_text TEXT NOT NULL,
                    status TEXT DEFAULT 'draft',
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
            
            // Create default admin if not exists
            $stmt = $this->conn->prepare("SELECT id FROM admin_users WHERE username = ?");
            $stmt->execute(['admin']);
            
            if (!$stmt->fetch()) {
                $password_hash = password_hash('admin123', PASSWORD_DEFAULT);
                $stmt = $this->conn->prepare("
                    INSERT INTO admin_users (username, password_hash, email) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute(['admin', $password_hash, 'admin@brooksdogtraining.com']);
            }
            
        } catch(PDOException $e) {
            die("Table creation failed: " . $e->getMessage());
        }
    }
}
?>
