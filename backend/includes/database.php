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
            
            // Pets table (enhanced for multi-pet support)
            $this->conn->exec("
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
            
            // Appointment pets junction table (for multi-pet appointments)
            $this->conn->exec("
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
            $this->conn->exec("
                CREATE TABLE IF NOT EXISTS appointment_types (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    description TEXT,
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
                    is_active INTEGER DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            
            // Client credits table
            $this->conn->exec("
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
            $this->conn->exec("
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
            
            // Contract templates table
            $this->conn->exec("
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
            $this->conn->exec("
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
            
            // Form templates table
            $this->conn->exec("
                CREATE TABLE IF NOT EXISTS form_templates (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    description TEXT,
                    form_type TEXT NOT NULL DEFAULT 'client_form',
                    fields TEXT NOT NULL,
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
            $this->conn->exec("
                CREATE TABLE IF NOT EXISTS form_submissions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_id INTEGER NOT NULL,
                    template_id INTEGER NOT NULL,
                    booking_id INTEGER,
                    responses TEXT NOT NULL,
                    status TEXT DEFAULT 'submitted',
                    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    submitted_by INTEGER,
                    reviewed_by INTEGER,
                    reviewed_at TIMESTAMP,
                    notes TEXT,
                    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
                    FOREIGN KEY (template_id) REFERENCES form_templates(id) ON DELETE CASCADE,
                    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
                    FOREIGN KEY (submitted_by) REFERENCES admin_users(id) ON DELETE SET NULL,
                    FOREIGN KEY (reviewed_by) REFERENCES admin_users(id) ON DELETE SET NULL
                )
            ");
            
            // Quotes table
            $this->conn->exec("
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
            $this->conn->exec("
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
            
        } catch(PDOException $e) {
            die("Table creation failed: " . $e->getMessage());
        }
    }
    
    private function initDefaultSettings() {
        $default_settings = [
            // General Settings
            ['site_name', "Brook's Dog Training Academy", 'text', 'general', 'Site Name', 'The name of your business', 0],
            ['site_tagline', 'Teaching Humans to Speak Dog', 'text', 'general', 'Site Tagline', 'Your business tagline or slogan', 0],
            ['business_email', 'info@brooksdogtraining.com', 'email', 'general', 'Business Email', 'Primary contact email', 0],
            ['business_phone', '(555) 123-4567', 'text', 'general', 'Business Phone', 'Primary contact phone number', 0],
            ['business_address', 'Sebring, Florida', 'textarea', 'general', 'Business Address', 'Your business address', 0],
            ['founded_year', '2018', 'number', 'general', 'Founded Year', 'Year your business was founded', 0],
            
            // Email Settings
            ['email_from_address', 'bookings@brooksdogtraining.com', 'email', 'email', 'From Email Address', 'Email address for outgoing emails', 0],
            ['email_from_name', "Brook's Dog Training Academy", 'text', 'email', 'From Name', 'Name displayed in outgoing emails', 0],
            ['email_service', 'mail', 'select', 'email', 'Email Service', 'Email delivery service (mail, smtp, sendgrid, mailgun, ses)', 0],
            ['smtp_host', '', 'text', 'email', 'SMTP Host', 'SMTP server hostname (if using SMTP)', 0],
            ['smtp_port', '587', 'number', 'email', 'SMTP Port', 'SMTP server port', 0],
            ['smtp_username', '', 'text', 'email', 'SMTP Username', 'SMTP authentication username', 0],
            ['smtp_password', '', 'password', 'email', 'SMTP Password', 'SMTP authentication password', 1],
            ['sendgrid_api_key', '', 'password', 'email', 'SendGrid API Key', 'SendGrid API key (if using SendGrid)', 1],
            ['mailgun_api_key', '', 'password', 'email', 'Mailgun API Key', 'Mailgun API key (if using Mailgun)', 1],
            ['mailgun_domain', '', 'text', 'email', 'Mailgun Domain', 'Mailgun sending domain', 0],
            
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
            ['google_calendar_id', 'primary', 'text', 'calendar', 'Google Calendar ID', 'Google Calendar ID to sync to', 0],
            ['google_calendar_credentials_file', '', 'text', 'calendar', 'Credentials File Path', 'Path to Google Calendar credentials JSON file', 0],
            
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
            ['facebook_url', 'https://www.facebook.com/BrooksDogTrainingAcademy', 'url', 'social', 'Facebook URL', 'Facebook page URL', 0],
            ['instagram_url', 'https://www.instagram.com/brooksdogtrainingacademy', 'url', 'social', 'Instagram URL', 'Instagram profile URL', 0],
            ['linktree_url', 'https://linktr.ee/brooksdogtrainingacademy', 'url', 'social', 'Linktree URL', 'Linktree URL', 0],
            
            // Advanced
            ['base_url', 'http://localhost:8000', 'url', 'advanced', 'Base URL', 'Base URL of your website', 0],
            ['timezone', 'America/New_York', 'text', 'advanced', 'Timezone', 'Your local timezone', 0],
            ['date_format', 'Y-m-d', 'text', 'advanced', 'Date Format', 'PHP date format string', 0],
            ['time_format', 'H:i', 'text', 'advanced', 'Time Format', 'PHP time format string', 0],
        ];
        
        $stmt = $this->conn->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_type, category, label, description, is_secret)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($default_settings as $setting) {
            $stmt->execute($setting);
        }
    }
    
    private function initSampleAppointmentTypes() {
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
        
        // Email templates table
        $this->conn->exec("
            CREATE TABLE IF NOT EXISTS email_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                template_type TEXT NOT NULL,
                subject TEXT NOT NULL,
                body_html TEXT NOT NULL,
                body_text TEXT,
                variables TEXT,
                is_active INTEGER DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // Update bookings table to add new columns for enhanced booking
        // Check if columns exist before adding
        $columns = $this->conn->query("PRAGMA table_info(bookings)")->fetchAll(PDO::FETCH_ASSOC);
        $column_names = array_column($columns, 'name');
        
        if (!in_array('appointment_type_id', $column_names)) {
            $this->conn->exec("ALTER TABLE bookings ADD COLUMN appointment_type_id INTEGER");
        }
        if (!in_array('pets', $column_names)) {
            $this->conn->exec("ALTER TABLE bookings ADD COLUMN pets TEXT");
        }
        if (!in_array('override_forms', $column_names)) {
            $this->conn->exec("ALTER TABLE bookings ADD COLUMN override_forms INTEGER DEFAULT 0");
        }
        if (!in_array('override_contract', $column_names)) {
            $this->conn->exec("ALTER TABLE bookings ADD COLUMN override_contract INTEGER DEFAULT 0");
        }
        if (!in_array('override_credits', $column_names)) {
            $this->conn->exec("ALTER TABLE bookings ADD COLUMN override_credits INTEGER DEFAULT 0");
        }
    }
}
?>
