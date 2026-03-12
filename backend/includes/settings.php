<?php
/**
 * Settings Helper Functions
 * Functions to retrieve and update settings from the database
 */

require_once __DIR__ . '/database.php';

class Settings {
    private static ?SafePDO $db = null;
    /** @var array<string, mixed> */
    private static array $cache = [];
    
    private static function getDB(): SafePDO {
        if (self::$db === null) {
            $db = new Database();
            self::$db = $db->getConnection();
        }
        return self::$db;
    }
    
    /**
     * Get a setting value by key
     */
    public static function get(string $key, mixed $default = null): mixed {
        // Check cache first - use array_key_exists to handle falsy values
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        
        $db = self::getDB();
        $stmt = $db->prepare("SELECT setting_value, setting_type FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $value = self::castValue($result['setting_value'], scalar_string($result['setting_type'] ?? ''));
            self::$cache[$key] = $value;
            return $value;
        }
        
        return $default;
    }
    
    /**
     * Set a setting value
     */
    public static function set(string $key, mixed $value): bool {
        $db = self::getDB();
        
        // Get the setting type for proper caching
        $stmt = $db->prepare("SELECT setting_type FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $type = $stmt->fetchColumn();
        
        if (!$type) {
            // Setting doesn't exist
            return false;
        }
        
        $stmt = $db->prepare("
            UPDATE settings 
            SET setting_value = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE setting_key = ?
        ");
        $result = $stmt->execute([$value, $key]);
        
        // Update cache with properly cast value
        if ($result) {
            self::$cache[$key] = self::castValue($value, scalar_string($type));
        } else {
            // Clear cache on failure to ensure consistency
            unset(self::$cache[$key]);
        }
        
        return $result;
    }
    
    /**
     * Get all settings in a category
     *
     * @return list<array<string, mixed>>
     */
    public static function getCategory(string $category): array {
        // Special handling for database category - read from .env
        if ($category === 'database') {
            return self::getDatabaseSettingsFromEnv();
        }
        
        $db = self::getDB();
        $stmt = $db->prepare("
            SELECT setting_key, setting_value, setting_type, label, description, is_secret 
            FROM settings 
            WHERE category = ? 
            ORDER BY id
        ");
        $stmt->execute([$category]);
        $settings = [];
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[] = [
                'key' => $row['setting_key'],
                'value' => $row['is_secret'] ? '••••••••' : $row['setting_value'],
                'actual_value' => $row['setting_value'],
                'type' => $row['setting_type'],
                'label' => $row['label'],
                'description' => $row['description'],
                'is_secret' => $row['is_secret']
            ];
        }
        
        return $settings;
    }
    
    /**
     * Get database settings from .env file
     *
     * @return list<array<string, mixed>>
     */
    private static function getDatabaseSettingsFromEnv(): array {
        require_once __DIR__ . '/env_loader.php';
        
        return [
            [
                'key' => 'db_type',
                'value' => EnvLoader::get('DB_TYPE', 'sqlite'),
                'actual_value' => EnvLoader::get('DB_TYPE', 'sqlite'),
                'type' => 'select',
                'label' => 'Database Type',
                'description' => 'Choose between SQLite (development) and MySQL (production)',
                'is_secret' => false
            ],
            [
                'key' => 'db_host',
                'value' => EnvLoader::get('DB_HOST', 'localhost'),
                'actual_value' => EnvLoader::get('DB_HOST', 'localhost'),
                'type' => 'text',
                'label' => 'MySQL Host',
                'description' => 'MySQL server hostname (only used when Database Type is MySQL)',
                'is_secret' => false
            ],
            [
                'key' => 'db_port',
                'value' => EnvLoader::get('DB_PORT', '3306'),
                'actual_value' => EnvLoader::get('DB_PORT', '3306'),
                'type' => 'number',
                'label' => 'MySQL Port',
                'description' => 'MySQL server port (default: 3306)',
                'is_secret' => false
            ],
            [
                'key' => 'db_name',
                'value' => EnvLoader::get('DB_NAME', 'bdta'),
                'actual_value' => EnvLoader::get('DB_NAME', 'bdta'),
                'type' => 'text',
                'label' => 'MySQL Database Name',
                'description' => 'Name of the MySQL database',
                'is_secret' => false
            ],
            [
                'key' => 'db_user',
                'value' => EnvLoader::get('DB_USER', 'root'),
                'actual_value' => EnvLoader::get('DB_USER', 'root'),
                'type' => 'text',
                'label' => 'MySQL Username',
                'description' => 'MySQL database username',
                'is_secret' => false
            ],
            [
                'key' => 'db_password',
                'value' => '••••••••',
                'actual_value' => EnvLoader::get('DB_PASSWORD', ''),
                'type' => 'password',
                'label' => 'MySQL Password',
                'description' => 'MySQL database password',
                'is_secret' => true
            ],
            [
                'key' => 'sqlite_db_path',
                'value' => EnvLoader::get('SQLITE_DB_PATH', 'bdta.db'),
                'actual_value' => EnvLoader::get('SQLITE_DB_PATH', 'bdta.db'),
                'type' => 'text',
                'label' => 'SQLite Database Path',
                'description' => 'Path to SQLite database file (relative to backend directory)',
                'is_secret' => false
            ]
        ];
    }
    
    /**
     * Get all categories
     *
     * @return list<string>
     */
    public static function getCategories(): array {
        $db = self::getDB();
        $stmt = $db->query("SELECT DISTINCT category FROM settings ORDER BY category");
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Always include database category (it's read from .env)
        if (!in_array('database', $categories)) {
            $categories[] = 'database';
            sort($categories);
        }
        
        /** @var list<string> $categories */
        return $categories;
    }
    
    /**
     * Cast value to appropriate type
     */
    private static function castValue(mixed $value, string $type): mixed {
        switch ($type) {
            case 'checkbox':
                return (bool)$value;
            case 'number':
                return is_numeric($value) ? (float)$value : 0;
            default:
                return $value;
        }
    }
    
    /**
     * Get Stripe configuration based on mode
     *
     * @return array<string, mixed>|null
     */
    public static function getStripeConfig(): ?array {
        $mode = self::get('stripe_mode', 'test');
        $enabled = self::get('stripe_enabled', false);
        
        if (!$enabled) {
            return null;
        }
        
        if ($mode === 'live') {
            return [
                'publishable_key' => self::get('stripe_live_publishable_key'),
                'secret_key' => self::get('stripe_live_secret_key'),
                'currency' => self::get('stripe_currency', 'usd'),
                'mode' => 'live'
            ];
        } else {
            return [
                'publishable_key' => self::get('stripe_test_publishable_key'),
                'secret_key' => self::get('stripe_test_secret_key'),
                'currency' => self::get('stripe_currency', 'usd'),
                'mode' => 'test'
            ];
        }
    }
    
    /**
     * Get theme color settings with defaults
     *
     * @return array<string, mixed>
     */
    public static function getThemeColors(): array {
        return [
            'primary'           => self::get('theme_primary_color', '#9a0073'),
            'primary_dark'      => self::get('theme_primary_dark_color', '#7a005a'),
            'secondary'         => self::get('theme_secondary_color', '#0a9a9c'),
            'accent'            => self::get('theme_accent_color', '#a39f89'),
            'sidebar_bg_start'  => self::get('theme_sidebar_bg_start', '#9a0073'),
            'sidebar_bg_end'    => self::get('theme_sidebar_bg_end', '#7a005a'),
        ];
    }

    /**
     * Get default theme colors
     *
     * @return array<string, string>
     */
    public static function getDefaultThemeColors(): array {
        return [
            'theme_primary_color'      => '#9a0073',
            'theme_primary_dark_color' => '#7a005a',
            'theme_secondary_color'    => '#0a9a9c',
            'theme_accent_color'       => '#a39f89',
            'theme_sidebar_bg_start'   => '#9a0073',
            'theme_sidebar_bg_end'     => '#7a005a',
        ];
    }

    /**
     * Get email configuration
     *
     * @return array<string, mixed>
     */
    public static function getEmailConfig(): array {
        return [
            'from_address' => self::get('email_from_address'),
            'from_name' => self::get('email_from_name'),
            'service' => self::get('email_service', 'mail'),
            'smtp_host' => self::get('smtp_host'),
            'smtp_port' => self::get('smtp_port', 587),
            'smtp_username' => self::get('smtp_username'),
            'smtp_password' => self::get('smtp_password'),
            'sendgrid_api_key' => self::get('sendgrid_api_key'),
            'mailgun_api_key' => self::get('mailgun_api_key'),
            'mailgun_domain' => self::get('mailgun_domain'),
        ];
    }
}
?>
