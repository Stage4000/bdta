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
